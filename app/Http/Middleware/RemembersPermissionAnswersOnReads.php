<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Services\ReadWindow;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ Opens the [[ReadWindow]] for one GET/HEAD request and closes it when the page is built (speed work,
 * 23 Nov 2026) — inside it [[GateMemo]] and [[AccountLookups]] answer repeated questions once.
 * ⛔ A write (POST, PUT, PATCH, DELETE) never opens it, so a page that changes data always reads afresh.
 */
final class RemembersPermissionAnswersOnReads
{
    public function __construct(private readonly ReadWindow $window) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        $this->window->open($request);

        try {
            return $next($request);
        } finally {
            $this->window->close($request);
        }
    }
}
