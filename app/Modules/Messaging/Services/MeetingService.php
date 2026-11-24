<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Messaging\Models\Meeting;
use App\Modules\Messaging\Models\MeetingEvent;
use App\Modules\Messaging\Models\MeetingPerson;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Meetings and group calls — one engine (decided 24 Nov 2026: lobby on, invited people enter directly; everyone may share,
 * a host can stop it or switch it off; no recording; at most 10).
 *
 * ⭐ Every power is asked here, on the row, never by a hidden button. Each step locks the meeting first, so the eleventh
 *   person and the tenth can never both win.
 * ⭐ Who is inside comes from three sides: the join, the page's own heartbeat (it asks the state every few seconds), and
 *   LiveKit's signed webhook. ⓘ Without the webhook a closed tab leaves after [[SEEN_SECONDS]].
 * ⓘ Off by `messaging.meetings` (default off) until the network is proven. ⛔ Nothing here touches a two-person call.
 */
final class MeetingService
{
    public const SWITCH = 'messaging.meetings';

    /** ⓘ a hidden tab's timers run once a minute — so this is well past two of them */
    public const SEEN_SECONDS = 150;

    public const RING_SECONDS = 45;

    public function __construct(
        private readonly MessengerAccess $access,
        private readonly MessengerService $messenger,
        private readonly MessengerPresenter $presenter,
        private readonly LiveKitToken $livekit,
        private readonly LiveKitRooms $rooms,
        private readonly SettingsService $settings,
    ) {}

    /** Is the switch on, in this company, and is the call server set up? */
    public function isOn(?int $companyId = null): bool
    {
        $read = fn (): bool => (bool) $this->settings->get(self::SWITCH, false);

        return $this->livekit->configured()
            && ($companyId === null || $companyId === CompanyContext::id() ? $read() : (bool) CompanyContext::forCompany($companyId, $read));
    }

    // ── start ────────────────────────────────────────────────────────────

    /**
     * @param  list<int>  $people
     * @param  string  $key  the page's id for this press — the same press again gives the same meeting
     */
    public function start(User $host, string $key, string $title, array $people, bool $video, ?string $threadId): Meeting
    {
        $this->refuseUnlessOn();
        $this->sweep();

        $mine = Meeting::query()->where('host_id', $host->id)->where('client_key', $key)->first();

        if ($mine !== null) {
            return $mine;
        }

        $thread = $threadId === null ? null : $this->messenger->threadFor($host, $threadId);

        if ($thread !== null && ! $thread->isGroup()) {
            throw $this->refused('meeting_group_only');
        }

        // ⓘ a group has one running call: a second press joins it
        $running = $thread === null ? null : Meeting::query()->where('thread_id', $thread->id)->whereNull('ended_at')->latest('id')->first();

        if ($running !== null) {
            return $running;
        }

        $users = $thread === null
            ? $this->colleagues($people)
            : $this->messenger->membersOf($thread)->map(fn ($m) => $m->user)->filter();
        $users = $users->filter(fn (User $u): bool => $this->access->mayTalk($host, $u))->values();

        try {
            $meeting = $this->write($host, $key, $title, $users->all(), $video, $thread);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // ⓘ the same press twice at the same moment
            return Meeting::query()->where('host_id', $host->id)->where('client_key', $key)->firstOrFail();
        }

        $this->rooms->make($meeting->room(), Meeting::MAX_PEOPLE);

        return $meeting;
    }

    /** @param  list<User>  $users */
    private function write(User $host, string $key, string $title, array $users, bool $video, ?\App\Modules\Messaging\Models\Thread $thread): Meeting
    {
        return DB::transaction(function () use ($host, $key, $title, $users, $video, $thread): Meeting {
            $meeting = Meeting::query()->create([
                'thread_id' => $thread?->id,
                'host_id' => $host->id,
                'client_key' => $key,
                'title' => mb_substr($title !== '' ? $title : (string) ($thread?->title ?: __('messaging::meeting.default_title', ['name' => $host->name])), 0, 120),
                'video' => $video,
                'empty_since' => now(),
            ]);

            $meeting->people()->create(['company_id' => $meeting->company_id, 'user_id' => $host->id, 'role' => MeetingPerson::HOST, 'state' => MeetingPerson::INVITED]);

            foreach ($users as $user) {
                $this->invited($meeting, $user);
            }

            $this->note($meeting, 'started', $host);

            return $meeting;
        });
    }

    // ── doors of one person ──────────────────────────────────────────────

    /** A meeting this person may know of — invited, or any active colleague with the link; anyone else 404. */
    public function forUser(User $user, string $publicId): Meeting
    {
        $meeting = Meeting::query()->wherePublicId($publicId)->first();

        if ($meeting === null || ! $user->is_active || $this->access->companiesOf($user) === []) {
            throw new HttpException(404);
        }

        return $meeting;
    }

    /**
     * Come in: `{ state: in, url, token, … }`, or `{ state: waiting }` in the lobby.
     *
     * @return array<string, mixed>
     */
    public function join(Meeting $meeting, User $user): array
    {
        $this->refuseUnlessOn((int) $meeting->company_id);
        $this->sweepOne($meeting);

        $person = DB::transaction(function () use ($meeting, $user): MeetingPerson {
            $row = $this->locked($meeting);
            $person = $this->personOf($row, $user);

            if ($person?->state === MeetingPerson::REMOVED) {
                throw $this->refused('meeting_removed');
            }

            if ($person?->state === MeetingPerson::IN) {
                $person->forceFill(['seen_at' => now()])->save();

                return $person;
            }

            $runs = $person?->runsIt() ?? false;

            if ($row->locked && ! $runs) {
                throw $this->refused('meeting_locked');
            }

            // ⭐ the link alone gives only the lobby; invited (or once inside) enters directly
            if ($row->lobby && ($person === null || $person->state === MeetingPerson::WAITING)) {
                $person ??= $row->people()->make(['company_id' => $row->company_id, 'user_id' => $user->id]);
                $person->forceFill(['state' => MeetingPerson::WAITING, 'seen_at' => now()])->save();

                return $person;
            }

            if ($this->inside($row)->count() >= Meeting::MAX_PEOPLE) {
                throw $this->refused('meeting_full', 409, ['max' => Meeting::MAX_PEOPLE]);
            }

            $person ??= $row->people()->make(['company_id' => $row->company_id, 'user_id' => $user->id]);
            $person->forceFill(['state' => MeetingPerson::IN, 'joined_at' => now(), 'left_at' => null, 'seen_at' => now(), 'session' => null])->save();

            // ⓘ the others ring when the first person is really inside — not while the host is still at the door
            if ($row->started_at === null) {
                MeetingPerson::query()->where('meeting_id', $row->id)->where('state', MeetingPerson::INVITED)->toBase()->update(['invited_at' => now()]);
            }

            $row->forceFill(['started_at' => $row->started_at ?? now(), 'empty_since' => null])->save();

            return $person;
        });

        return $this->state($meeting->refresh(), $user) + ($person->state === MeetingPerson::IN ? $this->ticket($meeting, $person, $user) : []);
    }

    /**
     * A new short token — only while inside.
     *
     * @return array<string, mixed>
     */
    public function renew(Meeting $meeting, User $user): array
    {
        $person = $this->insider($meeting, $user);

        return $this->ticket($meeting, $person, $user);
    }

    public function leave(Meeting $meeting, User $user): void
    {
        DB::transaction(function () use ($meeting, $user): void {
            $row = $this->locked($meeting, over: true);
            $person = $this->personOf($row, $user);

            if ($person !== null && in_array($person->state, [MeetingPerson::IN, MeetingPerson::WAITING], true)) {
                $this->goneOut($row, $person, MeetingPerson::LEFT);
            }
        });
    }

    public function decline(Meeting $meeting, User $user): void
    {
        MeetingPerson::query()->where('meeting_id', $meeting->id)->where('user_id', $user->id)
            ->where('state', MeetingPerson::INVITED)->first()?->forceFill(['state' => MeetingPerson::DECLINED])->save();
    }

    /**
     * What the page draws — and the heartbeat of whoever asks.
     *
     * @return array<string, mixed>
     */
    public function state(Meeting $meeting, User $user): array
    {
        $me = $this->personOf($meeting, $user);

        if ($me !== null && in_array($me->state, [MeetingPerson::IN, MeetingPerson::WAITING], true)
            && ($me->seen_at === null || $me->seen_at->lt(now()->subSeconds(10)))) {
            MeetingPerson::query()->whereKey($me->id)->toBase()->update(['seen_at' => now()]);
        }

        $this->sweepOne($meeting);
        $meeting->refresh();
        $insider = $me !== null && $me->state === MeetingPerson::IN;
        $runs = $insider && $me->runsIt();
        $rows = ! $insider ? collect() : MeetingPerson::query()->where('meeting_id', $meeting->id)
            ->whereIn('state', $runs ? [MeetingPerson::IN, MeetingPerson::WAITING, MeetingPerson::INVITED] : [MeetingPerson::IN])
            ->with('user')->orderBy('joined_at')->orderBy('id')->get()->filter(fn (MeetingPerson $p): bool => $p->user !== null);
        $this->presenter->warm($rows->map(fn (MeetingPerson $p): User => $p->user)->all());

        return [
            'id' => (string) $meeting->public_id,
            'title' => (string) $meeting->title,
            'video' => (bool) $meeting->video,
            'over' => $meeting->isOver(),
            'state' => $me?->state,
            'role' => $me?->role,
            'runs' => $runs,
            'locked' => (bool) $meeting->locked,
            'lobby' => (bool) $meeting->lobby,
            'allow_share' => (string) $meeting->allow_share,
            'may_share' => $insider && $this->mayShare($meeting, $me),
            'started_at' => $meeting->started_at?->toIso8601String(),
            'max' => Meeting::MAX_PEOPLE,
            // ⛔ the thread only to its members — a colleague let in from the lobby does not get the group's chat
            'thread' => $insider && $meeting->thread !== null && $this->access->isMember($meeting->thread, $user) ? (string) $meeting->thread->public_id : null,
            'people' => $rows->map(fn (MeetingPerson $p): array => ['role' => $p->role, 'state' => $p->state] + $this->presenter->person($p->user))->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> the open meetings I am in or called to */
    public function mine(User $user): array
    {
        return Meeting::query()->whereNull('ended_at')
            ->whereIn('id', MeetingPerson::query()->select('meeting_id')->where('user_id', $user->id)
                ->whereIn('state', [MeetingPerson::INVITED, MeetingPerson::WAITING, MeetingPerson::IN, MeetingPerson::LEFT]))
            ->latest('id')->limit(20)->get()
            ->map(fn (Meeting $m): array => ['id' => (string) $m->public_id, 'title' => (string) $m->title, 'started_at' => $m->started_at?->toIso8601String()])
            ->values()->all();
    }

    /** @return array<string, mixed>|null  who is calling me to a meeting right now */
    public function ringing(User $user): ?array
    {
        $person = MeetingPerson::query()->where('user_id', $user->id)->where('state', MeetingPerson::INVITED)
            ->where('invited_at', '>=', now()->subSeconds(self::RING_SECONDS))
            ->whereHas('meeting', fn ($q) => $q->whereNull('ended_at')->whereNotNull('started_at')->whereNull('empty_since'))
            ->with('meeting.host')->latest('id')->first();

        if ($person === null || $person->meeting->host === null || ! $this->isOn((int) $person->meeting->company_id)) {
            return null;
        }

        return ['id' => (string) $person->meeting->public_id, 'title' => (string) $person->meeting->title,
            'video' => (bool) $person->meeting->video, 'peer' => $this->presenter->person($person->meeting->host)];
    }

    // ── host powers ──────────────────────────────────────────────────────

    /** @param  list<int>  $people */
    public function invite(Meeting $meeting, User $by, array $people): void
    {
        $this->asHost($meeting, $by, function (Meeting $row) use ($by, $people): void {
            foreach ($this->colleagues($people) as $user) {
                // ⓘ calling someone back also lifts a removal — the host's own decision
                if ($this->access->mayTalk($by, $user)) {
                    $this->invited($row, $user);
                    $this->note($row, 'invited', $by, (int) $user->id);
                }
            }
        });
    }

    /** Let someone in from the lobby (`yes`) or not. */
    public function answerLobby(Meeting $meeting, User $by, int $userId, bool $yes): void
    {
        $this->asHost($meeting, $by, function (Meeting $row) use ($by, $userId, $yes): void {
            $person = $this->target($row, $userId);

            if ($person->state !== MeetingPerson::WAITING) {
                return;
            }

            $person->forceFill(['state' => $yes ? MeetingPerson::INVITED : MeetingPerson::DECLINED])->save();
            $this->note($row, $yes ? 'admitted' : 'refused', $by, $userId);
        });
    }

    public function remove(Meeting $meeting, User $by, int $userId): void
    {
        $this->asHost($meeting, $by, function (Meeting $row, MeetingPerson $me) use ($by, $userId): void {
            $person = $this->target($row, $userId);

            if ($person->role === MeetingPerson::HOST || (int) $person->user_id === (int) $me->user_id) {
                throw $this->refused('meeting_not_host');
            }

            $this->goneOut($row, $person, MeetingPerson::REMOVED);
            $person->forceFill(['role' => MeetingPerson::MEMBER])->save();
            $this->note($row, 'removed', $by, $userId);
        });

        // ⛔ the row first: no new token whatever the call server answers; then out of the room
        $this->done($this->rooms->putOut($meeting->room(), $userId));
    }

    public function role(Meeting $meeting, User $by, int $userId, string $role): void
    {
        $this->asHost($meeting, $by, function (Meeting $row, MeetingPerson $me) use ($by, $userId, $role): void {
            $person = $this->target($row, $userId);

            if ($me->role !== MeetingPerson::HOST || $person->role === MeetingPerson::HOST) {
                throw $this->refused('meeting_not_host');
            }

            $person->forceFill(['role' => $role === MeetingPerson::COHOST ? MeetingPerson::COHOST : MeetingPerson::MEMBER])->save();
            $this->note($row, $person->role, $by, $userId);
        });

        $this->sendRights($meeting->refresh(), $userId);
    }

    /** @param  array{locked?: bool, lobby?: bool, allow_share?: string}  $to */
    public function set(Meeting $meeting, User $by, array $to): void
    {
        $this->asHost($meeting, $by, function (Meeting $row) use ($by, $to): void {
            $row->forceFill(array_intersect_key($to, ['locked' => 1, 'lobby' => 1, 'allow_share' => 1]))->save();

            foreach ($to as $what => $value) {
                $this->note($row, $what.':'.(is_bool($value) ? ($value ? 'on' : 'off') : $value), $by);
            }
        });

        if (isset($to['allow_share'])) {
            $meeting->refresh();
            $done = true;

            foreach ($this->inside($meeting)->get() as $person) {
                $done = $this->rooms->maySend($meeting->room(), (int) $person->user_id, $this->sources($meeting, $person)) && $done;
            }

            $this->done($done);
        }
    }

    /** One person, or everyone but the one who asks. */
    public function mute(Meeting $meeting, User $by, ?int $userId): void
    {
        $this->asHost($meeting, $by, function (Meeting $row) use ($by, $userId): void {
            if ($userId !== null) {
                $this->target($row, $userId);
            }

            $this->note($row, $userId === null ? 'muted_all' : 'muted', $by, $userId);
        });

        $this->done($this->rooms->mute($meeting->room(), $userId, $userId === null ? (int) $by->id : null));
    }

    /** Stop someone's share: take the right away (LiveKit drops the share), then give back what the meeting allows. */
    public function stopShare(Meeting $meeting, User $by, int $userId): void
    {
        $person = $this->asHost($meeting, $by, function (Meeting $row) use ($by, $userId): MeetingPerson {
            $person = $this->target($row, $userId);
            $this->note($row, 'share_stopped', $by, $userId);

            return $person;
        });

        $room = $meeting->room();
        $done = $this->rooms->maySend($room, $userId, ['microphone', 'camera']);
        $this->done($this->rooms->maySend($room, $userId, $this->sources($meeting->refresh(), $person)) && $done);
    }

    public function end(Meeting $meeting, User $by): void
    {
        $this->asHost($meeting, $by, function (Meeting $row) use ($by): void {
            $this->close($row);
            $this->note($row, 'ended', $by);
        });

        $this->rooms->close($meeting->room());
    }

    // ── LiveKit's own word ───────────────────────────────────────────────

    /** @param  array<string, mixed>  $event  already proven ours ([[LiveKitToken::webhookIsOurs()]]) */
    public function heard(array $event): void
    {
        $room = (string) ($event['room']['name'] ?? '');

        if (! str_starts_with($room, 'meet-') || ! Str::isUuid(substr($room, 5))) {
            return;
        }

        $meeting = Meeting::query()->withoutGlobalScopes()->where('public_id', substr($room, 5))->first();

        if ($meeting === null) {
            return;
        }

        CompanyContext::forCompany((int) $meeting->company_id, fn () => $this->take($meeting, (string) ($event['event'] ?? ''), (array) ($event['participant'] ?? [])));
    }

    /** @param  array<string, mixed>  $who */
    private function take(Meeting $meeting, string $what, array $who): void
    {
        $userId = (int) ($who['identity'] ?? 0);
        $session = mb_substr((string) ($who['sid'] ?? ''), 0, 64);

        $putOut = DB::transaction(function () use ($meeting, $what, $userId, $session): bool {
            $row = $this->locked($meeting, over: true);

            if ($what === 'room_finished') {
                if (! $row->isOver() && $this->inside($row)->doesntExist()) {
                    $this->close($row);
                }

                return false;
            }

            $person = $userId > 0 ? MeetingPerson::query()->where('meeting_id', $row->id)->where('user_id', $userId)->first() : null;

            if ($what === 'participant_joined') {
                // ⓘ the line dropped and LiveKit brought the same person back by itself — not someone new
                if ($person?->state === MeetingPerson::LEFT && ! $row->isOver() && ! $row->locked
                    && $person->left_at?->gte(now()->subSeconds(LiveKitToken::MEETING_TTL)) && $this->inside($row)->count() < Meeting::MAX_PEOPLE) {
                    $person->forceFill(['state' => MeetingPerson::IN, 'joined_at' => now(), 'left_at' => null])->save();
                    $row->forceFill(['empty_since' => null])->save();
                }

                // ⛔ someone our book does not have inside (removed, or the meeting is over) came with an old token → out
                if ($person === null || $person->state !== MeetingPerson::IN || $row->isOver()) {
                    return true;
                }

                $person->forceFill(['session' => $session, 'seen_at' => now()])->save();
            }

            // ⓘ only the session we know: a second tab's old session must not put the person out
            if ($what === 'participant_left' && $person?->state === MeetingPerson::IN && $person->session === $session && $session !== '') {
                $this->goneOut($row, $person, MeetingPerson::LEFT);
            }

            return false;
        });

        if ($putOut && $userId > 0) {
            $this->rooms->putOut($meeting->room(), $userId);
        }
    }

    // ── inner work ───────────────────────────────────────────────────────

    /** Over meetings: nobody inside for five minutes. */
    private function sweep(): void
    {
        foreach (Meeting::query()->whereNull('ended_at')->where('created_at', '<', now()->subSeconds(Meeting::EMPTY_SECONDS))->limit(20)->get() as $meeting) {
            $this->sweepOne($meeting);
        }
    }

    private function sweepOne(Meeting $meeting): void
    {
        $stale = now()->subSeconds(self::SEEN_SECONDS);
        $late = fn () => MeetingPerson::query()->where('meeting_id', $meeting->id)
            ->whereIn('state', [MeetingPerson::IN, MeetingPerson::WAITING])->where('seen_at', '<', $stale);

        if ($meeting->isOver() || (! $late()->exists() && ($meeting->empty_since === null || $meeting->empty_since->gt(now()->subSeconds(Meeting::EMPTY_SECONDS))))) {
            return;
        }

        DB::transaction(function () use ($meeting, $late): void {
            $row = $this->locked($meeting, over: true);

            if ($row->isOver()) {
                return;
            }

            foreach ($late()->get() as $person) {
                $this->goneOut($row, $person, MeetingPerson::LEFT);
            }

            if ($row->empty_since !== null && $row->empty_since->lte(now()->subSeconds(Meeting::EMPTY_SECONDS))) {
                $this->close($row);
            }
        });
    }

    private function goneOut(Meeting $row, MeetingPerson $person, string $state): void
    {
        $wasIn = $person->state === MeetingPerson::IN;
        $seconds = $wasIn && $person->joined_at !== null ? max(0, now()->getTimestamp() - $person->joined_at->getTimestamp()) : 0;
        $person->forceFill(['state' => $state, 'left_at' => now(), 'session' => null, 'seconds' => (int) $person->seconds + $seconds])->save();

        if (! $wasIn) {
            return;
        }

        // ⭐ the host went: the oldest co-host, else the oldest member, takes over; nobody left → the five minutes start
        $next = $this->inside($row)->orderByRaw("CASE WHEN role = 'cohost' THEN 0 ELSE 1 END")->orderBy('joined_at')->orderBy('id')->first();

        if ($next === null) {
            $row->forceFill(['empty_since' => now()])->save();
        } elseif ($person->role === MeetingPerson::HOST) {
            $person->forceFill(['role' => MeetingPerson::COHOST])->save();
            $next->forceFill(['role' => MeetingPerson::HOST])->save();
            $row->forceFill(['host_id' => $next->user_id])->save();
            $this->note($row, 'host', null, (int) $next->user_id);
        }
    }

    private function close(Meeting $row): void
    {
        foreach ($this->inside($row)->get() as $person) {
            $seconds = $person->joined_at === null ? 0 : max(0, now()->getTimestamp() - $person->joined_at->getTimestamp());
            $person->forceFill(['state' => MeetingPerson::LEFT, 'left_at' => now(), 'session' => null, 'seconds' => (int) $person->seconds + $seconds])->save();
        }

        $row->forceFill(['ended_at' => now(), 'empty_since' => $row->empty_since ?? now()])->save();
    }

    /**
     * @param  list<int>  $ids
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function colleagues(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()->whereHas('companies', fn ($c) => $c->where('company_user.is_active', true))
            ->whereIn('id', array_slice(array_unique($ids), 0, 50))->get();
    }

    private function invited(Meeting $row, User $user): void
    {
        $person = $this->personOf($row, $user) ?? $row->people()->make(['company_id' => $row->company_id, 'user_id' => $user->id]);

        if ($person->state !== MeetingPerson::IN) {
            $person->forceFill(['state' => MeetingPerson::INVITED, 'invited_at' => now()])->save();
        }
    }

    /**
     * @template T
     *
     * @param  callable(Meeting, MeetingPerson): T  $step
     * @return T
     */
    private function asHost(Meeting $meeting, User $by, callable $step): mixed
    {
        $this->refuseUnlessOn((int) $meeting->company_id);

        return DB::transaction(function () use ($meeting, $by, $step): mixed {
            $row = $this->locked($meeting);
            $me = $this->personOf($row, $by);

            if ($me === null || $me->state !== MeetingPerson::IN || ! $me->runsIt()) {
                throw $this->refused('meeting_not_host');
            }

            return $step($row, $me);
        });
    }

    private function locked(Meeting $meeting, bool $over = false): Meeting
    {
        /** @var Meeting $row */
        $row = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();

        if (! $over && $row->isOver()) {
            throw $this->refused('meeting_over', 409);
        }

        return $row;
    }

    private function personOf(Meeting $meeting, User $user): ?MeetingPerson
    {
        return MeetingPerson::query()->where('meeting_id', $meeting->id)->where('user_id', $user->id)->first();
    }

    private function target(Meeting $row, int $userId): MeetingPerson
    {
        return MeetingPerson::query()->where('meeting_id', $row->id)->where('user_id', $userId)->first() ?? throw new HttpException(404);
    }

    private function insider(Meeting $meeting, User $user): MeetingPerson
    {
        $this->refuseUnlessOn((int) $meeting->company_id);
        $person = $this->personOf($meeting, $user);

        if ($meeting->isOver() || $person === null || $person->state !== MeetingPerson::IN) {
            throw $this->refused('meeting_not_in');
        }

        return $person;
    }

    private function inside(Meeting $meeting): \Illuminate\Database\Eloquent\Builder
    {
        return MeetingPerson::query()->where('meeting_id', $meeting->id)->where('state', MeetingPerson::IN);
    }

    private function mayShare(Meeting $meeting, MeetingPerson $person): bool
    {
        return $meeting->allow_share === Meeting::EVERYONE || $person->runsIt();
    }

    /** @return list<string> */
    private function sources(Meeting $meeting, MeetingPerson $person): array
    {
        return ['microphone', 'camera', ...($this->mayShare($meeting, $person) ? ['screen_share', 'screen_share_audio'] : [])];
    }

    private function sendRights(Meeting $meeting, int $userId): void
    {
        $person = $this->target($meeting, $userId);

        if ($person->state === MeetingPerson::IN && $meeting->allow_share === Meeting::HOSTS) {
            $this->done($this->rooms->maySend($meeting->room(), $userId, $this->sources($meeting, $person)));
        }
    }

    /** @return array<string, mixed> */
    private function ticket(Meeting $meeting, MeetingPerson $person, User $user): array
    {
        return [
            'url' => $this->livekit->url(),
            'token' => $this->livekit->forMeeting($user, $meeting->room(), $this->sources($meeting, $person)),
            'renew_in' => intdiv(LiveKitToken::MEETING_TTL, 2),
        ];
    }

    private function note(Meeting $meeting, string $what, ?User $by = null, ?int $personId = null): void
    {
        MeetingEvent::query()->create(['company_id' => $meeting->company_id, 'meeting_id' => $meeting->id,
            'actor_id' => $by?->id, 'person_id' => $personId, 'what' => mb_substr($what, 0, 24)]);
    }

    private function refuseUnlessOn(?int $companyId = null): void
    {
        if (! $this->isOn($companyId)) {
            throw $this->refused('meetings_off', 503);
        }
    }

    private function done(bool $done): void
    {
        if (! $done) {
            throw $this->refused('meeting_server', 502);
        }
    }

    /** @param  array<string, mixed>  $swap */
    private function refused(string $code, int $status = 403, array $swap = []): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'error' => $code,
            'message' => __('messaging::meeting.refused.'.$code, $swap),
        ], $status));
    }
}
