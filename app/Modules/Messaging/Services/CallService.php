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

    /** the most rows one history page gives, and the most people one totals answer gives */
    public const PAGE_MAX = 100;

    public const PEOPLE_MAX = 200;

    /** what a history page can be narrowed to */
    public const KINDS = ['missed', 'in', 'out'];

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

        $this->now(new SendPushToUser(
            (int) $callee->id,
            (string) $caller->name,
            $this->callData($call, $caller, (string) $thread->public_id),
            (string) __($video ? 'messaging::messenger.call.incoming_video' : 'messaging::messenger.call.incoming_voice'),));

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

        $this->presenter->warm($calls->map(fn (Call $call): User => $this->peerOf($call, $user))->all());

        return $calls->map(fn (Call $call): array => $this->row($call, $user))->values()->all();
    }

    /**
     * One page of my own call history, newest first — the same row as [[log()]].
     *
     * ⓘ `missed` = someone called me and I did not answer (a call I declined, or one I made that nobody took, is not it);
     *   `in` / `out` = every call to me / from me. The cursor is the last row's time and id, so a call that comes in
     *   while someone scrolls never repeats or skips a row.
     *
     * @return array{data: list<array<string, mixed>>, next: ?string}
     */
    public function history(User $user, ?string $kind, ?int $personId, ?string $cursor, int $limit, string $q = ''): array
    {
        $this->sweep();

        $limit = max(1, min(self::PAGE_MAX, $limit));
        $me = (int) $user->id;
        $after = $cursor === null ? null : self::cursor($cursor);

        /** @var Collection<int, Call> $calls */
        $calls = Call::query()
            // ⛔ the wall first, in its own brackets: nothing below can widen it
            ->where(fn ($q) => $q->where('caller_id', $me)->orWhere('callee_id', $me))
            ->when($kind === 'missed', fn ($q) => $q->where('callee_id', $me)->where('status', Call::MISSED))
            ->when($kind === 'in', fn ($q) => $q->where('callee_id', $me))
            ->when($kind === 'out', fn ($q) => $q->where('caller_id', $me))
            ->when($personId !== null, fn ($q) => $q->where(fn ($w) => $w->where('caller_id', $personId)->orWhere('callee_id', $personId)))
            // ⓘ the OTHER side's name — my own name would match every call
            ->when($q !== '', function ($query) use ($me, $q): void {
                $named = User::query()->select('id')->where('name', 'like', '%'.addcslashes($q, '%_\\').'%');
                $query->where(fn ($w) => $w->where(fn ($o) => $o->where('caller_id', $me)->whereIn('callee_id', clone $named))
                    ->orWhere(fn ($i) => $i->where('callee_id', $me)->whereIn('caller_id', clone $named)));
            })
            ->when($after !== null, fn ($q) => $q->where(fn ($w) => $w->where('created_at', '<', $after[0])
                ->orWhere(fn ($s) => $s->where('created_at', $after[0])->where('id', '<', $after[1]))))
            ->with(['caller', 'callee'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $more = $calls->count() > $limit;
        $page = $calls->take($limit)->values();
        $this->presenter->warm($page->map(fn (Call $call): User => $this->peerOf($call, $user))->all());
        $last = $page->last();

        return [
            'data' => $page->map(fn (Call $call): array => $this->row($call, $user))->all(),
            'next' => $more && $last instanceof Call ? $last->created_at->getTimestamp().'.'.$last->id : null,
        ];
    }

    /**
     * My calls added up per colleague, the latest talked-to first.
     *
     * ⓘ answered = picked up by either side; missed = they called, I did not answer; seconds = talking time only.
     *
     * @return list<array<string, mixed>>
     */
    public function people(User $user, ?int $personId = null): array
    {
        $this->sweep();

        $me = (int) $user->id;
        $rows = Call::query()
            ->where(fn ($q) => $q->where('caller_id', $me)->orWhere('callee_id', $me))
            ->when($personId !== null, fn ($q) => $q->where(fn ($w) => $w->where('caller_id', $personId)->orWhere('callee_id', $personId)))
            ->selectRaw('CASE WHEN caller_id = ? THEN callee_id ELSE caller_id END AS peer_id', [$me])
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw('SUM(CASE WHEN answered_at IS NOT NULL THEN 1 ELSE 0 END) AS answered')
            ->selectRaw('SUM(CASE WHEN callee_id = ? AND status = ? THEN 1 ELSE 0 END) AS missed', [$me, Call::MISSED])
            ->selectRaw('COALESCE(SUM(duration_s), 0) AS seconds')
            ->selectRaw('MAX(id) AS last_id')
            ->groupBy('peer_id')
            ->orderByDesc('last_id')
            ->orderBy('peer_id')
            ->limit(self::PEOPLE_MAX)
            ->toBase()
            ->get();

        $users = User::query()->whereIn('id', $rows->pluck('peer_id')->all())->get()->keyBy('id');
        $this->presenter->warm($users->values());
        // ⓘ the latest call with each — one more question for all of them; a call's id grows with its time
        $lasts = Call::query()->whereIn('id', $rows->pluck('last_id')->all())->with(['caller', 'callee'])->get()->keyBy('id');

        return $rows->filter(fn ($row): bool => $users->has($row->peer_id))->map(fn ($row): array => [
            'peer' => $this->presenter->person($users->get($row->peer_id)),
            'calls' => (int) $row->calls,
            'answered' => (int) $row->answered,
            'missed' => (int) $row->missed,
            'seconds' => (int) $row->seconds,
            'last_at' => $lasts->get($row->last_id)?->created_at?->toIso8601String(),
            'last' => $lasts->has($row->last_id) ? $this->row($lasts->get($row->last_id), $user) : null,
        ])->values()->all();
    }

    /** @return array{0: \Illuminate\Support\Carbon, 1: int} — a cursor that is not ours is a 422, never a silent first page */
    private static function cursor(string $cursor): array
    {
        if (preg_match('/^(\d{1,11})\.(\d{1,19})$/', $cursor, $m) !== 1) {
            throw \Illuminate\Validation\ValidationException::withMessages(['cursor' => __('validation.regex', ['attribute' => 'cursor'])]);
        }

        return [\Illuminate\Support\Carbon::createFromTimestamp((int) $m[1], config('app.timezone')), (int) $m[2]];
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
                $this->now(new SendCallEnd($callee, $id, Call::ANSWERED));
                break;

            case Call::DECLINED:
                $this->now(new SendCallEnd($caller, $id, Call::DECLINED));
                $this->now(new SendCallEnd($callee, $id, Call::DECLINED));
                break;

            case Call::MISSED:
                $this->now(new SendCallEnd($callee, $id, Call::MISSED));
                $this->now(new SendCallEnd($caller, $id, Call::MISSED));
                $this->pushMissed($call);
                break;

            case Call::ENDED:
                $other = $actor !== null && (int) $actor->id === $caller ? $callee : $caller;
                $this->now(new SendCallEnd($other, $id, Call::ENDED));
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

        $this->now(new SendPushToUser(
            (int) $call->callee_id,
            (string) __('messaging::messenger.call.missed', ['name' => (string) $caller->name]),
            $this->callData($call, $caller, $thread) + ['status' => Call::MISSED],));
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

    /**
     * ⭐ A call's pushes go out NOW, in this request — never through the queue (owner, 24 Nov 2026: "কল … 1-2 সেকেন্ডের ভিতরে
     * ওখানে পৌঁছার কথা … প্রায় 30 সেকেন্ড লাগে"; "পিসি থেকে মোবাইলে কল আসে না"). ⓘ The queue on this host is worked once a
     * minute by the scheduler, so a ring push waited 30–60 s — longer than the 45 s ring itself, and the phone never rang.
     */
    private function now(object $job): void
    {
        dispatch($job)->onConnection('sync');
    }
}
