<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\RoleLabel;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Messaging\Models\Member;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\Thread;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The JSON shapes of the contract (docs/Contract — বার্তা ও কল API, §1) — the app's `fromJson` names, one place.
 * ⓘ The web page reads the same shapes, so the phone and the web never disagree about a tick or a card.
 */
final class MessengerPresenter
{
    /** @var array<int, string|null> */
    private array $branches = [];

    /** @var array<int, bool> who is online, read together by {@see warm()} */
    private array $online = [];

    /** @var array<int, object>|null */
    private ?array $hrDesignations = null;

    public function __construct(
        private readonly Presence $presence,
        private readonly PaperCards $papers,
    ) {}

    /** @return array<string, mixed> */
    public function person(User $user, ?bool $canOpenPaper = null, ?bool $online = null): array
    {
        $out = [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'designation' => $this->designationOf($user),
            'branch' => $this->branchOf($user),
            'photo_url' => $this->photoOf($user),
            'colour' => null,
            'online' => $online ?? $this->online[(int) $user->id] ?? $this->presence->online((int) $user->id),
        ];

        if ($canOpenPaper !== null) {
            $out['can_open_paper'] = $canOpenPaper;
        }

        return $out;
    }

    /**
     * @param  Collection<int, Member>|null  $members  the thread's members, users loaded (saves a query per row)
     * @return array<string, mixed>
     */
    public function thread(Thread $thread, User $viewer, ?Collection $members = null): array
    {
        $members ??= $thread->members()->with('user')->get();
        $me = $members->firstWhere('user_id', $viewer->id);
        $others = $members->where('user_id', '!=', $viewer->id);
        $online = $this->presence->onlineMany($others->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        $peer = ! $thread->isGroup() ? $others->first()?->user : null;

        $unread = $me === null ? 0 : Message::query()
            ->where('thread_id', $thread->id)
            ->where('id', '>', $me->read_id)
            // ⓘ the same rule as [[MessengerService::unreadTotal()]] — no system lines, no tombstones, nothing hidden for me
            ->whereNotNull('sender_id')->where('sender_id', '!=', $viewer->id)
            ->whereNull('deleted_at')
            ->whereNotIn('id', \App\Modules\Messaging\Models\HiddenMessage::query()->select('message_id')->where('user_id', $viewer->id))
            ->count();

        $last = $thread->last_message_id ? ($thread->relationLoaded('lastMessage') ? $thread->lastMessage : $thread->lastMessage()->first()) : null;

        return [
            'id' => (string) $thread->public_id,
            'kind' => $thread->kind,
            'title' => $thread->isGroup() ? (string) $thread->title : (string) ($peer?->name ?? ''),
            'subtitle' => $thread->isGroup()
                ? __('messaging::messenger.group_subtitle', ['count' => $members->count()])
                : implode(' · ', array_filter([$peer ? $this->designationOf($peer) : null, $peer ? $this->branchOf($peer) : null])),
            'person' => $peer ? $this->person($peer, online: $online[(int) $peer->id] ?? false) : null,
            'group_icon' => $thread->isGroup() ? 'users' : '',
            'colour' => null,
            'last' => $last ? $this->message($last, $viewer, $members) : null,
            'last_at' => $thread->last_at?->toIso8601String(),
            'unread' => $unread,
            'muted' => (bool) ($me?->muted ?? false),
            'member_count' => $members->count(),
            'online_count' => count(array_filter($online)),
        ];
    }

    /**
     * @param  Collection<int, Member>  $members  the thread's members — for the ticks of my own messages
     * @return array<string, mixed>
     */
    public function message(Message $message, User $viewer, Collection $members, ?Thread $thread = null): array
    {
        $mine = $message->sender_id !== null && (int) $message->sender_id === (int) $viewer->id;
        $deleted = $message->isDeleted();
        $file = ! $deleted && in_array($message->kind, Message::FILE_KINDS, true)
            ? ($message->relationLoaded('file') ? $message->file : $message->file()->first())
            : null;

        [$state, $readCount] = $mine ? $this->stateOf($message, $members) : ['sent', 0];

        $sender = null;
        if (! $mine && $message->sender_id !== null) {
            $user = $members->firstWhere('user_id', $message->sender_id)?->user
                ?? ($message->relationLoaded('sender') ? $message->sender : $message->sender()->first());
            $sender = $user ? $this->person($user, online: false) : null;
        }

        $reply = null;
        if ($message->reply_to_id && ! $deleted) {
            $r = Message::query()->where('thread_id', $message->thread_id)->with('sender')->find($message->reply_to_id);
            if ($r) {
                $replySender = $members->firstWhere('user_id', $r->sender_id)?->user ?? $r->sender;
                $reply = [
                    'id' => (string) $r->id,
                    'kind' => $r->isDeleted() ? 'deleted' : $r->kind,
                    // ⓘ a short preview; a quoted paper shows its number only, a deleted one nothing
                    'text' => $r->isDeleted() ? '' : mb_substr($r->kind === 'paper' ? (string) $r->paper_no : (string) $r->text, 0, 80),
                    'sender' => $replySender ? $this->person($replySender, online: false) : null,
                    'mine' => $r->sender_id !== null && (int) $r->sender_id === (int) $viewer->id,
                ];
            }
        }

        return [
            'id' => (string) $message->id,
            'thread_id' => (string) ($thread?->public_id ?? $message->thread?->public_id ?? ''),
            'client_id' => $message->client_id,
            // ⭐ "delete for everyone" — a tombstone: no text, no file, no paper (contract, sample v7)
            'kind' => $deleted ? 'deleted' : $message->kind,
            'at' => $message->created_at?->toIso8601String(),
            'mine' => $mine,
            'sender' => $sender,
            'text' => $deleted ? '' : ($message->kind === 'system' ? $this->systemLine($message) : (string) ($message->text ?? '')),
            'state' => $state,
            'read_count' => $readCount,
            'forwarded' => (bool) $message->forwarded,
            'attachment' => $file ? [
                'url' => route('api.messaging.file', ['file' => $file->id]),
                'thumb_url' => $file->thumb_path ? route('api.messaging.file', ['file' => $file->id, 'thumb' => 1]) : null,
                'web_url' => route('messaging.file', ['file' => $file->id]),
                'name' => $file->name,
                'mime' => $file->mime,
                'size' => $file->size,
                'width' => $file->width,
                'height' => $file->height,
                'duration_ms' => (int) ($file->duration_ms ?? 0),
                'waveform' => $file->waveform ?? [],
            ] : null,
            'paper' => $message->kind === 'paper' && ! $deleted ? $this->papers->card($message, $viewer) : null,
            'lat' => $message->lat !== null && ! $deleted ? (float) $message->lat : null,
            'lng' => $message->lng !== null && ! $deleted ? (float) $message->lng : null,
            'reply_to' => $reply,
        ];
    }

    /**
     * sent → delivered (every other member fetched it) → read (every other member read it).
     *
     * @param  Collection<int, Member>  $members
     * @return array{0: string, 1: int}
     */
    public function stateOf(Message $message, Collection $members): array
    {
        $others = $members->where('user_id', '!=', $message->sender_id);

        if ($others->isEmpty()) {
            return ['sent', 0];
        }

        $read = $others->filter(fn (Member $m): bool => $m->read_id >= $message->id)->count();
        $delivered = $others->filter(fn (Member $m): bool => max($m->delivered_id, $m->read_id) >= $message->id)->count();

        $state = $read === $others->count() ? 'read' : ($delivered === $others->count() ? 'delivered' : 'sent');

        return [$state, $read];
    }

    /**
     * The poll's `meta.states` — tick changes of my own messages and "deleted" for any message, in the last 100.
     *
     * @param  Collection<int, Member>  $members
     * @return list<array{id: string, state: string, read_count?: int}>
     */
    public function states(Thread $thread, User $viewer, Collection $members): array
    {
        $rows = Message::query()->where('thread_id', $thread->id)
            ->where(fn ($q) => $q->where('sender_id', $viewer->id)->orWhereNotNull('deleted_at'))
            ->orderByDesc('id')->limit(100)->get(['id', 'sender_id', 'deleted_at', 'thread_id']);

        $out = [];
        foreach ($rows as $m) {
            if ($m->isDeleted()) {
                $out[] = ['id' => (string) $m->id, 'state' => 'deleted'];

                continue;
            }
            [$state, $count] = $this->stateOf($m, $members);
            $out[] = ['id' => (string) $m->id, 'state' => $state, 'read_count' => $count];
        }

        return $out;
    }

    /** "নাসির ফারজানাকে গ্রুপে যোগ করেছেন" */
    private function systemLine(Message $message): string
    {
        $meta = $message->meta ?? [];
        $event = (string) ($meta['event'] ?? '');

        return match ($event) {
            'members_added' => __('messaging::messenger.system.members_added', [
                'actor' => (string) ($meta['actor'] ?? ''),
                'names' => implode(', ', array_map('strval', (array) ($meta['names'] ?? []))),
            ]),
            'group_created' => __('messaging::messenger.system.group_created', [
                'actor' => (string) ($meta['actor'] ?? ''),
                'title' => (string) ($meta['title'] ?? ''),
            ]),
            default => (string) ($message->text ?? ''),
        };
    }

    private function designationOf(User $user): string
    {
        /*
         * ⭐ Owner, 24 Nov 2026: an HR employee shows the HR designation; someone who is not an employee (a director, Starline's
         * DSM or area manager) shows the title the owner gave the account (bn or en, by the reader's language); else the role.
         */
        $hr = $this->hrDesignations()[(int) $user->id] ?? null;
        $bn = app()->getLocale() === 'bn';

        if ($hr !== null && filled($bn ? ($hr->name_bn ?: $hr->name_en) : ($hr->name_en ?: $hr->name_bn))) {
            return (string) ($bn ? ($hr->name_bn ?: $hr->name_en) : ($hr->name_en ?: $hr->name_bn));
        }

        $own = $bn ? ($user->designation_bn ?: $user->designation) : ($user->designation ?: $user->designation_bn);

        if (filled($own)) {
            return (string) $own;
        }

        try {
            $role = $user->getRoleNames()->first();
        } catch (\Throwable) {
            $role = null;
        }

        return is_string($role) ? RoleLabel::for($role) : '';
    }

    /**
     * The working HR employees' designations, by user — one query for the whole list, never one per person.
     * ⓘ Someone who has left (inactive) is not an employee any more: the owner-given title shows again.
     *
     * @return array<int, object>
     */
    private function hrDesignations(): array
    {
        return $this->hrDesignations ??= DB::table('hr_employees')
            ->join('mdm_designations', 'mdm_designations.id', '=', 'hr_employees.designation_id')
            // ⓘ the messenger spans the group: the companies that are working now, named, never "whatever is in the table"
            ->whereIn('hr_employees.company_id', \App\Models\Company::query()->where('is_active', true)->select('id'))
            ->whereNull('hr_employees.deleted_at')->where('hr_employees.is_active', true)->whereNotNull('hr_employees.user_id')
            ->get(['hr_employees.user_id', 'mdm_designations.name_en', 'mdm_designations.name_bn'])
            ->keyBy('user_id')->all();
    }

    /**
     * Fills what {@see person()} needs for a whole page of people — three queries and the roles, never some per person.
     * ⓘ The same rules as [[branchOf()]]; someone already known is left as is.
     *
     * @param  iterable<User>  $users
     */
    public function warm(iterable $users): void
    {
        $users = \Illuminate\Database\Eloquent\Collection::make($users);

        // ⓘ every object, not one per person: someone who both called and was called is two objects on a page
        $users->loadMissing('roles');
        $this->online = $this->presence->onlineMany($users->pluck('id')->unique()->map(fn ($id): int => (int) $id)->values()->all()) + $this->online;

        $users = $users->unique('id')->values();
        $ids = $users->pluck('id')->map(fn ($id): int => (int) $id)->reject(fn (int $id): bool => array_key_exists($id, $this->branches))->values()->all();

        if ($ids === []) {
            return;
        }

        $company = (int) CompanyContext::id();
        $rows = DB::table('company_user')->whereIn('user_id', $ids)->orderBy('company_id')
            ->get(['company_id', 'user_id', 'default_branch_id', 'is_active'])->groupBy('user_id');
        $branches = Branch::query()->withoutGlobalScopes()->whereIn('id', $rows->flatten(1)->pluck('default_branch_id')->filter()->unique()->all())->get()->keyBy('id');
        $companies = \App\Models\Company::query()->whereIn('id', $rows->flatten(1)->pluck('company_id')->unique()->all())->get()->keyBy('id');

        foreach ($ids as $id) {
            $mine = $rows->get($id, collect());
            $here = $mine->first(fn ($r): bool => (int) $r->company_id === $company);

            if ($here !== null) {
                $branch = $here->default_branch_id === null ? null : $branches->get($here->default_branch_id);
                // ⓘ as Branch::find() would: this company's, not deleted
                $this->branches[$id] = $branch !== null && (int) $branch->company_id === $company && $branch->deleted_at === null ? $branch->name() : null;

                continue;
            }

            $row = $mine->first(fn ($r): bool => (bool) $r->is_active);
            $name = $row === null ? '' : (string) $companies->get($row->company_id)?->name();
            $branch = $row?->default_branch_id === null ? null : $branches->get($row->default_branch_id)?->name();
            $this->branches[$id] = trim($name.($name !== '' && $branch ? ' · ' : '').($branch ?? '')) ?: null;
        }
    }

    private function branchOf(User $user): string
    {
        $uid = (int) $user->id;

        if (! array_key_exists($uid, $this->branches)) {
            /*
             * ⭐ The messenger spans the group (owner, 24 Nov 2026) — a colleague of a sister company shows "company · branch",
             * so two people of the same name are told apart. Someone in the open company shows the branch alone, as before.
             */
            $here = DB::table('company_user')->where('company_id', CompanyContext::id())->where('user_id', $uid)->first(['default_branch_id']);

            if ($here !== null) {
                $this->branches[$uid] = $here->default_branch_id === null ? null : Branch::query()->find($here->default_branch_id)?->name();
            } else {
                $row = DB::table('company_user')->where('user_id', $uid)->where('is_active', true)->orderBy('company_id')
                    ->first(['company_id', 'default_branch_id']);
                $company = $row === null ? '' : (string) \App\Models\Company::query()->find($row->company_id)?->name();
                $branch = $row?->default_branch_id === null ? null
                    : Branch::query()->withoutGlobalScopes()->whereKey($row->default_branch_id)->first()?->name();

                $this->branches[$uid] = trim($company.($company !== '' && $branch ? ' · ' : '').($branch ?? '')) ?: null;
            }
        }

        return (string) ($this->branches[$uid] ?? '');
    }

    private function photoOf(User $user): ?string
    {
        try {
            return request()->is('api/*') ? $user->avatarApiUrl() : $user->avatarUrl();
        } catch (\Throwable) {
            return null;
        }
    }
}
