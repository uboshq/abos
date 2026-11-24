<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Messaging\Services\CallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The call doors (contract §2) — the phone API and the web messages page both use these; JSON either way.
 *
 * ⓘ `{call}` is the call's public id, resolved by [[CallService::forUser()]] — 404 to anyone but the two parties.
 */
final class MessengerCallApiController extends Controller implements HasMiddleware
{
    public function __construct(private readonly CallService $calls) {}

    public static function middleware(): array
    {
        return [new Middleware('can:messaging.use')];
    }

    /** POST /messenger/calls {person, video} → 201 { data: { call_id, url, token, video, peer, room } } */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'person' => ['required'],
            'video' => ['sometimes', 'boolean'],
        ]);

        $me = $this->me($request);
        $person = is_scalar($data['person']) ? (string) $data['person'] : '';
        $callee = ctype_digit($person) ? User::query()->find((int) $person) : null;

        if (! $callee instanceof User) {
            throw new HttpException(404);
        }

        $call = $this->calls->start($me, $callee, (bool) ($data['video'] ?? false));
        $call->setRelation('caller', $me)->setRelation('callee', $callee);

        return response()->json(['data' => $this->calls->joinPayload($call, $me)], 201);
    }

    /** POST /messenger/calls/{call}/answer → { data: { call_id, url, token, video, peer, room } } */
    public function answer(Request $request, string $call): JsonResponse
    {
        $me = $this->me($request);
        $row = $this->calls->answer($this->calls->forUser($me, $call), $me);

        return response()->json(['data' => $this->calls->joinPayload($row, $me)]);
    }

    /** POST /messenger/calls/{call}/decline → 204 */
    public function decline(Request $request, string $call): Response
    {
        $me = $this->me($request);
        $this->calls->decline($this->calls->forUser($me, $call), $me);

        return response()->noContent();
    }

    /** POST /messenger/calls/{call}/end → 204 */
    public function end(Request $request, string $call): Response
    {
        $me = $this->me($request);
        $this->calls->end($this->calls->forUser($me, $call), $me);

        return response()->noContent();
    }

    /**
     * GET /messenger/calls → { data: [call row] }, newest first, 50 — exactly as before while no history parameter is sent.
     *
     * With any of `kind` (missed | in | out), `person` (user id), `q` (the other side's name), `cursor` (the answer's `next`) or `limit` (1–100, 50 if not
     * said) → { data: [call row], next: string|null }, only my own calls.
     */
    public function log(Request $request): JsonResponse
    {
        if (! $request->hasAny(['kind', 'person', 'cursor', 'limit', 'q'])) {
            return response()->json(['data' => $this->calls->log($this->me($request))]);
        }

        $data = $request->validate([
            'kind' => ['sometimes', 'nullable', 'string', Rule::in(CallService::KINDS)],
            'person' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:40'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.CallService::PAGE_MAX],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->calls->history(
            $this->me($request),
            $data['kind'] ?? null,
            isset($data['person']) ? (int) $data['person'] : null,
            $data['cursor'] ?? null,
            (int) ($data['limit'] ?? CallService::LOG),
            trim((string) ($data['q'] ?? '')),
        ));
    }

    /** GET /messenger/calls/people[?person=] → { data: [{ peer, calls, answered, missed, seconds, last_at, last }] }, only my own calls */
    public function people(Request $request): JsonResponse
    {
        $data = $request->validate(['person' => ['sometimes', 'nullable', 'integer', 'min:1']]);

        return response()->json(['data' => $this->calls->people($this->me($request), isset($data['person']) ? (int) $data['person'] : null)]);
    }

    private function me(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new HttpException(401);
        }

        return $user;
    }
}
