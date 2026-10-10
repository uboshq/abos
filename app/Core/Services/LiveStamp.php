<?php

declare(strict_types=1);

namespace App\Core\Services;

use Illuminate\Support\Facades\Cache;

/**
 * ⭐ রিয়েল-টাইম সিঙ্ক, ওয়েবের দিক — মালিক, ১০ অক্টোবর ২০২৬, পথ (ক): কোম্পানিপ্রতি "শেষ কবে কিছু লেখা হলো"।
 *
 * প্রতিটা সফল লেখা সময়-চিহ্নটা নতুন করে ([[NudgePhonesAfterWrite]])। খোলা পাতা পাতার সাথে আসা চিহ্নটা মনে
 * রাখে আর প্রতি কুড়ি সেকেন্ডে জিজ্ঞেস করে ([[LiveController]]); বদলালে "নতুন তথ্য এসেছে"।
 *
 * ── ⛔ দরজাটা সেশন ছাড়া ─────────────────────────────────────────────────
 * জিজ্ঞাসা সেশন ছুঁলে খোলা-রাখা পাতা মানুষকে চিরকাল লগইন রাখত — নিষ্ক্রিয়তায় লগআউট আর হত না। তাই
 * চাবিটা পাতার ভেতরে, কোম্পানির নম্বর আর অ্যাপের গোপন চাবির সই ([[key()]])। উত্তরে কেবল একটা সংখ্যা —
 * কোনো কাগজ, টাকা বা নাম নয়।
 */
final class LiveStamp
{
    public function bump(int $companyId): void
    {
        try {
            Cache::forever('live-stamp:'.$companyId, (int) floor(microtime(true) * 1000));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function read(int $companyId): int
    {
        return (int) Cache::get('live-stamp:'.$companyId, 0);
    }

    /** পাতায় বসানো চাবি — `c{id}.{সই}`; সই ছাড়া অন্য কোম্পানির নম্বর বসিয়ে কিছু পাওয়া যায় না। */
    public function key(int $companyId): string
    {
        return 'c'.$companyId.'.'.$this->sign($companyId);
    }

    public function companyOf(string $key): ?int
    {
        if (preg_match('/^c(\d{1,10})\.([a-f0-9]{24})$/', $key, $m) !== 1) {
            return null;
        }

        $id = (int) $m[1];

        return hash_equals($this->sign($id), $m[2]) ? $id : null;
    }

    private function sign(int $companyId): string
    {
        return substr(hash_hmac('sha256', 'live-stamp|'.$companyId, (string) config('app.key')), 0, 24);
    }
}
