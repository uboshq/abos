<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * প্যাকেটের গায়ের ২ডি বারকোড পড়া।
 *
 * ── এটাই ঠিক করে ব্যাচ ধরে রাখার কোনো মানে আছে কি না ─────────────────
 * ব্যাচ, মেয়াদ আর FEFO — তিনটাই আছে যাতে আগে-মেয়াদ-শেষ পাতাটা আগে
 * বেরোয়। কিন্তু কাউন্টারে লাইন থাকলে যে ক্যাশিয়ারকে ড্রপডাউন থেকে লট
 * বাছতে হয়, তিনি প্রতিবার প্রথমটাই বাছেন — আর তখন মজুদ লট ধরে হিসাব
 * হয়, বিক্রয় হয় না, আর পুরনো পাতাগুলো তাকেই মেয়াদ পার করে।
 *
 * প্যাকেটটা উত্তরটা নিজেই বহন করে। ওষুধের কার্টনের GS1 DataMatrix-এ
 * পণ্য, লট আর মেয়াদ একসাথে থাকে, তাই **একটা স্ক্যানেই ওগুলো বসে যায়**।
 *
 * ── GS1 element string দেখতে যেমন ───────────────────────────────────
 *
 *     ]d2 01 08901234567890 17 261231 10 ABC123
 *
 *     (01) GTIN     — কোন পণ্য, ১৪ অঙ্ক, নির্দিষ্ট দৈর্ঘ্য
 *     (17) মেয়াদ    — YYMMDD, নির্দিষ্ট দৈর্ঘ্য
 *     (10) লট       — ২০ অক্ষর পর্যন্ত, **পরিবর্তনশীল** দৈর্ঘ্য
 *     (21) সিরিয়াল  — ২০ অক্ষর পর্যন্ত, পরিবর্তনশীল
 *
 * নির্দিষ্ট দৈর্ঘ্যের ঘর সোজা পরেরটার সাথে লেগে যায়। পরিবর্তনশীলগুলো
 * পারে না, তাই ওদের শেষে FNC1 বসে — স্ক্যানার যেটা ASCII ২৯ (GS) হিসেবে
 * পাঠায়। যে স্ক্যানারে ওই অক্ষরটা সেট করা নেই তার কাছ থেকে লট নম্বরটা
 * তার পরের সবকিছু গিলে ফেলে — নিচে সেটা সামলানো আছে, কারণ মাঠে ওটাই
 * সবচেয়ে সাধারণ ভুল সেটিং আর ব্যর্থতাটা নীরব: লট নম্বরটা কেবল ভুল হয়,
 * আর কিছুই ভাঙা দেখায় না।
 */
class PackBarcode
{
    /** ASCII ২৯ — সিম্বলজিতে FNC1, পাঠানো ডেটায় GS। */
    public const SEPARATOR = "\x1d";

    /**
     * যে Application Identifier-গুলো পড়া হয়, আর তাদের মানের দৈর্ঘ্য।
     *
     * কেবল সেগুলোই যা ফার্মেসির প্যাকেটে সত্যিই থাকে। দুইশো সারির একটা
     * AI টেবিল রাখলে সেটা এমন একটা তালিকা হত যা কেউ হালনাগাদ করত না।
     */
    private const FIXED = [
        '01' => 14,   // GTIN
        '17' => 6,    // মেয়াদ, YYMMDD
        '11' => 6,    // উৎপাদনের তারিখ
        '15' => 6,    // best before
    ];

    private const VARIABLE = [
        '10' => 20,   // লট
        '21' => 20,   // সিরিয়াল
    ];

    /**
     * স্ক্যান করা লেখাটা পড়ে পণ্য, লট ও মেয়াদ।
     *
     * @return array{gtin: ?string, batch_no: ?string, expiry_date: ?Carbon, serial_no: ?string, is_gs1: bool}
     *
     * @throws ValidationException লেখাটা GS1 বলে দাবি করে অথচ নিজের সাথে না মিললে
     */
    public function read(string $scanned): array
    {
        $empty = [
            'gtin' => null, 'batch_no' => null, 'expiry_date' => null,
            'serial_no' => null, 'is_gs1' => false,
        ];

        $data = preg_replace('/^\](?:d2|C1|e0|Q3|d1)/', '', trim($scanned)) ?? '';

        if ($data === '') {
            return $empty;
        }

        /*
         * সাধারণ EAN-13 বা EAN-8 GS1 হিসেবে পড়া হয় না।
         *
         * ফার্মেসির অ-ওষুধ মালের প্রায় সবটারই একটা আছে। GS1 ধরে পড়লে
         * তার প্রথম দুই অঙ্ক একটা AI হয়ে যেত আর উত্তরটা হত আত্মবিশ্বাসী
         * ও ভুল — যেটা কোনো উত্তর না পাওয়ার চেয়ে খারাপ, কারণ কিছুই
         * ভাঙা দেখায় না।
         */
        if (ctype_digit($data) && in_array(strlen($data), [8, 12, 13], true)) {
            return $empty;
        }

        $fields = [];
        $at = 0;
        $length = strlen($data);

        while ($at < $length) {
            if ($data[$at] === self::SEPARATOR) {
                $at++;

                continue;
            }

            [$ai, $size] = $this->identifierAt($data, $at);
            $at += strlen($ai);

            if ($size !== null) {
                $value = substr($data, $at, $size);

                if (strlen($value) < $size) {
                    throw ValidationException::withMessages([
                        'barcode' => __('inventory::validation.barcode_truncated', ['ai' => $ai]),
                    ]);
                }

                $at += $size;
            } else {
                /*
                 * পরিবর্তনশীল দৈর্ঘ্য: পরের FNC1 পর্যন্ত, নয়তো শেষ পর্যন্ত।
                 *
                 * স্ক্যানার FNC1 না পাঠালে এটা বাকি পুরোটা নেয় — যেটা
                 * ভুল, আর **ইচ্ছাকৃতভাবেই দৃশ্যমান ভুল**: অপারেটর দেখেন
                 * লট নম্বরের শেষে মেয়াদটা লেগে আছে, আর বোঝেন স্ক্যানার
                 * সেট করতে হবে। বিশ অক্ষরে কেটে দিলে একটা বিশ্বাসযোগ্য
                 * লট নম্বর তৈরি হত যেটা বাক্সের গায়েরটা নয়।
                 */
                $end = strpos($data, self::SEPARATOR, $at);
                $end = $end === false ? $length : $end;
                $value = substr($data, $at, $end - $at);
                $at = $end;
            }

            $fields[$ai] = $value;
        }

        return [
            'gtin' => $fields['01'] ?? null,
            'batch_no' => ($fields['10'] ?? '') !== '' ? $fields['10'] : null,
            'serial_no' => ($fields['21'] ?? '') !== '' ? $fields['21'] : null,
            'expiry_date' => isset($fields['17']) ? $this->date($fields['17']) : null,
            'is_gs1' => isset($fields['01']) || isset($fields['10']),
        ];
    }

    /**
     * এখান থেকে শুরু হওয়া AI, আর তার মানের দৈর্ঘ্য।
     *
     * AI দুই থেকে চার অঙ্কের, আর নিজে থেকে আলাদা হয় না — তাই যেগুলো
     * সত্যিই বোঝা যায় তাদের সাথে লম্বা-আগে মিলিয়ে দেখা হয়। অচেনা AI-র
     * দৈর্ঘ্য আন্দাজ করলে ভুল সংখ্যক অক্ষর খেয়ে ফেলত আর তার পরের সবটা
     * নষ্ট হত, তাই অচেনা হলে থেমে যাওয়াই ঠিক।
     *
     * @return array{0: string, 1: ?int}
     */
    private function identifierAt(string $data, int $at): array
    {
        foreach ([4, 3, 2] as $size) {
            $candidate = substr($data, $at, $size);

            if (isset(self::FIXED[$candidate])) {
                return [$candidate, self::FIXED[$candidate]];
            }

            if (isset(self::VARIABLE[$candidate])) {
                return [$candidate, null];
            }
        }

        throw ValidationException::withMessages([
            'barcode' => __('inventory::validation.barcode_unknown_part', [
                'part' => substr($data, $at, 4),
            ]),
        ]);
    }

    /**
     * YYMMDD — আর দুইটা নিয়ম, যে কারণে এটা `strptime` নয়।
     *
     * **DD শূন্য হতে পারে**, আর GS1-এ তার মানে "ওই মাসের শেষ দিন" —
     * ভুল নয়, আর প্রথম দিনও নয়। ২৬১২০০ লেখা প্যাকেট ৩১ ডিসেম্বর
     * পর্যন্ত ভালো। ১ তারিখ ধরলে এক মাসের বেচার মতো ওষুধ কাউন্টারে
     * ফিরিয়ে দেওয়া হত; ভুল ধরলে প্যাকেটটাই বেচা যেত না।
     *
     * **YY-তে শতক নেই।** GS1-এর নিয়ম পঞ্চাশ বছরের জানালা: ৫১–৯৯ মানে
     * ১৯০০-এর দশক, ০০–৫০ মানে ২০০০-এর। "২০" + YY লিখলে ২০৫১ পর্যন্ত
     * চলত, তারপর প্রতিটা মেয়াদ নব্বই বছর আগের হয়ে যেত — অর্থাৎ সব
     * প্যাকেট একসাথে মেয়াদোত্তীর্ণ।
     */
    private function date(string $yymmdd): Carbon
    {
        if (strlen($yymmdd) !== 6 || ! ctype_digit($yymmdd)) {
            throw ValidationException::withMessages([
                'barcode' => __('inventory::validation.barcode_bad_date', ['date' => $yymmdd]),
            ]);
        }

        $yy = (int) substr($yymmdd, 0, 2);
        $mm = (int) substr($yymmdd, 2, 2);
        $dd = (int) substr($yymmdd, 4, 2);

        if ($mm < 1 || $mm > 12) {
            throw ValidationException::withMessages([
                'barcode' => __('inventory::validation.barcode_bad_date', ['date' => $yymmdd]),
            ]);
        }

        $year = $yy <= 50 ? 2000 + $yy : 1900 + $yy;
        $last = (int) Carbon::create($year, $mm, 1)->endOfMonth()->format('d');

        if ($dd === 0) {
            $dd = $last;
        } elseif ($dd > $last) {
            throw ValidationException::withMessages([
                'barcode' => __('inventory::validation.barcode_bad_date', ['date' => $yymmdd]),
            ]);
        }

        return Carbon::create($year, $mm, $dd)->startOfDay();
    }
}
