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
            'online' => $online ?? $this->presence->online((int) $user->id),
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
        try {
            $role = $user->getRoleNames()->first();
        } catch (\Throwable) {
            $role = null;
        }

        return is_string($role) ? RoleLabel::for($role) : '';
    }

    private function branchOf(User $user): string
    {
        $uid = (int) $user->id;

        if (! array_key_exists($uid, $this->branches)) {
            $id = DB::table('company_user')->where('company_id', CompanyContext::id())->where('user_id', $uid)->value('default_branch_id');
            $this->branches[$uid] = $id === null ? null : Branch::query()->find($id)?->name();
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
