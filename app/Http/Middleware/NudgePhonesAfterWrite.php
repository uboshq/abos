<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Services\SyncNudge;
use App\Core\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ লেখার পরে ফোনকে ডাক — রিয়েল-টাইম সিঙ্ক, মালিক, ১০ অক্টোবর ২০২৬ ([[SyncNudge]])।
 *
 * ⓘ কেন মডেলের ঘটনা নয়, অনুরোধ: খাতা, মজুদ আর অনেক সারি query builder দিয়ে লেখা হয়, মডেলের ঘটনা
 * সেগুলো দেখে না। ⭐ যে অনুরোধ কিছু লিখল (GET নয়) আর সফল হল, তার কোম্পানিতে কিছু বদলেছে — এটুকুই
 * নিশ্চিত, আর এটুকুই লাগে।
 *
 * ⓘ ব্যর্থ (৪০০+) অনুরোধে কিছু লেখা হয়নি, তাই ডাক নয়। কোম্পানির প্রসঙ্গ না থাকলে (লগইন, পাসওয়ার্ড) নয়।
 */
final class NudgePhonesAfterWrite
{
    public function __construct(private readonly SyncNudge $nudge) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
            && $response->getStatusCode() < 400
            && ($company = CompanyContext::id()) !== null) {
            $this->nudge->touch($company);
        }

        return $response;
    }
}
