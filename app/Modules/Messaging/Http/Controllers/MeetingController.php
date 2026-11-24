<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Messaging\Models\Meeting;
use App\Modules\Messaging\Models\MeetingPerson;
use App\Modules\Messaging\Services\MeetingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * The meeting page and its doors. ⓘ `{meeting}` is the public id, resolved by [[MeetingService::forUser()]]; every power is
 * asked in the service.
 */
final class MeetingController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MeetingService $meetings) {}

    public static function middleware(): array
    {
        return [new Middleware('can:messaging.use')];
    }

    public function page(Request $request, string $meeting): View
    {
        $row = $this->meetings->forUser($this->me($request), $meeting);
        abort_unless($this->meetings->isOn((int) $row->company_id), 404);

        return view('messaging::meeting', [
            'menu' => app(MenuBuilder::class)->forUser($request->user()),
            'meeting' => $row,
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->meetings->mine($this->me($request))]);
    }

    /** POST …/meetings/new/{key} → 201 { data: { id, url } }; the same key again → the same meeting */
    public function start(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'thread' => ['sometimes', 'nullable', 'string', 'max:64'],
            'video' => ['sometimes', 'boolean'],
            'people' => ['sometimes', 'array', 'max:50'],
            'people.*' => ['integer', 'min:1'],
        ]);

        $meeting = $this->meetings->start($this->me($request), $key, trim((string) ($data['title'] ?? '')),
            array_map('intval', $data['people'] ?? []), (bool) ($data['video'] ?? false), $data['thread'] ?? null);

        return response()->json(['data' => ['id' => (string) $meeting->public_id,
            'url' => route('messaging.meeting', ['meeting' => $meeting->public_id])]], 201);
    }

    public function state(Request $request, string $meeting): JsonResponse
    {
        $me = $this->me($request);

        return response()->json(['data' => $this->meetings->state($this->meetings->forUser($me, $meeting), $me)]);
    }

    public function join(Request $request, string $meeting): JsonResponse
    {
        $me = $this->me($request);

        return response()->json(['data' => $this->meetings->join($this->meetings->forUser($me, $meeting), $me)]);
    }

    public function renew(Request $request, string $meeting): JsonResponse
    {
        $me = $this->me($request);

        return response()->json(['data' => $this->meetings->renew($this->meetings->forUser($me, $meeting), $me)]);
    }

    public function leave(Request $request, string $meeting): Response
    {
        return $this->run($request, $meeting, fn (Meeting $m, User $me) => $this->meetings->leave($m, $me));
    }

    public function decline(Request $request, string $meeting): Response
    {
        return $this->run($request, $meeting, fn (Meeting $m, User $me) => $this->meetings->decline($m, $me));
    }

    public function end(Request $request, string $meeting): Response
    {
        return $this->run($request, $meeting, fn (Meeting $m, User $me) => $this->meetings->end($m, $me));
    }

    public function invite(Request $request, string $meeting): Response
    {
        $data = $request->validate(['people' => ['required', 'array', 'min:1', 'max:50'], 'people.*' => ['integer', 'min:1']]);

        return $this->run($request, $meeting, fn (Meeting $m, User $me) => $this->meetings->invite($m, $me, array_map('intval', $data['people'])));
    }

    /** POST …/{meeting}/lobby {person, yes} */
    public function lobby(Request $request, string $meeting): Response
    {
        $data = $request->validate(['person' => ['required', 'integer', 'min:1'], 'yes' => ['required', 'boolean']]);

        return $this->run($request, $meeting, fn (Meeting $m, User $me) => $this->meetings->answerLobby($m, $me, (int) $data['person'], (bool) $data['yes']));
    }

    public function remove(Request $request, string $meeting): Response
    {
        $person = $this->person($request);

        return $this->run($request, $meeting, fn (Meeting $m, User $me) => $this->meetings->remove($m, $me, $person));
    }

    public function role(Request $request, string $meeting): Response
    {
        $data = $request->validate(['person' => ['required', 'integer', 'min:1'], 'role' => ['required', Rule::in([MeetingPerson::COHOST, MeetingPerson::MEMBER])]]);

        return $this->run($request, $meeting, fn (Meeting $m, User $me) => $this->meetings->role($m, $me, (int) $data['person'], $data['role']));
    }

    /** POST …/{meeting}/set {locked?, lobby?, allow_share?} */
    public function set(Request $request, string $meeting): Response
    {
        $data = $request->validate([
            'locked' => ['sometimes', 'boolean'],
            'lobby' => ['sometimes', 'boolean'],
            'allow_share' => ['sometimes', Rule::in([Meeting::EVERYONE, Meeting::HOSTS])],
        ]);

        foreach (['locked', 'lobby'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = (bool) $data[$flag];
            }
        }

        return $this->run($request, $meeting, fn (Meeting $m, User $me) => $this->meetings->set($m, $me, $data));
    }

    /** POST …/{meeting}/mute {person?} — nobody named = everyone */
    public function mute(Request $request, string $meeting): Response
    {
        $data = $request->validate(['person' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $person = isset($data['person']) ? (int) $data['person'] : null;

        return $this->run($request, $meeting, fn (Meeting $m, User $me) => $this->meetings->mute($m, $me, $person));
    }

    public function stopShare(Request $request, string $meeting): Response
    {
        $person = $this->person($request);

        return $this->run($request, $meeting, fn (Meeting $m, User $me) => $this->meetings->stopShare($m, $me, $person));
    }

    /** @param  callable(Meeting, User): void  $step */
    private function run(Request $request, string $meeting, callable $step): Response
    {
        $me = $this->me($request);
        $step($this->meetings->forUser($me, $meeting), $me);

        return response()->noContent();
    }

    private function person(Request $request): int
    {
        return (int) $request->validate(['person' => ['required', 'integer', 'min:1']])['person'];
    }

    private function me(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
