<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * "কোন তারিখ থেকে কোন তারিখ পর্যন্ত" — এক জায়গায়, সব ডকুমেন্ট তালিকার জন্য।
 *
 * ── কেন এটা দরকার হলো ───────────────────────────────────────────────
 * ভাউচারের তালিকায় তারিখের ছাঁকনি ছিল, বিল-চালান-আদায়ের তালিকায় ছিল না।
 * ফলে "আজ কত বিক্রি হলো" দেখতে হলে পুরো তালিকাটা খুলে চোখে খুঁজতে হত,
 * আর হোম পর্দার সংখ্যাটা ক্লিক করে আজকের সারিগুলোতে নামা যেত না —
 * অথচ প্রতিটা সংখ্যা তার উৎসে নিয়ে যাওয়ার কথা (নিয়ম ১)।
 *
 * ── কেন ভুল তারিখে পাতা ভাঙে না ─────────────────────────────────────
 * বাছাইয়ের মতোই: পুরনো বুকমার্ক, হাতে বদলানো ঠিকানা, বা অন্য ভাষার
 * তারিখ — এগুলোতে ৫০০ দেখানোর কোনো কারণ নেই। যা পড়া যায় না সেটা
 * ছাঁকনি হিসেবে ধরা হয় না, আর তালিকাটা এমনভাবে আসে যেন ছাঁকনিটা
 * দেওয়াই হয়নি।
 */
trait FiltersByDate
{
    /**
     * তারিখের পরিসর বসিয়ে দেয়, আর কোনটা চলছে তা ফেরত দেয়।
     *
     * @return array{from: ?string, to: ?string}
     */
    protected function applyDateRange(Builder $query, Request $request, string $column = 'trx_date'): array
    {
        $from = $this->readDate($request->query('from'));
        $to = $this->readDate($request->query('to'));

        /*
         * উল্টো পরিসর সোজা করে নেওয়া।
         *
         * কেউ শুরুতে ৩১ তারিখ আর শেষে ১ তারিখ দিলে কোয়েরিটা কখনো কিছু
         * ফেরাত না, আর পর্দায় "কোনো সারি নেই" দেখে মানুষ ভাবত মাসটায়
         * সত্যিই কিছু হয়নি।
         */
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($from !== null) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate($column, '<=', $to);
        }

        return ['from' => $from, 'to' => $to];
    }

    /** পড়া গেলে Y-m-d, নাহলে null। */
    private function readDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->toDateString();
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
