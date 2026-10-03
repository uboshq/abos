<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Engines\Search\StartingPoints;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ একটা কাগজের পাতা খোলা হলে মনে রাখা — Ctrl+K-এর "সাম্প্রতিক কাগজ"-এর জন্য।
 *
 * ⓘ ডিজাইন-চেকলিস্ট, ধাপ ৭ · ৫ (২ অক্টোবর ২০২৬)। কোন পাতা "কাগজ", সেটা
 * এই ফাইল জানে না — মডিউলের ঘোষণা থেকে [[StartingPoints::remember()]] বলে।
 *
 * ── ⛔ মনে রাখা ব্যর্থ হলে পাতা ব্যর্থ নয় ───────────────────────────────
 * ⚠️ এটা একটা সুবিধা, কাগজের অংশ নয়। ⓘ সারি বসাতে কিছু ভাঙলে (টেবিল
 * এখনো নেই, ডাটাবেজ ব্যস্ত) মানুষ তাঁর বিলটা ঠিকই দেখবেন — ভুলটা কেবল
 * লগে যায়।
 */
final class RemembersOpenedPapers
{
    public function __construct(private readonly StartingPoints $papers) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->papers->remember($request, $response);
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }
}
