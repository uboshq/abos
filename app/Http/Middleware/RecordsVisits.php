<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Engines\Assistant\AssistantEngine;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ⭐ কে কোন পাতা খুলল, কী নামাল — কোপাইলটের খাতায় (মালিক, ১১ নভেম্বর ২০২৬: "কে কী করতেছিল … কার আইডি থেকে কখন, কোথায়,
 * কীভাবে, কোন জায়গা থেকে, সব কিছু অডিটে থাকবে")।
 *
 * ⓘ লেখা হয়: লগইন করা মানুষের পাতা খোলা (GET, উত্তর ২০০, HTML) আর নামানো (PDF, Excel, CSV — ফাইল হিসেবে আসা উত্তর)।
 *   অ্যাপ থেকে: কাগজ খোলা আর নামানো (দরজার নাম `.show`, `.pdf`, `.print`, `.export`, `.download`-এ শেষ)।
 * ⛔ লেখা হয় না: পেছনের ছোট ডাক (JSON, খোঁজার ঘর — সেটা আলাদা লেখে, লাইভ-হালনাগাদ, সিঙ্ক) — নইলে খাতা গোলমালে ভরত।
 * ⓘ খাতায় লেখা না গেলেও পাতা খোলে — খাতার ভুল মানুষের কাজ আটকায় না।
 */
final class RecordsVisits
{
    /** ⓘ এই নামের দরজা কখনো নয় — নিজেই লেখে বা নিছক পেছনের ডাক */
    private const SKIP = ['search', 'live.', 'notifications.poll', 'assistant.audit', 'sync.', 'api.sync', 'heartbeat'];

    private const APP_ENDINGS = ['.show', '.pdf', '.print', '.export', '.download'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->maybeRecord($request, $response);
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }

    private function maybeRecord(Request $request, Response $response): void
    {
        $user = $request->user();
        $name = (string) ($request->route()?->getName() ?? '');

        if ($user === null || ! $request->isMethod('GET') || $response->getStatusCode() !== 200 || $name === '') {
            return;
        }

        foreach (self::SKIP as $skip) {
            if (str_starts_with($name, $skip)) {
                return;
            }
        }

        $type = (string) $response->headers->get('Content-Type', '');
        $file = $this->fileName($response);
        $isApp = $request->is('api/*');

        if ($isApp) {
            $wanted = collect(self::APP_ENDINGS)->contains(fn (string $end): bool => str_ends_with($name, $end));
        } else {
            $wanted = $file !== null || (str_contains($type, 'text/html') && ! $request->ajax() && ! $request->expectsJson());
        }

        if (! $wanted) {
            return;
        }

        $label = $file !== null ? __('assistant::audit.downloaded', ['file' => $file]) : $name;
        $query = $request->getQueryString();
        $path = '/'.ltrim($request->path(), '/').($query === null || $query === '' ? '' : '?'.$query);

        app(AssistantEngine::class)->recordVisit($user, $path, (string) $label);
    }

    private function fileName(Response $response): ?string
    {
        $disposition = (string) $response->headers->get('Content-Disposition', '');

        if ($disposition === '' || ! str_contains($disposition, 'filename')) {
            return null;
        }

        if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $disposition, $m) === 1) {
            return mb_substr(rawurldecode($m[1]), 0, 120);
        }

        return preg_match('/filename="?([^";]+)"?/i', $disposition, $m) === 1 ? mb_substr($m[1], 0, 120) : null;
    }
}
