<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Contracts\Drillable;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Engines\Search\SearchEngine;
use App\Core\Services\PartyRegistry;

/**
 * লেখা থেকে তথ্য — বিল নম্বর, তারিখ, অঙ্ক, আর সরবরাহকারী বা গ্রাহক (পরিকল্পনা §৭ "OCR Result", §৮ "Extract
 * Data"; পঞ্চম ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কোনো মডেল নয়, কোনো বাইরের সার্ভার নয় ───────────────────────────
 * Document Intelligence (ABE) মানে নিয়ম আর প্যাটার্ন — মালিকের প্রথম বাঁধন। এখানে কেবল নিয়মিত রাশি
 * (regex), আর পক্ষের নাম মেলানো হয় ABOS-এ আগে থেকে থাকা গ্রাহক-সরবরাহকারীর নামের সাথে
 * ([[PartyRegistry]])। ⓘ ফল কেবল প্রস্তাব — মানুষ দেখে ঠিক করেন, তারপর রাখা হয়।
 *
 * ⓘ বাংলা অঙ্ক (০-৯) আগে ইংরেজি অঙ্কে আনা হয়, যাতে "মোট: ১২,৫০০" আর "Total: 12,500" একই নিয়মে পড়ে।
 */
final class DocumentFieldExtractor
{
    /** @var list<string> যে চারটা তথ্য পর্দায় আসে, এই ক্রমে */
    public const FIELDS = ['invoice_no', 'date', 'party', 'amount'];

    private const BENGALI_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    public function __construct(
        private readonly PartyRegistry $parties,
        private readonly DrillResolver $drill,
        private readonly SearchEngine $search,
    ) {}

    /**
     * @return array{invoice_no: ?string, date: ?string, party: ?string, amount: ?string}
     */
    public function extract(string $text): array
    {
        $plain = self::englishDigits($text);

        return [
            'invoice_no' => $this->invoiceNo($plain),
            'date' => $this->date($plain),
            'party' => $this->party($text),
            'amount' => $this->amount($plain),
        ];
    }

    public static function englishDigits(string $text): string
    {
        return str_replace(self::BENGALI_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $text);
    }

    private function invoiceNo(string $text): ?string
    {
        $pattern = '/(?:invoice|inv|bill|challan|memo|বিল|চালান|ইনভয়েস|মেমো)\s*(?:no\.?|number|#|নং|নম্বর|নাম্বার)?\s*[:#.\-]?\s*([A-Za-z]{0,6}[\-\/]?\d[A-Za-z0-9\-\/]{0,30})/iu';

        return preg_match($pattern, $text, $m) === 1 ? trim($m[1], '-/') : null;
    }

    /** ⓘ বাংলাদেশের ক্রমে — দিন/মাস/বছর; নয়তো বছর-মাস-দিন। অসম্ভব তারিখ বাদ। */
    private function date(string $text): ?string
    {
        if (preg_match('/\b(\d{4})[\-\/.](\d{1,2})[\-\/.](\d{1,2})\b/u', $text, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        if (preg_match_all('/\b(\d{1,2})[\-\/.](\d{1,2})[\-\/.](\d{2,4})\b/u', $text, $all, PREG_SET_ORDER) > 0) {
            foreach ($all as $m) {
                $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];

                if (checkdate((int) $m[2], (int) $m[1], $year)) {
                    return sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]);
                }
            }
        }

        return null;
    }

    /**
     * অঙ্ক — "মোট/সর্বমোট/Total/Grand Total/Net payable"-এর পাশের সংখ্যা; কয়েকটা থাকলে সবচেয়ে বড়টা
     * (সাধারণত সর্বমোট)। ⓘ কমা বাদ, দুই ঘর দশমিকে।
     */
    private function amount(string $text): ?string
    {
        $pattern = '/(?:grand\s*total|net\s*(?:total|payable|amount)|total(?:\s*amount)?|amount\s*payable|সর্বমোট|মোট|প্রদেয়)\s*[:=\-]?\s*(?:tk\.?|taka|bdt|৳|টাকা)?\s*([\d][\d,]*(?:\.\d{1,2})?)/iu';

        if (preg_match_all($pattern, $text, $all) < 1) {
            return null;
        }

        $best = null;

        foreach ($all[1] as $raw) {
            $value = str_replace(',', '', $raw);

            if (is_numeric($value) && ($best === null || bccomp($value, $best, 2) > 0)) {
                $best = $value;
            }
        }

        return $best === null ? null : bcadd($best, '0', 2);
    }

    /**
     * পক্ষ — ABOS-এ থাকা যে গ্রাহক বা সরবরাহকারীর নাম (বাংলা বা ইংরেজি) লেখায় আছে; কয়েকটা মিললে সবচেয়ে লম্বা নামটা।
     *
     * ⓘ পক্ষের ধরন আর তাদের ঘর ABOS-এর নিজের তালিকা থেকে ([[PartyRegistry]], [[DrillResolver]], [[SearchEngine]]) —
     * কোনো মডিউলের ক্লাস এখানে নেই। ⓘ ফল পর্দার ভাষায় ([[Drillable::drillLabel()]])।
     */
    private function party(string $text): ?string
    {
        $haystack = mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $best = null;
        $user = auth()->user();
        $user = $user instanceof \App\Models\User ? $user : null;

        foreach ($this->parties->types() as $type) {
            $class = $this->drill->map()[$type] ?? null;
            $columns = $class === null ? [] : array_values(array_intersect(['name_en', 'name_bn'], $this->search->sources()[$class]['columns'] ?? []));

            if ($columns === []) {
                continue;
            }

            foreach ($class::query()->limit(5000)->get() as $row) {
                /*
                 * ⛔ যে পক্ষকে দেখার অধিকার নেই, তার নাম ফেরে না — [[DocumentLinks::candidates()]]-এর একই পাহারা (১১ অক্টোবর ২০২৬,
                 * documents রিভিউ ⚠️৮)। ⓘ আগে যেকোনো লেখা পাঠালে দেয়ালের বাইরের ডিলারের নামও মিলিয়ে ফেরত দিত — নামের তালিকা পেস্ট করে
                 * জানা যেত কোন ডিলার আছে।
                 */
                if ($user === null || ! $this->search->maySee($user, $row)) {
                    continue;
                }

                foreach ($columns as $column) {
                    $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $row->getAttribute($column)) ?? ''));

                    if (mb_strlen($name) >= 4 && str_contains($haystack, $name)
                        && ($best === null || mb_strlen($name) > $best['length'])) {
                        $best = ['length' => mb_strlen($name), 'label' => $row instanceof Drillable ? $row->drillLabel() : $name];
                    }
                }
            }
        }

        return $best['label'] ?? null;
    }
}
