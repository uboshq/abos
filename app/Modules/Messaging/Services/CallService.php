<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Jobs\SendPushToUser;
use App\Models\User;
use App\Modules\Messaging\Jobs\SendCallEnd;
use App\Modules\Messaging\Models\Call;
use App\Modules\Messaging\Models\Thread;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 1:1 voice and video calls — the call log and its steps (contract §2, §4, §5). The voice runs on LiveKit; only the
 * row (who, whom, when, how long) is kept here.
 *
 * ⭐ Steps: ringing → answered → ended; ringing → declined | missed. Every step locks the row first, so two taps
 * (two phones of the callee, or answer vs. hang-up) can never both win.
 * ⭐ A ring nobody answers turns `missed` after [[Call::RING_SECONDS]] — the minute sweep, and lazily on every read.
 * ⛔ Only the two parties see or touch a call; anyone else gets 404. Company-scoped through BelongsToCompany.
 */
final class CallService
{
    public const LOG = 50;

    public function __construct(
        private readonly MessengerAccess $access,
        private readonly MessengerService $messenger,
        private readonly MessengerPresenter $presenter,
        private readonly LiveKitToken $livekit,
    ) {}

    // ── steps ────────────────────────────────────────────────────────────

    public function start(User $caller, User $callee, bool $video): Call
    {
        $this->refuseUnlessConfigured();

        // ⓘ the allow-list is a wall on starting, so it is asked on every call, not only for a new thread
        if ((int) $caller->id === (int) $callee->id || ! $this->access->mayTalk($caller, $callee)) {
            throw $this->messenger->refusal('may_not_talk');
        }

        $thread = $this->messenger->directWith($caller, $callee);

        /** @var Call $call */
        $call = Call::query()->create([
            'thread_id' => $thread->id,
            'caller_id' => $caller->id,
            'callee_id' => $callee->id,
            'video' => $video,
            'status' => Call::RINGING,
        ]);

        SendPushToUser::dispatch(
            (int) $callee->id,
            (string) $caller->name,
            $this->callData($call, $caller, (string) $thread->public_id),
            (string) __($video ? 'messaging::messenger.call.incoming_video' : 'messaging::messenger.call.incoming_voice'),
        );

        return $call;
    }

    /** Callee only, only while ringing; a call already over → 409 call_over. */
    public function answer(Call $call, User $user): Call
    {
        $this->refuseUnlessConfigured();
        $this->refuseUnlessCallee($call, $user);

        [$row, $event] = $this->locked($call, function (Call $row): ?string {
            if ($row->status !== Call::RINGING) {
                return null;
            }

            $row->forceFill(['status' => Call::ANSWERED, 'answered_at' => now()])->save();

            return Call::ANSWERED;
        });

        if ($event !== Call::ANSWERED) {
            throw $this->callOver();
        }

        return $row;
    }

    /** Callee only; a call no longer ringing is left as it is (a second tap is harmless). */
    public function decline(Call $call, User $user): Call
    {
        $this->refuseUnlessCallee($call, $user);

        return $this->locked($call, fn (Call $row): ?string => $this->closeRinging($row, Call::DECLINED))[0];
    }

    /** Either side. Ringing → missed (caller hangs up) or declined (callee); answered → ended with its length. */
    public function end(Call $call, User $user): Call
    {
        if (! $this->isParty($call, $user)) {
            throw new HttpException(404);
        }

        return $this->locked($call, function (Call $row) use ($user): ?string {
            if ($row->status === Call::RINGING) {
                return $this->closeRinging($row, (int) $row->caller_id === (int) $user->id ? Call::MISSED : Call::DECLINED);
            }

            if ($row->status === Call::ANSWERED) {
                $answered = $row->answered_at?->getTimestamp() ?? now()->getTimestamp();
                $row->forceFill([
                    'status' => Call::ENDED,
                    'ended_at' => now(),
                    'duration_s' => max(0, now()->getTimestamp() - $answered),
                ])->save();

                return Call::ENDED;
            }

            return null;
        }, $user)[0];
    }

    /** Ringing calls older than the ring time → missed, in the current company. Returns how many. */
    public function sweep(): int
    {
        $ids = Call::query()
            ->where('status', Call::RINGING)
            ->where('created_at', '<=', now()->subSeconds(Call::RING_SECONDS))
            ->orderBy('id')
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            $call = Call::query()->find($id);
            if ($call !== null && $this->locked($call, fn (): ?string => null)[1] === Call::MISSED) {
                $count++;
            }
        }

        return $count;
    }

    // ── reads ────────────────────────────────────────────────────────────

    /** A call by its public id — only for its two parties (404 otherwise: "exists" is information too). */
    public function forUser(User $user, string $publicId): Call
    {
        $call = Call::query()->wherePublicId($publicId)->first();

        if ($call === null || ! $this->isParty($call, $user)) {
            throw new HttpException(404);
        }

        return $call;
    }

    /**
     * The call log, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function log(User $user): array
    {
        $this->sweep();

        /** @var Collection<int, Call> $calls */
        $calls = Call::query()
            ->where(fn ($q) => $q->where('caller_id', $user->id)->orWhere('callee_id', $user->id))
            ->with(['caller', 'callee'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LOG)
            ->get();

        return $calls->map(fn (Call $call): array => $this->row($call, $user))->values()->all();
    }

    /**
     * What a party needs to join the room: `{ call_id, url, token, video, peer, room }`.
     *
     * @return array<string, mixed>
     */
    public function joinPayload(Call $call, User $viewer): array
    {
        $this->refuseUnlessConfigured();

        return [
            'call_id' => (string) $call->public_id,
            'url' => $this->livekit->url(),
            'token' => $this->livekit->for($viewer, $call->room()),
            'video' => (bool) $call->video,
            'peer' => $this->presenter->person($this->peerOf($call, $viewer)),
            'room' => $call->room(),
        ];
    }

    /** @return array<string, mixed> */
    public function row(Call $call, User $viewer): array
    {
        $out = (int) $call->caller_id === (int) $viewer->id;

        return [
            'call_id' => (string) $call->public_id,
            'video' => (bool) $call->video,
            'direction' => $out ? 'out' : 'in',
            'peer' => $this->presenter->person($this->peerOf($call, $viewer)),
            'status' => (string) $call->status,
            'at' => $call->created_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration_s' => $call->duration_s,
        ];
    }

    // ── inner work ───────────────────────────────────────────────────────

    /**
     * Locks the row, settles an overdue ring as missed, then runs one step. Pushes go out after the commit.
     *
     * @param  callable(Call): ?string  $step  returns the new status it set, or null for "nothing changed"
     * @return array{0: Call, 1: ?string}
     */
    private function locked(Call $call, callable $step, ?User $actor = null): array
    {
        [$row, $event] = DB::transaction(function () use ($call, $step): array {
            /** @var Call $row */
            $row = Call::query()->whereKey($call->id)->lockForUpdate()->firstOrFail();

            if ($this->overdue($row)) {
                $this->closeRinging($row, Call::MISSED);

                return [$row, Call::MISSED];
            }

            return [$row, $step($row)];
        });

        if ($event !== null) {
            $this->announce($row, $event, $actor);
        }

        return [$row, $event];
    }

    private function closeRinging(Call $row, string $status): ?string
    {
        if ($row->status !== Call::RINGING) {
            return null;
        }

        $row->forceFill(['status' => $status, 'ended_at' => now()])->save();

        return $status;
    }

    private function overdue(Call $row): bool
    {
        return $row->status === Call::RINGING
            && $row->created_at !== null
            && $row->created_at->lte(now()->subSeconds(Call::RING_SECONDS));
    }

    /** The pushes after a step (contract §5). */
    private function announce(Call $call, string $event, ?User $actor): void
    {
        $id = (string) $call->public_id;
        $caller = (int) $call->caller_id;
        $callee = (int) $call->callee_id;

        switch ($event) {
            case Call::ANSWERED:
                // ⓘ the callee's other phones stop ringing — answered elsewhere
                SendCallEnd::dispatch($callee, $id, Call::ANSWERED);
                break;

            case Call::DECLINED:
                SendCallEnd::dispatch($caller, $id, Call::DECLINED);
                SendCallEnd::dispatch($callee, $id, Call::DECLINED);
                break;

            case Call::MISSED:
                SendCallEnd::dispatch($callee, $id, Call::MISSED);
                SendCallEnd::dispatch($caller, $id, Call::MISSED);
                $this->pushMissed($call);
                break;

            case Call::ENDED:
                $other = $actor !== null && (int) $actor->id === $caller ? $callee : $caller;
                SendCallEnd::dispatch($other, $id, Call::ENDED);
                break;
        }
    }

    private function pushMissed(Call $call): void
    {
        $caller = $call->caller()->first();
        if ($caller === null) {
            return;
        }

        $thread = $call->thread_id !== null ? (string) Thread::query()->whereKey($call->thread_id)->value('public_id') : '';

        SendPushToUser::dispatch(
            (int) $call->callee_id,
            (string) __('messaging::messenger.call.missed', ['name' => (string) $caller->name]),
            $this->callData($call, $caller, $thread) + ['status' => Call::MISSED],
        );
    }

    /** @return array<string, string> */
    private function callData(Call $call, User $caller, string $threadId): array
    {
        return [
            'open' => 'messenger',
            'type' => 'call',
            'call_id' => (string) $call->public_id,
            'video' => $call->video ? '1' : '0',
            'from' => (string) $caller->id,
            'thread_id' => $threadId,
        ];
    }

    private function isParty(Call $call, User $user): bool
    {
        return in_array((int) $user->id, [(int) $call->caller_id, (int) $call->callee_id], true);
    }

    private function peerOf(Call $call, User $viewer): User
    {
        $peer = (int) $call->caller_id === (int) $viewer->id ? $call->callee : $call->caller;

        if (! $peer instanceof User) {
            throw new HttpException(404);
        }

        return $peer;
    }

    private function refuseUnlessCallee(Call $call, User $user): void
    {
        if (! $this->isParty($call, $user)) {
            throw new HttpException(404);
        }

        if ((int) $call->callee_id !== (int) $user->id) {
            throw $this->messenger->refusal('forbidden');
        }
    }

    private function refuseUnlessConfigured(): void
    {
        if (! $this->livekit->configured()) {
            throw new HttpResponseException(response()->json([
                'error' => 'calls_off',
                'message' => __('messaging::messenger.refused.calls_off'),
            ], 503));
        }
    }

    private function callOver(): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'error' => 'call_over',
            'message' => __('messaging::messenger.refused.call_over'),
        ], 409));
    }
}
