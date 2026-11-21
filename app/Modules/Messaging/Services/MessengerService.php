<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\Engines\Image\ImageEngine;
use App\Jobs\SendPushToUser;
use App\Models\User;
use App\Modules\Messaging\Models\HiddenMessage;
use App\Modules\Messaging\Models\Member;
use App\Modules\Messaging\Models\MemberMark;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageFile;
use App\Modules\Messaging\Models\Thread;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The messenger's one writer — the phone doors and the web page both come here (contract §2–§5).
 *
 * ⛔ Every read and write first asks "is this person a member of this thread" — the role allow-list only decides
 * who may START talking ([[MessengerAccess]]).
 */
final class MessengerService
{
    public const PAGE = 30;

    public const LIST_PAGE = 50;

    /** ⓘ sizes, contract §3 */
    public const IMAGE_IN_KB = 10 * 1024;

    public const FILE_KB = 20 * 1024;

    public const VOICE_KB = 5 * 1024;

    public const FILE_EXT = ['pdf', 'xlsx', 'xls', 'docx', 'doc', 'csv', 'txt', 'zip'];

    /**
     * Voice notes: what the file really is (sniffed) → [stored extension, served type].
     * ⓘ The phone records m4a/aac; a browser records mp4 where it can (Chrome, Safari) and webm/ogg where it cannot
     * (Firefox, older Chrome) — the web page (sample v7) sends whatever its browser makes.
     */
    public const VOICE_TYPES = [
        'audio/mp4' => ['m4a', 'audio/mp4'], 'audio/x-m4a' => ['m4a', 'audio/mp4'], 'audio/m4a' => ['m4a', 'audio/mp4'],
        'audio/mpeg4-generic' => ['m4a', 'audio/mp4'], 'video/mp4' => ['m4a', 'audio/mp4'],
        'audio/aac' => ['aac', 'audio/aac'], 'audio/x-hx-aac-adts' => ['aac', 'audio/aac'],
        'audio/webm' => ['webm', 'audio/webm'], 'video/webm' => ['webm', 'audio/webm'],
        'audio/ogg' => ['ogg', 'audio/ogg'], 'application/ogg' => ['ogg', 'audio/ogg'], 'video/ogg' => ['ogg', 'audio/ogg'],
    ];

    /** ⓘ when the sniffer only says "octet-stream", the name's extension decides */
    private const VOICE_EXT = ['m4a' => 'audio/mp4', 'aac' => 'audio/aac', 'webm' => 'audio/webm', 'ogg' => 'audio/ogg'];

    public function __construct(
        private readonly MessengerAccess $access,
        private readonly Presence $presence,
        private readonly PaperCards $papers,
    ) {}

    // ── threads ──────────────────────────────────────────────────────────

    /** @return Collection<int, Thread> */
    public function threadsFor(User $user, string $filter = 'all', string $q = ''): Collection
    {
        $query = Thread::query()->of((int) $user->id)->with('lastMessage')
            ->orderByDesc('last_at')->orderByDesc('id');

        if ($filter === 'groups') {
            $query->where('kind', Thread::GROUP);
        }

        if ($filter === 'papers') {
            $query->whereIn('id', Message::query()->select('thread_id')->where('kind', 'paper'));
        }

        if ($filter === 'unread') {
            $query->whereExists(function ($q) use ($user): void {
                $q->select(DB::raw(1))->from('messenger_members as mm')
                    ->join('messenger_messages as m', 'm.thread_id', '=', 'mm.thread_id')
                    ->whereColumn('mm.thread_id', 'messenger_threads.id')
                    ->where('mm.user_id', $user->id)
                    ->whereColumn('m.id', '>', 'mm.read_id')
                    ->whereNotNull('m.sender_id')->where('m.sender_id', '!=', $user->id)
                    ->whereNull('m.deleted_at')
                    ->whereNotIn('m.id', $this->hiddenFor($user));
            });
        }

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function ($w) use ($like, $user): void {
                $w->where('title', 'like', $like)
                    // ⓘ the OTHER members' names — my own name would match every thread
                    ->orWhereIn('id', Member::query()->select('thread_id')->where('user_id', '!=', $user->id)
                        ->whereIn('user_id', User::query()->select('id')->where('name', 'like', $like)))
                    ->orWhereIn('id', Message::query()->select('thread_id')->where('kind', 'text')->where('text', 'like', $like));
            });
        }

        return $query->limit(self::LIST_PAGE)->get();
    }

    public function unreadTotal(User $user): int
    {
        return (int) DB::table('messenger_members as mm')
            ->join('messenger_messages as m', 'm.thread_id', '=', 'mm.thread_id')
            ->where('mm.user_id', $user->id)
            ->where('mm.company_id', \App\Core\Support\CompanyContext::id())
            ->whereColumn('m.id', '>', 'mm.read_id')
            // ⓘ unread = someone else's live message: not a system line, not deleted for everyone, not hidden for me
            ->whereNotNull('m.sender_id')->where('m.sender_id', '!=', $user->id)
            ->whereNull('m.deleted_at')
            ->whereNotIn('m.id', $this->hiddenFor($user))
            ->count();
    }

    /** A thread by its public id — only if this person is in it (404 otherwise: "exists" is information too). */
    public function threadFor(User $user, string $publicId): Thread
    {
        $thread = Thread::query()->wherePublicId($publicId)->first();

        if ($thread === null || ! $this->access->isMember($thread, $user)) {
            throw new HttpException(404);
        }

        return $thread;
    }

    /** Finds or creates the one direct thread of a pair. */
    public function directWith(User $me, User $other): Thread
    {
        $key = Thread::directKey((int) $me->id, (int) $other->id);
        $existing = Thread::query()->where('direct_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->refuseUnlessMayTalk($me, $other);

        try {
            return DB::transaction(function () use ($me, $other, $key): Thread {
                $thread = Thread::query()->create(['kind' => Thread::DIRECT, 'direct_key' => $key, 'created_by' => $me->id]);
                foreach ([$me, $other] as $u) {
                    Member::query()->create(['thread_id' => $thread->id, 'user_id' => $u->id, 'joined_at' => now()]);
                }

                return $thread;
            });
        } catch (QueryException) {
            // ⓘ both opened the chat at the same moment — the unique key let one win; take it
            return Thread::query()->where('direct_key', $key)->firstOrFail();
        }
    }

    /** @param  Collection<int, User>  $people */
    public function createGroup(User $me, string $title, Collection $people): Thread
    {
        if (! $me->can('messaging.group.create')) {
            throw $this->refusal('group_create_denied');
        }

        foreach ($people as $p) {
            $this->refuseUnlessMayTalk($me, $p);
        }

        return DB::transaction(function () use ($me, $title, $people): Thread {
            $thread = Thread::query()->create(['kind' => Thread::GROUP, 'title' => $title, 'created_by' => $me->id]);
            Member::query()->create(['thread_id' => $thread->id, 'user_id' => $me->id, 'is_admin' => true, 'joined_at' => now()]);
            foreach ($people as $p) {
                Member::query()->firstOrCreate(['thread_id' => $thread->id, 'user_id' => $p->id], ['joined_at' => now()]);
            }
            $this->system($thread, ['event' => 'group_created', 'actor' => $me->name, 'title' => $title]);

            return $thread;
        });
    }

    /** @param  Collection<int, User>  $people */
    public function addMembers(Thread $thread, User $me, Collection $people): Thread
    {
        $mine = $this->access->member($thread, $me);

        if (! $thread->isGroup() || $mine === null || ! $mine->is_admin) {
            throw $this->refusal('forbidden');
        }

        $added = [];
        DB::transaction(function () use ($thread, $me, $people, &$added): void {
            foreach ($people as $p) {
                if (Member::query()->where('thread_id', $thread->id)->where('user_id', $p->id)->exists()) {
                    continue;
                }
                $this->refuseUnlessMayTalk($me, $p);
                $row = Member::query()->firstOrCreate(['thread_id' => $thread->id, 'user_id' => $p->id], ['joined_at' => now()]);
                if ($row->wasRecentlyCreated) {
                    // ⓘ a new member starts at the present — they do not inherit an "unread" history
                    $row->forceFill(['delivered_id' => (int) $thread->last_message_id, 'read_id' => (int) $thread->last_message_id])->save();
                    $added[] = $p->name;
                }
            }
            if ($added !== []) {
                $this->system($thread, ['event' => 'members_added', 'actor' => $me->name, 'names' => $added]);
            }
        });

        return $thread->refresh();
    }

    // ── messages ─────────────────────────────────────────────────────────

    /** @return Collection<int, Message> oldest first */
    public function messages(Thread $thread, User $user, ?int $before = null, ?int $after = null, int $limit = self::PAGE): Collection
    {
        $query = Message::query()->where('thread_id', $thread->id)->with(['file', 'sender'])
            ->whereNotIn('id', $this->hiddenFor($user));

        if ($after !== null) {
            $rows = $query->where('id', '>', $after)->orderBy('id')->limit(200)->get();
        } else {
            if ($before !== null) {
                $query->where('id', '<', $before);
            }
            $rows = $query->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        }

        // ⭐ fetching = delivered (the "পৌঁছেছে" tick) — contract §2
        $top = (int) ($rows->max('id') ?? 0);
        if ($top > 0) {
            $this->moveMark($thread, $user, MemberMark::DELIVERED, $top);
        }

        return $rows;
    }

    /**
     * ⭐ Moves a member's delivered or read mark forward — never back — and writes when, for "message info".
     * ⓘ Read implies delivered.
     */
    public function moveMark(Thread $thread, User $user, string $kind, int $upTo): void
    {
        $upTo = min($upTo, (int) $thread->last_message_id);
        if ($upTo <= 0) {
            return;
        }

        $member = $this->access->member($thread, $user);
        if ($member === null) {
            return;
        }

        $now = now();
        $kinds = $kind === MemberMark::READ ? [MemberMark::DELIVERED, MemberMark::READ] : [MemberMark::DELIVERED];

        foreach ($kinds as $k) {
            $column = $k === MemberMark::READ ? 'read_id' : 'delivered_id';
            $moved = Member::query()->whereKey($member->id)->where($column, '<', $upTo)->update([$column => $upTo]);
            if ($moved > 0) {
                MemberMark::query()->create(['member_id' => $member->id, 'kind' => $k, 'up_to' => $upTo, 'at' => $now]);
            }
        }
    }

    /** @return \Illuminate\Database\Eloquent\Builder<HiddenMessage> ids this person deleted "for me" */
    private function hiddenFor(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return HiddenMessage::query()->select('message_id')->where('user_id', $user->id);
    }

    /**
     * Forward: copies of one or more messages into one or more threads (owner, sample v7).
     *
     * ⛔ Only messages the forwarder can see (member of their thread, not deleted, not hidden).
     * ⛔ A paper is re-checked against the forwarder's own right to open it — [[send()]] does that.
     * ⓘ A file is not copied on disk; the new file row points at the same stored file (files are never removed).
     *
     * @param  list<int|string>  $messageIds
     * @param  list<string>  $to  "person:<id>" | "thread:<public id>"
     * @return list<Thread>
     */
    public function forward(User $me, array $messageIds, array $to, ?string $clientId): array
    {
        $sources = Message::query()->whereIn('id', array_map('intval', $messageIds))
            ->whereNull('deleted_at')->where('kind', '!=', 'system')
            ->whereNotIn('id', $this->hiddenFor($me))
            ->with(['file', 'thread'])->orderBy('id')->get()
            ->filter(fn (Message $m): bool => $m->thread !== null && $this->access->isMember($m->thread, $me))
            ->values();

        if ($sources->isEmpty()) {
            throw new HttpException(404);
        }

        $threads = [];
        $to = array_values(array_unique(array_map('strval', $to)));
        // ⛔ every target first — a refusal on the third must not leave the first two already sent
        foreach ($to as $target) {
            $this->checkTarget($me, $target);
        }

        foreach ($to as $i => $target) {
            $thread = $this->targetThread($me, (string) $target);
            foreach ($sources as $j => $source) {
                $data = [
                    'kind' => $source->kind,
                    'text' => $source->text,
                    'lat' => $source->lat,
                    'lng' => $source->lng,
                    'paper_type' => $source->paper_type,
                    'paper_id' => $source->paper_id,
                    'client_id' => $clientId !== null ? $clientId.':'.$i.':'.$j : null,
                    'forwarded' => true,
                    'copy_file' => $source->file,
                ];
                $this->send($thread, $me, $data);
            }
            $threads[] = $thread;
        }

        return $threads;
    }

    /**
     * Delete — "me" hides it for me only; "everyone" leaves a tombstone, my own message, within the hour.
     */
    public function delete(User $me, int $messageId, string $for): Message
    {
        $message = Message::query()->with('thread')->find($messageId);

        if ($message === null || $message->thread === null || ! $this->access->isMember($message->thread, $me)) {
            throw new HttpException(404);
        }

        if ($for === 'me') {
            HiddenMessage::query()->firstOrCreate(['message_id' => $message->id, 'user_id' => $me->id]);

            return $message;
        }

        $mine = $message->sender_id !== null && (int) $message->sender_id === (int) $me->id;
        $inTime = $message->created_at !== null && $message->created_at->gt(now()->subMinutes(Message::DELETE_WINDOW_MINUTES));

        if (! $mine || ! $inTime || $message->kind === 'system') {
            throw $this->refusal('delete_not_allowed');
        }

        if (! $message->isDeleted()) {
            $message->forceFill(['deleted_at' => now()])->save();
        }

        return $message;
    }

    /**
     * Message info — for each other member: when it reached them, when they read it.
     *
     * @return list<array{user: User, delivered_at: ?\Illuminate\Support\Carbon, read_at: ?\Illuminate\Support\Carbon}>
     */
    public function info(User $me, int $messageId): array
    {
        $message = Message::query()->with('thread')->find($messageId);

        if ($message === null || $message->thread === null || ! $this->access->isMember($message->thread, $me)) {
            throw new HttpException(404);
        }

        $out = [];
        foreach ($this->membersOf($message->thread) as $member) {
            if ((int) $member->user_id === (int) $message->sender_id || $member->user === null) {
                continue;
            }
            $first = fn (string $kind) => MemberMark::query()->where('member_id', $member->id)->where('kind', $kind)
                ->where('up_to', '>=', $message->id)->orderBy('up_to')->value('at');
            $out[] = [
                'user' => $member->user,
                'delivered_at' => $member->delivered_id >= $message->id || $member->read_id >= $message->id ? $this->asDate($first(MemberMark::DELIVERED)) : null,
                'read_at' => $member->read_id >= $message->id ? $this->asDate($first(MemberMark::READ)) : null,
            ];
        }

        return $out;
    }

    private function asDate(mixed $v): ?\Illuminate\Support\Carbon
    {
        return $v === null ? null : \Illuminate\Support\Carbon::parse($v);
    }

    /** Would [[targetThread()]] succeed? — asks without creating anything. */
    private function checkTarget(User $me, string $target): void
    {
        [$kind, $ref] = array_pad(explode(':', $target, 2), 2, '');

        if ($kind === 'thread') {
            $this->threadFor($me, $ref);

            return;
        }

        if ($kind !== 'person') {
            throw ValidationException::withMessages(['to' => __('messaging::messenger.bad_target')]);
        }

        $other = User::query()->find((int) $ref) ?? throw new HttpException(404);
        $existing = Thread::query()->where('direct_key', Thread::directKey((int) $me->id, (int) $other->id))->exists();

        if (! $existing) {
            $this->refuseUnlessMayTalk($me, $other);
        }
    }

    private function targetThread(User $me, string $target): Thread
    {
        [$kind, $ref] = array_pad(explode(':', $target, 2), 2, '');

        return match ($kind) {
            'person' => $this->directWith($me, User::query()->findOrFail((int) $ref)),
            'thread' => $this->threadFor($me, $ref),
            default => throw ValidationException::withMessages(['to' => __('messaging::messenger.bad_target')]),
        };
    }

    /**
     * @param  array{kind: string, text?: ?string, client_id?: ?string, reply_to_id?: ?int, lat?: mixed, lng?: mixed,
     *               duration_ms?: mixed, waveform?: mixed, paper_type?: ?string, paper_id?: ?string}  $data
     */
    public function send(Thread $thread, User $user, array $data, ?UploadedFile $file = null): Message
    {
        if (! $this->access->isMember($thread, $user)) {
            throw $this->refusal('forbidden');
        }

        $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (string) $data['client_id'] : null;
        if ($clientId !== null) {
            $again = Message::query()->where('sender_id', $user->id)->where('client_id', $clientId)->first();
            if ($again !== null) {
                // ⛔ the phone's id is per message; the same id in another thread is a phone bug, not a repeat
                if ((int) $again->thread_id !== (int) $thread->id) {
                    throw ValidationException::withMessages(['client_id' => __('messaging::messenger.client_id_used')]);
                }
                $again->setAttribute('repeated', true);

                return $again;
            }
        }

        $kind = (string) $data['kind'];
        $replyTo = isset($data['reply_to_id']) && $data['reply_to_id'] !== null
            ? Message::query()->where('thread_id', $thread->id)->find((int) $data['reply_to_id'])?->id
            : null;

        $attrs = [
            'thread_id' => $thread->id,
            'sender_id' => $user->id,
            'client_id' => $clientId,
            'kind' => $kind,
            'text' => isset($data['text']) ? trim((string) $data['text']) : null,
            'reply_to_id' => $replyTo,
            'forwarded' => (bool) ($data['forwarded'] ?? false),
        ];

        if ($kind === 'location') {
            $attrs['lat'] = (string) $data['lat'];
            $attrs['lng'] = (string) $data['lng'];
        }

        if ($kind === 'paper') {
            $type = (string) ($data['paper_type'] ?? '');
            $id = (string) ($data['paper_id'] ?? '');
            $found = $this->papers->find($type, $id);
            // ⛔ the sender must be able to open what they send — a card never leaks a paper its sender could not see
            if ($found === null || ! $this->papers->mayOpen($user, $type, $id)) {
                throw $this->refusal('paper_denied');
            }
            $attrs['paper_type'] = $type;
            $attrs['paper_id'] = $id;
            $attrs['paper_no'] = $this->papers->numberOf($found[2]);
        }

        $stored = null;
        $copy = $data['copy_file'] ?? null;
        if (in_array($kind, Message::FILE_KINDS, true) && $copy instanceof MessageFile) {
            // ⓘ a forward — the same stored file, a new row (files are never removed, so sharing the path is safe)
            $stored = $copy->only(['kind', 'path', 'thumb_path', 'name', 'mime', 'size', 'width', 'height', 'duration_ms', 'waveform']);
            $stored['shared'] = true;
        } elseif (in_array($kind, Message::FILE_KINDS, true)) {
            if ($file === null) {
                throw ValidationException::withMessages(['file' => __('messaging::messenger.file_required')]);
            }
            $stored = $this->storeFile($kind, $file, $data);
        }

        try {
            $message = DB::transaction(function () use ($thread, $attrs, $stored): Message {
                $message = Message::query()->create($attrs);
                if ($stored !== null) {
                    $row = $stored;
                    unset($row['shared']);
                    MessageFile::query()->create($row + ['message_id' => $message->id]);
                }
                Thread::query()->whereKey($thread->id)->update(['last_message_id' => $message->id, 'last_at' => $message->created_at]);
                // ⓘ my own message is read and delivered for me
                Member::query()->where('thread_id', $thread->id)->where('user_id', $attrs['sender_id'])
                    ->update(['read_id' => $message->id, 'delivered_id' => $message->id]);

                return $message;
            });
        } catch (QueryException $e) {
            if ($stored !== null && empty($stored['shared'])) {
                Storage::disk(MessageFile::DISK)->delete(array_filter([$stored['path'], $stored['thumb_path'] ?? null]));
            }
            if ($clientId !== null && ($again = Message::query()->where('sender_id', $user->id)->where('client_id', $clientId)->first())) {
                return $again;
            }
            throw $e;
        }

        $this->presence->stoppedTyping((int) $thread->id, (int) $user->id);
        $this->push($thread->refresh(), $message, $user);

        return $message;
    }

    /**
     * One paper to several people and groups at once (sample tab ঘ / app `POST /messenger/papers`).
     *
     * @param  list<string>  $to  "person:<user id>" | "thread:<public id>"
     * @return list<Thread>
     */
    public function sharePaper(User $me, string $type, string $id, array $to, ?string $note, ?string $clientId): array
    {
        if ($this->papers->find($type, $id) === null || ! $this->papers->mayOpen($me, $type, $id)) {
            throw $this->refusal('paper_denied');
        }

        $threads = [];
        $to = array_values(array_unique(array_map('strval', $to)));
        // ⛔ every target first — a refusal on the third must not leave the first two already sent
        foreach ($to as $target) {
            $this->checkTarget($me, $target);
        }

        foreach ($to as $i => $target) {
            $thread = $this->targetThread($me, (string) $target);
            $this->send($thread, $me, [
                'kind' => 'paper',
                'paper_type' => $type,
                'paper_id' => $id,
                'text' => $note,
                'client_id' => $clientId !== null ? $clientId.':'.$i : null,
            ]);
            $threads[] = $thread;
        }

        return $threads;
    }

    public function markRead(Thread $thread, User $user, int $upTo): void
    {
        $this->moveMark($thread, $user, MemberMark::READ, $upTo);
    }

    /** @param  array<string, int|string>  $marks  thread public id → last message id */
    public function markDelivered(User $user, array $marks): void
    {
        foreach ($marks as $publicId => $last) {
            $thread = Thread::query()->wherePublicId((string) $publicId)->first();
            if ($thread === null) {
                continue;
            }
            $this->moveMark($thread, $user, MemberMark::DELIVERED, (int) $last);
        }
    }

    public function mute(Thread $thread, User $user, bool $muted): void
    {
        Member::query()->where('thread_id', $thread->id)->where('user_id', $user->id)->update(['muted' => $muted]);
    }

    /** @return Collection<int, Message> newest first */
    public function search(Thread $thread, User $user, string $q): Collection
    {
        if (trim($q) === '') {
            return collect();
        }

        return Message::query()->where('thread_id', $thread->id)
            ->whereNull('deleted_at')->whereNotIn('id', $this->hiddenFor($user))
            ->whereIn('kind', ['text', 'image', 'file', 'paper'])
            ->where(fn ($w) => $w->where('text', 'like', '%'.addcslashes($q, '%_\\').'%')
                ->orWhere('paper_no', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->with(['file', 'sender'])->orderByDesc('id')->limit(50)->get();
    }

    /** @return Collection<int, Message> newest first */
    public function media(Thread $thread, User $user, ?string $kind = null): Collection
    {
        $kinds = match ($kind) {
            'image' => ['image'],
            'file' => ['file', 'voice'],
            'paper' => ['paper'],
            default => ['image', 'file', 'voice', 'paper'],
        };

        return Message::query()->where('thread_id', $thread->id)->whereIn('kind', $kinds)
            ->whereNull('deleted_at')->whereNotIn('id', $this->hiddenFor($user))
            ->with(['file', 'sender'])->orderByDesc('id')->limit(100)->get();
    }

    /** @return Collection<int, Member> */
    public function membersOf(Thread $thread): Collection
    {
        return $thread->members()->with('user')->orderByDesc('is_admin')->orderBy('id')->get();
    }

    /** The file of a message, only for a member of its thread. */
    public function fileFor(User $user, int $fileId): MessageFile
    {
        $file = MessageFile::query()->with('message.thread')->find($fileId);

        // ⛔ a deleted-for-everyone message's file is gone for members too (the audit door reads it another way)
        if ($file === null || $file->message === null || $file->message->thread === null || $file->message->isDeleted()
            || ! $this->access->isMember($file->message->thread, $user)) {
            throw new HttpException(404);
        }

        return $file;
    }

    // ── inner work ───────────────────────────────────────────────────────

    private function refuseUnlessMayTalk(User $me, User $other): void
    {
        if (! $this->access->mayTalk($me, $other)) {
            throw $this->refusal('may_not_talk');
        }
    }

    public function refusal(string $code): HttpException
    {
        return new MessengerRefusal($code, __('messaging::messenger.refused.'.$code));
    }

    /** @param  array<string, mixed>  $meta */
    private function system(Thread $thread, array $meta): Message
    {
        $message = Message::query()->create(['thread_id' => $thread->id, 'sender_id' => null, 'kind' => 'system', 'meta' => $meta]);
        Thread::query()->whereKey($thread->id)->update(['last_message_id' => $message->id, 'last_at' => $message->created_at]);

        return $message;
    }

    /**
     * Checks and stores one upload on the private disk.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function storeFile(string $kind, UploadedFile $file, array $data): array
    {
        $kb = (int) ceil($file->getSize() / 1024);
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');
        $mime = strtolower((string) $file->getMimeType());
        $name = mb_substr(basename((string) $file->getClientOriginalName()) ?: ($kind.'.'.$ext), 0, 200);
        $dir = 'messenger/'.\App\Core\Support\CompanyContext::id().'/'.now()->format('Y/m');

        if ($kind === 'image') {
            if ($kb > self::IMAGE_IN_KB) {
                throw ValidationException::withMessages(['file' => __('messaging::messenger.too_big', ['mb' => 10])]);
            }
            $engine = app(ImageEngine::class);
            if (! in_array($mime, ImageEngine::READS, true) || ! $engine->reads($file->getRealPath())) {
                throw ValidationException::withMessages(['file' => __('messaging::messenger.bad_type')]);
            }
            // ⭐ the deposit slip's engine, photo road — upright, EXIF (GPS too) gone, ≤ 2000 px, ≤ 800 KB
            $jpeg = $engine->photo($file->getRealPath());
            $path = $dir.'/'.Str::uuid7().'.jpg';
            Storage::disk(MessageFile::DISK)->put($path, $jpeg);
            [$w, $h] = @getimagesizefromstring($jpeg) ?: [null, null];

            $thumb = $this->thumb($jpeg);
            $thumbPath = null;
            if ($thumb !== null) {
                $thumbPath = $dir.'/'.Str::uuid7().'-t.jpg';
                Storage::disk(MessageFile::DISK)->put($thumbPath, $thumb);
            }

            return ['kind' => 'image', 'path' => $path, 'thumb_path' => $thumbPath, 'name' => preg_replace('/\.[^.]+$/', '', $name).'.jpg',
                'mime' => 'image/jpeg', 'size' => strlen($jpeg), 'width' => $w, 'height' => $h];
        }

        if ($kind === 'voice') {
            if ($kb > self::VOICE_KB) {
                throw ValidationException::withMessages(['file' => __('messaging::messenger.too_big', ['mb' => 5])]);
            }
            $type = self::VOICE_TYPES[$mime]
                ?? ($mime === 'application/octet-stream' && isset(self::VOICE_EXT[$ext]) ? self::VOICE_TYPES[self::VOICE_EXT[$ext]] : null);
            if ($type === null) {
                throw ValidationException::withMessages(['file' => __('messaging::messenger.bad_type')]);
            }
            [$voiceExt, $voiceMime] = $type;
            $path = $file->storeAs($dir, Str::uuid7().'.'.$voiceExt, ['disk' => MessageFile::DISK]);

            return ['kind' => 'voice', 'path' => (string) $path, 'name' => $name, 'mime' => $voiceMime,
                'size' => (int) $file->getSize(), 'duration_ms' => max(0, min(15 * 60 * 1000, (int) ($data['duration_ms'] ?? 0))),
                'waveform' => $this->waveform($data['waveform'] ?? null)];
        }

        if ($kb > self::FILE_KB) {
            throw ValidationException::withMessages(['file' => __('messaging::messenger.too_big', ['mb' => 20])]);
        }
        if (! in_array($ext, self::FILE_EXT, true)) {
            throw ValidationException::withMessages(['file' => __('messaging::messenger.bad_type')]);
        }
        $path = $file->storeAs($dir, Str::uuid7().'.'.$ext, ['disk' => MessageFile::DISK]);

        return ['kind' => 'file', 'path' => (string) $path, 'name' => $name, 'mime' => $mime ?: 'application/octet-stream', 'size' => (int) $file->getSize()];
    }

    /** @return list<float> 0–1, at most 64 bars */
    private function waveform(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : explode(',', $raw);
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (array_slice($raw, 0, 64) as $v) {
            if (is_numeric($v)) {
                $out[] = round(max(0.0, min(1.0, (float) $v)), 2);
            }
        }

        return $out;
    }

    private function thumb(string $jpeg): ?string
    {
        $src = @imagecreatefromstring($jpeg);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, 400 / max($w, $h));
        $dst = imagescale($src, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        if ($dst === false) {
            return null;
        }
        ob_start();
        imagejpeg($dst, null, 72);

        return (string) ob_get_clean();
    }

    /** ⓘ a push to every other member's phones — never the sender's, never a muted thread (contract §5) */
    private function push(Thread $thread, Message $message, User $sender): void
    {
        $title = $thread->isGroup() ? $sender->name.' · '.$thread->title : $sender->name;
        $body = match ($message->kind) {
            'text' => Str::limit((string) $message->text, 80),
            'image' => '📷 '.__('messaging::messenger.kind.image'),
            'file' => '📄 '.__('messaging::messenger.kind.file'),
            'voice' => '🎤 '.__('messaging::messenger.kind.voice'),
            'paper' => '🧾 '.$message->paper_no,
            'location' => '📍 '.__('messaging::messenger.kind.location'),
            default => '',
        };

        $data = [
            'open' => 'messenger',
            'type' => 'chat',
            'thread_id' => (string) $thread->public_id,
            'message_id' => (string) $message->id,
            'from' => (string) $sender->id,
        ];

        Member::query()->where('thread_id', $thread->id)->where('user_id', '!=', $sender->id)->where('muted', false)
            ->pluck('user_id')
            ->each(fn ($uid) => SendPushToUser::dispatch((int) $uid, $title, $data, $body));
    }
}
