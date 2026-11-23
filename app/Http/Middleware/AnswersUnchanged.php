<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Support\UnchangedAnswer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ A phone door that answers 304 when nothing changed (speed work, 23 Nov 2026) — the approvals inbox, the
 * net-worth card, the notifications, the notices bar and the messenger's threads.
 *
 * ⓘ The phone polls these doors; most polls find the same answer. The body is still built, but a matching
 * `If-None-Match` gets an empty 304 instead of the full JSON — less data on the phone's network, less to parse.
 * ⛔ The tag is the weak, scoped tag of [[UnchangedAnswer]] — user, company and branch are hashed in, so a tag never
 * answers for another user. ⛔ Always `no-store, private`. A door that already set its own ETag is left alone.
 */
final class AnswersUnchanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200 || ! $response instanceof JsonResponse
            || $response->headers->has('ETag')) {
            return $response;
        }

        $etag = UnchangedAnswer::tag($request, (string) $response->getContent());

        if (UnchangedAnswer::matches($request, $etag)) {
            return UnchangedAnswer::notModified($etag);
        }

        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', UnchangedAnswer::NO_STORE);

        return $response;
    }
}
