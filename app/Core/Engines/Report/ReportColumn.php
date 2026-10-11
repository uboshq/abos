<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use App\Core\Support\Money;
use InvalidArgumentException;

/**
 * একটা রিপোর্টের একটা কলাম।
 *
 * টেবিল কম্পোনেন্টের মতোই লেবেল বাধ্যতামূলক — মোবাইলে হেডার লুকিয়ে যায়,
 * আর লেবেলটাই একমাত্র জিনিস যা বলে মানটা কীসের।
 */
final class ReportColumn
{
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const QUANTITY = 'quantity';

    public const DATE = 'date';

    public const DOCUMENT = 'document';

    /** খাতার জের, চিহ্ন ছাড়া — "(Dr) 250.79" / "(Cr) 22,958.21" ([[\App\Core\Support\Money::drCr()]]; মালিক, ৩ অক্টোবর ২০২৬) */
    public const DR_CR = 'dr_cr';

    /*
     * শতাংশ — অবদান ও পরিবর্তন।
     *
     * টাকা নয় বলে আলাদা: শতাংশের যোগফল হয় না (তিনটা সারির ৪০% + ৩০%
     * + ৩০% = ১০০%, আর সেটা "মোট" সারিতে বসালে অর্থহীন), আর খালি মান
     * মানে শূন্য নয় — মানে "আগের সময়ে জিনিসটাই ছিল না"।
     */
    public const PERCENT = 'percent';

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $total,
        public readonly ?string $width,
        /** ড্রিল-ডাউনের জন্য: কোন কলামে source_type ও source_id আছে */
        public readonly ?string $sourceTypeKey,
        public readonly ?string $sourceIdKey,

        /**
         * এই কলামটা দেখতে যে অনুমতি লাগে — null মানে সবার জন্য।
         *
         * ── কেন কলামে, রিপোর্টে নয় ──────────────────────────────────
         * "ক্রেতা ধরে বিক্রয়" রিপোর্টটা বিক্রয়কর্মীর দরকার — কে কত
         * কিনছে সেটা তাঁর রোজকার কাজ। কিন্তু ওই একই রিপোর্টে মুনাফার
         * কলামটা তাঁর দেখার কথা নয়। পুরো রিপোর্ট আটকালে হয় তাঁর কাজ
         * বন্ধ, নয় মুনাফা ফাঁস — দুইটার কোনোটাই চলে না।
         *
         * তাই আড়ালটা কলাম ধরে: সারিগুলো তিনি দেখেন, ওই একটা ঘর নয়।
         */
        public readonly ?string $permission = null,

        /*
         * ⭐ জেরের কথা — চিহ্নের বদলে দুইটা শব্দ (প্রিন্সিপালের কমিশন, মালিক, ৫ অক্টোবর ২০২৬)।
         * ⓘ কেবল [[self::DR_CR]]-এ: `[ধনাত্মক হলে, ঋণাত্মক হলে]` — দুইটা অনুবাদের চাবি, প্রতিটায় `:amount`, যেমন
         * "দিতে হবে ৳:amount" / "কোম্পানির কাছে পাব ৳:amount"। না দিলে আগের মতো "(Dr) 250.79" / "(Cr) 22,958.21"।
         *
         * @var array{0: string, 1: string}|null
         */
        public readonly ?array $words = null,
    ) {}

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition, int $index): self
    {
        if (! isset($definition['key'])) {
            throw new InvalidArgumentException("Report column {$index} has no 'key'.");
        }

        if (! isset($definition['label'])) {
            throw new InvalidArgumentException(
                "Report column {$index} ('{$definition['key']}') has no label. On a phone the header is "
                .'hidden, so the label is the only thing telling the reader what a value is.'
            );
        }

        $type = $definition['type'] ?? self::TEXT;

        if (! in_array($type, [self::TEXT, self::MONEY, self::QUANTITY, self::DATE, self::DOCUMENT, self::PERCENT, self::DR_CR], true)) {
            throw new InvalidArgumentException("Report column '{$definition['key']}' has unknown type '{$type}'.");
        }

        return new self(
            key: $definition['key'],
            label: $definition['label'],
            type: $type,
            // টাকা ও পরিমাণ ডিফল্টে যোগ হয়; তারিখ বা লেখা নয়। একটা রিপোর্টে
            // "মোট" সারিতে তারিখের যোগফল দেখানোর কোনো মানে হয় না।
            // ⛔ হার, দাম, দিন আর স্তরও নয় — [[self::isNotASum()]]
            total: $definition['total'] ?? (in_array($type, [self::MONEY, self::QUANTITY], true) && ! self::isNotASum((string) $definition['key'])),
            width: $definition['width'] ?? null,
            sourceTypeKey: $definition['source_type'] ?? null,
            sourceIdKey: $definition['source_id'] ?? null,
            permission: $definition['permission'] ?? null,
            words: isset($definition['words']) ? [(string) $definition['words'][0], (string) $definition['words'][1]] : null,
        );
    }

    /**
     * ⛔ টাকা বা পরিমাণের ঘর, অথচ যোগ করলে অর্থহীন — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
     *
     * ⚠️ "মোট" সারি এতদিন প্রতিটা টাকা/পরিমাণের ঘর যোগ করত: দশটা পণ্যের **একক দাম**
     * (`rate`, `mrp`, `sale_price`), দশটা অনুমোদনের **গড় দিন** (`avg_days`), পুনঃঅর্ডারের
     * **স্তর** (`reorder_level`) — সবই যোগ হয়ে একটা বিশ্বাসযোগ্য দেখতে, কিন্তু মিথ্যা সংখ্যা।
     * ⭐ এখন ঘোষণায় `total` না থাকলে এই নামগুলো খালি থাকে; গড় দরকার হলে রিপোর্টের
     * নিজের সারাংশ (`summary`) মোট থেকে সেটা আবার গোনে, যেমন মূলধনের আয়ের হার।
     *
     * ⓘ নাম ধরে, কারণ এগুলো ১৫টা মডিউলের ঘোষণায় ছড়ানো; ঘোষণায় `'total' => true`
     * লিখলে নিয়মটা ভাঙা যায় — জেনেবুঝে।
     */
    public static function isNotASum(string $key): bool
    {
        return preg_match('/(^|_)(rate|price|mrp|avg|days|level)(_|$)/', $key) === 1;
    }

    /**
     * জেরের লেখা, খালি বিয়োগ ছাড়া — পর্দা, মোট, ছাপা, PDF আর ফাইল সবাই এটাই ডাকে ([[self::DR_CR]])।
     *
     * ⓘ শব্দ ঘোষণা থাকলে "দিতে হবে ৳১২,৩৪৫.০০", না থাকলে [[Money::drCr()]]-এর "(Dr) 250.79"; শূন্যে কেবল অঙ্ক।
     */
    public function signed(mixed $value): string
    {
        if ($this->words === null) {
            return Money::drCr($value, $this->decimals());
        }

        $rounded = Money::round($value, $this->decimals());
        $amount = Money::format(ltrim($rounded, '-'), $this->decimals());

        return match (bccomp($rounded, '0', $this->decimals())) {
            1 => (string) __($this->words[0], ['amount' => $amount]),
            -1 => (string) __($this->words[1], ['amount' => $amount]),
            default => $amount,
        };
    }

    /**
     * এই ব্যবহারকারী কলামটা দেখতে পাবেন কি না।
     *
     * লগইন ছাড়া কেউ রিপোর্ট দেখে না, কিন্তু null এলে **আড়াল করাই**
     * নিরাপদ দিক: অনুমতি যাচাই করার মতো কেউ না থাকলে সংখ্যাটা
     * দেখানোর কোনো কারণ নেই।
     */
    public function visibleTo(mixed $user): bool
    {
        if ($this->permission === null) {
            return true;
        }

        return $user !== null && $user->can($this->permission);
    }

    public function isNumeric(): bool
    {
        return in_array($this->type, [self::MONEY, self::QUANTITY, self::PERCENT], true);
    }

    /** এই কলামের মান ক্লিক করলে উৎস ডকুমেন্টে যাবে — নিয়ম ১। */
    public function isDrillable(): bool
    {
        return $this->sourceTypeKey !== null && $this->sourceIdKey !== null;
    }

    public function decimals(): int
    {
        return match ($this->type) {
            self::MONEY, self::DR_CR => 2,
            self::QUANTITY => 3,

            // শতাংশে দুই ঘরই যথেষ্ট — ৪০.১২% আর ৪০.১২৩৪% একই সিদ্ধান্তে
            // নিয়ে যায়, আর দ্বিতীয়টা সারিটাকে চওড়া করে
            self::PERCENT => 2,

            default => 0,
        };
    }
}
