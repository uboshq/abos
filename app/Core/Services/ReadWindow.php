<?php

declare(strict_types=1);

namespace App\Core\Services;

use Illuminate\Http\Request;

/**
 * ⭐ "A read-only page is being built" — the only window in which per-request memories may answer (speed work,
 * 23 Nov 2026).
 *
 * ⓘ Opened by [[RemembersPermissionAnswersOnReads]] for a GET/HEAD request and closed when the response is built.
 * ⛔ Outside it — a form post, a console command, a queue job, test code between two requests — [[generation()]] is
 * null, every memory that asks it stays silent, and the code reads the database exactly as before. That is what keeps
 * a test that changes data with a mass update (no model events) from ever reading a remembered answer.
 *
 * ⓘ Each window has its own number: a memory keeps the number it was filled in and starts empty in the next window,
 * so nothing remembered ever crosses from one request to another.
 */
final class ReadWindow
{
    private ?Request $request = null;

    private int $counter = 0;

    public function open(Request $request): void
    {
        $this->request = $request;
        $this->counter++;
    }

    public function close(Request $request): void
    {
        if ($this->request === $request) {
            $this->request = null;
            $this->counter++;
        }
    }

    /** the open window's number, or null when no read-only page is being built right now */
    public function generation(): ?int
    {
        if ($this->request === null || ! $this->request->isMethodSafe()) {
            return null;
        }

        // ⛔ only the request that opened it — a sub-request or a swapped request object reads the database
        if (! app()->bound('request') || app('request') !== $this->request) {
            return null;
        }

        return $this->counter;
    }

    /** a write inside the window (a model event) empties every memory by moving to a fresh number */
    public function invalidate(): void
    {
        $this->counter++;
    }

    /** @var array<string, mixed> */
    private array $memo = [];

    private ?int $memoGeneration = null;

    /**
     * A small answer remembered for the open window only — outside it `$load` runs every time.
     *
     * ⛔ The key must name everything the answer depends on (company, branch, user …); the window only guarantees that
     * nothing was written since.
     *
     * @template T
     *
     * @param  \Closure(): T  $load
     * @return T
     */
    public function remember(string $key, \Closure $load): mixed
    {
        $generation = $this->generation();

        if ($generation === null) {
            return $load();
        }

        if ($generation !== $this->memoGeneration) {
            $this->memoGeneration = $generation;
            $this->memo = [];
        }

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $load();
        }

        return $this->memo[$key];
    }
}
