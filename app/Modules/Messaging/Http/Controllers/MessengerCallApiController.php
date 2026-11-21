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

    /** GET /messenger/calls → { data: [call row] }, newest first, 50 */
    public function log(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->calls->log($this->me($request))]);
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
