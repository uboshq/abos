<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Messaging\Models\Member;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageFile;
use App\Modules\Messaging\Services\MessengerAccess;
use App\Modules\Messaging\Services\MessengerPresenter;
use App\Modules\Messaging\Services\MessengerService;
use App\Modules\Messaging\Services\PaperCards;
use App\Modules\Messaging\Services\Presence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The messenger's doors — the phone (`/api/v1/messenger/…`) and the web page (`/messages/x/…`) call the same methods,
 * so a tick, a card or a refusal can never differ between the two (contract §2).
 *
 * ⛔ Key `messaging.use` on every door; membership of the thread is asked by [[MessengerService]] on every read and write.
 * ⓘ Every call marks the caller as seen — "online" is "seen in the last 60 s" ([[Presence]]).
 */
final class MessengerApiController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MessengerService $messenger,
        private readonly MessengerPresenter $present,
        private readonly MessengerAccess $access,
        private readonly Presence $presence,
        private readonly PaperCards $papers,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:messaging.use')];
    }

    // ── list, people, new threads ───────────────────────────────────────

    public function threads(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'unread', 'groups', 'papers'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $threads = $this->messenger->threadsFor($me, (string) ($data['filter'] ?? 'all'), trim((string) ($data['q'] ?? '')));
        $members = Member::query()->whereIn('thread_id', $threads->pluck('id'))->with('user')->get()->groupBy('thread_id');

        return response()->json([
            'data' => $threads->map(fn ($t) => $this->present->thread($t, $me, $members->get($t->id, collect())))->values(),
            'unread_total' => $this->messenger->unreadTotal($me),
        ]);
    }

    public function thread(Request $request, string $thread): JsonResponse
    {
        $me = $this->me($request);
        $t = $this->messenger->threadFor($me, $thread);
        $members = $this->messenger->membersOf($t);

        return response()->json(['data' => $this->present->thread($t, $me, $members) + [
            'members' => $members->map(fn (Member $m) => $this->present->person($m->user) + ['admin' => $m->is_admin])->values(),
        ]]);
    }

    public function people(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'online' => ['nullable', 'boolean'],
            'paper_type' => ['nullable', 'string', 'max:80', 'required_with:paper_id'],
            'paper_id' => ['nullable', 'string', 'max:64', 'required_with:paper_type'],
        ]);

        $people = $this->access->reachable($me, trim((string) ($data['q'] ?? '')));
        $online = $this->presence->onlineMany($people->pluck('id')->map(fn ($id) => (int) $id)->all());

        if (! empty($data['online'])) {
            $people = $people->filter(fn (User $u): bool => $online[(int) $u->id] ?? false)->values();
        }

        $paper = isset($data['paper_type'], $data['paper_id']) ? [(string) $data['paper_type'], (string) $data['paper_id']] : null;

        return response()->json(['data' => $people->map(fn (User $u) => $this->present->person(
            $u,
            $paper === null ? null : $this->papers->mayOpen($u, $paper[0], $paper[1]),
            $online[(int) $u->id] ?? false,
        ))->values()]);
    }

    public function direct(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate(['person' => ['required', 'integer']]);
        $other = User::query()->find((int) $data['person']) ?? abort(404);
        $thread = $this->messenger->directWith($me, $other);

        return response()->json(['data' => $this->present->thread($thread, $me)], $thread->wasRecentlyCreated ? 201 : 200);
    }

    public function group(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'people' => ['required', 'array', 'min:1', 'max:200'],
            'people.*' => ['integer'],
        ]);
        $people = User::query()->whereIn('id', array_map('intval', $data['people']))->get();
        $thread = $this->messenger->createGroup($me, trim((string) $data['title']), $people);

        return response()->json(['data' => $this->present->thread($thread, $me)], 201);
    }

    public function addMembers(Request $request, string $thread): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate(['people' => ['required', 'array', 'min:1', 'max:200'], 'people.*' => ['integer']]);
        $t = $this->messenger->threadFor($me, $thread);
        $t = $this->messenger->addMembers($t, $me, User::query()->whereIn('id', array_map('intval', $data['people']))->get());

        return response()->json(['data' => $this->present->thread($t, $me)]);
    }

    // ── messages ─────────────────────────────────────────────────────────

    public function messages(Request $request, string $thread): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);
        $t = $this->messenger->threadFor($me, $thread);
        $rows = $this->messenger->messages($t, $me,
            isset($data['before']) ? (int) $data['before'] : null,
            isset($data['after']) ? (int) $data['after'] : null);
        $members = $this->messenger->membersOf($t);

        $others = $members->where('user_id', '!=', $me->id);
        $typing = $this->presence->typists((int) $t->id, (int) $me->id);

        return response()->json([
            'data' => $rows->map(fn (Message $m) => $this->present->message($m, $me, $members, $t))->values(),
            'meta' => [
                'typing' => $others->filter(fn (Member $m): bool => in_array((int) $m->user_id, $typing, true))
                    ->map(fn (Member $m) => $this->present->person($m->user, online: true))->values(),
                'peer_online' => ! $t->isGroup() && $others->first() !== null && $this->presence->online((int) $others->first()->user_id),
                'states' => $this->present->states($t, $me, $members),
                'has_more' => ! isset($data['after']) && $rows->count() >= MessengerService::PAGE,
            ],
        ]);
    }

    public function send(Request $request, string $thread): JsonResponse
    {
        $me = $this->me($request);
        $t = $this->messenger->threadFor($me, $thread);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['text', 'image', 'file', 'voice', 'location'])],
            'client_id' => ['nullable', 'string', 'max:64'],
            'text' => ['nullable', 'string', 'max:4000', 'required_if:kind,text'],
            'reply_to_id' => ['nullable', 'integer'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_if:kind,location'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_if:kind,location'],
            'file' => ['nullable', 'file', 'required_if:kind,image,file,voice'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'waveform' => ['nullable'],
        ]);

        $message = $this->messenger->send($t, $me, $data, $request->file('file'));

        return response()->json(
            ['data' => $this->present->message($message, $me, $this->messenger->membersOf($t), $t)],
            $message->getAttribute('repeated') ? 200 : 201,
        );
    }

    public function read(Request $request, string $thread): Response
    {
        $me = $this->me($request);
        $data = $request->validate(['up_to' => ['required', 'integer', 'min:1']]);
        $this->messenger->markRead($this->messenger->threadFor($me, $thread), $me, (int) $data['up_to']);

        return response()->noContent();
    }

    public function delivered(Request $request): Response
    {
        $me = $this->me($request);
        $data = $request->validate(['marks' => ['required', 'array', 'max:200'], 'marks.*' => ['integer', 'min:1']]);
        $this->messenger->markDelivered($me, $data['marks']);

        return response()->noContent();
    }

    public function typing(Request $request, string $thread): Response
    {
        $me = $this->me($request);
        $t = $this->messenger->threadFor($me, $thread);
        $this->presence->typing((int) $t->id, (int) $me->id);

        return response()->noContent();
    }

    public function mute(Request $request, string $thread): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate(['muted' => ['required', 'boolean']]);
        $t = $this->messenger->threadFor($me, $thread);
        $this->messenger->mute($t, $me, (bool) $data['muted']);

        return response()->json(['data' => $this->present->thread($t, $me)]);
    }

    public function members(Request $request, string $thread): JsonResponse
    {
        $me = $this->me($request);
        $members = $this->messenger->membersOf($this->messenger->threadFor($me, $thread));

        return response()->json(['data' => $members->filter(fn (Member $m) => $m->user !== null)
            ->map(fn (Member $m) => $this->present->person($m->user) + ['admin' => $m->is_admin])->values()]);
    }

    public function media(Request $request, string $thread): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate(['kind' => ['nullable', Rule::in(['image', 'file', 'paper'])]]);
        $t = $this->messenger->threadFor($me, $thread);
        $members = $this->messenger->membersOf($t);

        return response()->json(['data' => $this->messenger->media($t, $me, $data['kind'] ?? null)
            ->map(fn (Message $m) => $this->present->message($m, $me, $members, $t))->values()]);
    }

    public function search(Request $request, string $thread): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate(['q' => ['required', 'string', 'max:100']]);
        $t = $this->messenger->threadFor($me, $thread);
        $members = $this->messenger->membersOf($t);

        return response()->json(['data' => $this->messenger->search($t, $me, (string) $data['q'])
            ->map(fn (Message $m) => $this->present->message($m, $me, $members, $t))->values()]);
    }

    // ── papers, forward, delete, info ───────────────────────────────────

    public function papers(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate([
            'paper_type' => ['required', 'string', 'max:80'],
            'paper_id' => ['required', 'string', 'max:64'],
            'to' => ['required', 'array', 'min:1', 'max:50'],
            'to.*' => ['string', 'regex:/^(person|thread):[A-Za-z0-9-]+$/'],
            'note' => ['nullable', 'string', 'max:4000'],
            'client_id' => ['nullable', 'string', 'max:40'],
        ]);

        $threads = $this->messenger->sharePaper($me, (string) $data['paper_type'], (string) $data['paper_id'],
            $data['to'], $data['note'] ?? null, $data['client_id'] ?? null);

        return response()->json(['data' => ['sent' => count($threads), 'thread_ids' => array_map(fn ($t) => (string) $t->public_id, $threads)]], 201);
    }

    /** The composer's attach → "ERP কাগজ": my own papers by number, only those I may open ([[PaperCards::search()]]). */
    public function paperSearch(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:60']]);

        return response()->json(['data' => $this->papers->search($me, (string) ($data['q'] ?? ''))]);
    }

    public function forward(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate([
            'message_ids' => ['required', 'array', 'min:1', 'max:30'],
            'message_ids.*' => ['integer'],
            'to' => ['required', 'array', 'min:1', 'max:20'],
            'to.*' => ['string', 'regex:/^(person|thread):[A-Za-z0-9-]+$/'],
            'client_id' => ['nullable', 'string', 'max:40'],
        ]);

        $threads = $this->messenger->forward($me, $data['message_ids'], $data['to'], $data['client_id'] ?? null);

        return response()->json(['data' => ['sent' => count($threads), 'thread_ids' => array_map(fn ($t) => (string) $t->public_id, $threads)]], 201);
    }

    public function destroy(Request $request, int $message): Response
    {
        $me = $this->me($request);
        $data = $request->validate(['for' => ['required', Rule::in(['me', 'everyone'])]]);
        $this->messenger->delete($me, $message, (string) $data['for']);

        return response()->noContent();
    }

    public function info(Request $request, int $message): JsonResponse
    {
        $me = $this->me($request);

        return response()->json(['data' => array_map(fn (array $row) => [
            'person' => $this->present->person($row['user']),
            'delivered_at' => $row['delivered_at']?->toIso8601String(),
            'read_at' => $row['read_at']?->toIso8601String(),
        ], $this->messenger->info($me, $message))]);
    }

    /** ⛔ The file only to a member of its thread; private cache; thumbnails for photos. */
    public function file(Request $request, int $file): StreamedResponse
    {
        $me = $this->me($request);
        $row = $this->messenger->fileFor($me, $file);
        $thumb = $request->boolean('thumb') && $row->kind === 'image' && $row->thumb_path !== null;
        $path = $thumb ? (string) $row->thumb_path : $row->path;
        $disk = Storage::disk(MessageFile::DISK);

        abort_unless($disk->exists($path), 404);

        $inline = in_array($row->kind, ['image', 'voice'], true) || $row->mime === 'application/pdf';

        return $disk->response($path, $row->name, [
            'Content-Type' => $thumb ? 'image/jpeg' : $row->mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ], $inline ? 'inline' : 'attachment');
    }

    private function me(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        $this->presence->seen((int) $user->id);

        return $user;
    }
}
