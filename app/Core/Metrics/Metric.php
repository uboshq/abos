<?php

declare(strict_types=1);

namespace App\Core\Metrics;

use App\Core\Support\DocumentStatus;
use InvalidArgumentException;

/**
 * একটা সংখ্যার সংজ্ঞা — একটাই, আর লেখা।
 *
 * ── কেন এটা লাগল, ABOS-এর নিজের প্রমাণে ─────────────────────────────
 * "আজকের বিক্রয়" এই রিপোতেই চার জায়গায় হিসাব হত: ড্যাশবোর্ড, POS,
 * রিপোর্ট, আর শিফট। একবার তারা **দুইটা আলাদা উত্তর** দিয়েছিল —
 * `todaysTotal()` খসড়াও গুনত, তাই ধরে-রাখা একটা বিলের টাকা ক্যাশিয়ারের
 * শিফটে দেখা যেত, অথচ ড্যাশবোর্ড ওটা গুনত না। শিফট মেলানোর সময় হাতের
 * নগদ কম পড়ত আর কেউ বুঝত না কেন।
 *
 * ওই নির্দিষ্ট ভুলটা সারানো হয়েছে। কিন্তু **নিয়মটা পাঁচ জায়গায় হাতে
 * লেখা ছিল**, আর হাতে লেখা নিয়ম আবার আলাদা হয় — গতবার ঠিক তাই হয়েছে।
 *
 * ── সংজ্ঞায় কী কী থাকতে হয় ──────────────────────────────────────────
 * চারটা প্রশ্নের উত্তর, কারণ এই চারটাতেই দুইজন মানুষ দুই রকম ধরে নেয়:
 *
 *   ১. কোন status গোনা হয় — খসড়া? বাতিল?
 *   ২. কোন তারিখ — লেনদেনের, নাকি এন্ট্রি করার?
 *   ৩. দশমিক কয় ঘর
 *   ৪. রাউন্ডিং কোন ধাপে — প্রতিটা সারিতে, নাকি শেষে একবার
 *
 * ── কেন সংজ্ঞাটা পর্দায় দেখা যায় ────────────────────────────────────
 * যে সংখ্যার সংজ্ঞা লুকানো, দুইজন মানুষ তার দুই অর্থ করে — আর মিটিংয়ে
 * সেটা ধরা পড়ে না, ধরা পড়ে ছয় মাস পরে, যখন সিদ্ধান্তটা নেওয়া হয়ে গেছে।
 */
final class Metric
{
    /** লেনদেনের তারিখ — কাগজে যা লেখা, যেদিন ঘটনাটা ঘটেছে। */
    public const BY_TRANSACTION_DATE = 'trx_date';

    /** এন্ট্রির তারিখ — কবে ব্যবস্থায় তোলা হলো। */
    public const BY_ENTRY_DATE = 'created_at';

    /** সারি ধরে গোল করা — প্রতিটা লাইনে। */
    public const ROUND_PER_ROW = 'row';

    /** শেষে একবার — যোগফলে। */
    public const ROUND_AT_TOTAL = 'total';

    /**
     * @param  string  $key  `sales.today` — মডিউলের নাম দিয়ে শুরু, তাই দুই
     *                       মডিউলের দুইটা মেট্রিক কখনো ঠোকে না
     * @param  list<string>  $statuses  কোন অবস্থার কাগজ গোনা হয়
     * @param  callable():string  $value  সংখ্যাটা — bcmath স্ট্রিং
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $statuses,
        public readonly string $dateField,
        public readonly int $scale,
        public readonly string $rounding,
        public readonly string $permission,
        private $value,
    ) {
        if (! str_contains($key, '.')) {
            throw new InvalidArgumentException(
                "Metric key '{$key}' has no module prefix. Use 'sales.today', not 'today' — "
                .'two modules would otherwise collide on the same name.'
            );
        }

        if ($statuses === []) {
            throw new InvalidArgumentException(
                "Metric '{$key}' counts no statuses at all, so it can only ever be zero."
            );
        }

        foreach ($statuses as $status) {
            if (! in_array($status, DocumentStatus::ALL, true)) {
                throw new InvalidArgumentException("Metric '{$key}' counts unknown status '{$status}'.");
            }
        }

        if (! in_array($dateField, [self::BY_TRANSACTION_DATE, self::BY_ENTRY_DATE], true)) {
            throw new InvalidArgumentException(
                "Metric '{$key}' uses date field '{$dateField}'. A figure is either about when it "
                .'happened or when it was typed in, and the two differ on every back-dated entry.'
            );
        }

        if (! in_array($rounding, [self::ROUND_PER_ROW, self::ROUND_AT_TOTAL], true)) {
            throw new InvalidArgumentException("Metric '{$key}' has an unknown rounding step '{$rounding}'.");
        }
    }

    /** সংখ্যাটা। */
    public function value(): string
    {
        return ($this->value)();
    }

    /**
     * সংজ্ঞাটা মানুষের ভাষায় — সংখ্যার পাশে দেখানোর জন্য।
     *
     * ব্যাখ্যাটা এখানেই তৈরি হয়, প্রতিটা পর্দায় আলাদা করে লেখা হয় না —
     * নাহলে সংজ্ঞা এক জায়গায় আর তার বর্ণনা আরেক জায়গায় থাকত, আর
     * দুইটা আলাদা হয়ে যেত।
     */
    public function definition(): string
    {
        return __('core.metric.definition', [
            'statuses' => implode(', ', array_map(
                fn (string $s) => __('core.status.'.$s),
                $this->statuses,
            )),
            'date' => __('core.metric.'.($this->dateField === self::BY_TRANSACTION_DATE
                ? 'by_transaction_date'
                : 'by_entry_date')),
            'scale' => $this->scale,
            'rounding' => __('core.metric.'.($this->rounding === self::ROUND_PER_ROW
                ? 'round_per_row'
                : 'round_at_total')),
        ]);
    }
}
