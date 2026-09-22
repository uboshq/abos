<?php

declare(strict_types=1);

namespace App\Core\Engines\Print;

use App\Core\Support\Money;
use InvalidArgumentException;

/**
 * কাগজের মাপ — সেকশন ২ ও প্ল্যানের প্রিন্টিং প্রয়োজনীয়তা।
 *
 * তিনটাই: A4, ৮০mm ও ৫৮mm থার্মাল। বাকি সব মাপ কেউ চায়নি, আর প্রতিটা
 * বাড়তি মাপ মানে প্রতিটা ডকুমেন্ট আরও একবার চোখে দেখে যাচাই করা।
 *
 * থার্মালে প্রস্থ স্থির কিন্তু উচ্চতা নয় — রোল কাগজ যত লাগে তত কাটে।
 * mPDF-কে একটা উচ্চতা দিতেই হয়, তাই যথেষ্ট বড় একটা দিয়ে শেষে ছেঁটে
 * নেওয়া হয়; নাহলে দুই লাইনের রসিদও পুরো পাতা কাগজ খেয়ে ফেলত।
 */
final class PaperSize
{
    public const A4 = 'a4';

    public const THERMAL_80 = '80mm';

    public const THERMAL_58 = '58mm';

    private function __construct(
        public readonly string $name,
        /** @var string|array{0: float, 1: float} */
        public readonly string|array $format,
        public readonly float $margin,
        public readonly float $fontSize,
        public readonly bool $isThermal,
    ) {}

    public static function of(string $name): self
    {
        return match ($name) {
            self::A4 => new self(self::A4, 'A4', 12, 10, false),

            // থার্মাল প্রিন্টারে ছাপার প্রস্থ কাগজের চেয়ে কম — ৮০mm রোলে
            // ৭২mm, ৫৮mm রোলে ৪৮mm। পুরো প্রস্থ ধরে নিলে ডান দিকের লেখা
            // কেটে যায়, আর সেটা টাকার অঙ্কে ঘটলে রসিদটাই অকেজো।
            self::THERMAL_80 => new self(self::THERMAL_80, [80, 3000], 3, 8.5, true),
            self::THERMAL_58 => new self(self::THERMAL_58, [58, 3000], 2, 7.5, true),

            default => throw new InvalidArgumentException(
                "Unknown paper size '{$name}'. Use a4, 80mm or 58mm."
            ),
        };
    }

    /**
     * এই কাগজে টাকাটা যেভাবে ছাপা হবে।
     *
     * ── ⭐ থার্মালে পয়সা নেই — মালিক, ২২ সেপ্টেম্বর ২০২৬ ───
     * *"ok bad daw rounding kore nilei holo"*।
     *
     * ⓘ কারণটা জায়গার: ৫৪smm কাগজে দুই পাশে ২mm মার্জিন বাদ
     * দিলে থাকে ৫৪mm। ⛔ কলামগুলো নিত ৬ + ১৩ + ১৭ + ২১ = **৫৭mm**
     * — অর্থাৎ পণ্যের নামের জন্য কিছুই থাকত না।
     *
     * ⚠️ `.00` বাদ দিলে প্রতিটা টাকার ঘর প্রায় ৫৮০mm কম লাগে,
     * আর সেই জায়গাটাই নামে যায়।
     *
     * ── ⛔ যা হারায়, আর সেটা জেনেই বসানো ──────────────────
     * রাউন্ড করা সারিগুলো যোগ করলে রাউন্ড করা মোটের সাথে এক-দুই
     * টাকার ফারাক হতে পারে। ⓘ খাতায় অংকটা পয়সাসহ অক্ষত থাকে —
     * এটা কেবল **ছাপার** রূপ, হিসাবের নয়।
     *
     * ⚠️ A4-তে কিছুই বদলায় না — ওখানে জায়গার সমস্যা নেই।
     */
    public function decimals(): int
    {
        return $this->isThermal ? 0 : 2;
    }

    /**
     * আগেই সাজানো একটা অংককে এই কাগজের রূপ দেওয়া।
     *
     * ⓘ কন্ট্রোলারগুলো টাকা সাজিয়েই পাঠায়, আর কাগজটা ঠিক হয়
     * তার পরে। ⭐ তাই বদলটা এখানেই — চারটা কন্ট্রোলারে নয়।
     *
     * ⚠️ সংখ্যা না হলে (যেমন "—") যেমন আছে তেমনই ফেরত যায়।
     */
    public function money(?string $formatted): string
    {
        $formatted = (string) $formatted;

        if (! $this->isThermal || $formatted === '') {
            return $formatted;
        }

        /* দলের কমা তুলে নিলে পড়ার মতো সংখ্যা থাকে */
        $bare = str_replace(',', '', $formatted);

        if (! is_numeric($bare)) {
            return $formatted;
        }

        return Money::format($bare, 0);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::A4, self::THERMAL_80, self::THERMAL_58];
    }

    /**
     * কোন কাগজে ছাপা হবে — মালিকের বসানো মাপ, যন্ত্রের আন্দাজ নয়।
     *
     * ── ⛔ আগে যা হত, আর কেন সেটা ভুল ───────────────────────────────
     * সাতটা প্রিন্ট কন্ট্রোলারের প্রতিটায় এক লাইন লেখা ছিল:
     * `$request->query('paper', PaperSize::A4)`। ⓘ অর্থাৎ ঠিকানায় মাপ না
     * থাকলে **সিস্টেম নিজেই A4 ধরে নিত**, আর কেউ কোথাও সেটা বদলাতে
     * পারত না। ⚠️ মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬: *"কি কাগজে প্রিন্ট
     * করবো এটা নিজে নির্ধারণ করে দিব, একটা অটো-নির্ধারিত হচ্ছে —
     * এটা যাতে না হয়"*।
     *
     * ⭐ এখন ক্রমটা: ঠিকানায় যা চাওয়া হয়েছে → না থাকলে মালিকের বসানো
     * মাপ (প্রতিটা কাগজের নিজের সেটিং) → তাও না থাকলে A4।
     *
     * ⓘ ঠিকানার মাপটা আগে, কারণ ছাপার মেনুতে তিনটা মাপই দেখা যায় —
     * কেউ একবার অন্য মেশিনে পাঠাতে চাইলে সেটিং বদলাতে হবে না।
     * ⚠️ অচেনা মাপ এলে ৪০৪ নয়: পুরনো বুকমার্ক বা হাতে বদলানো ঠিকানার
     * জন্য কাগজটা না ছাপার কোনো কারণ নেই — বসানো মাপেই ছাপে।
     */
    public static function chosen(?string $requested, ?string $configured): string
    {
        foreach ([$requested, $configured] as $candidate) {
            if (is_string($candidate) && in_array($candidate, self::all(), true)) {
                return $candidate;
            }
        }

        return self::A4;
    }

    /** থার্মালে কলাম কম — ৫৮mm-এ পাঁচটা কলাম ধরে না, আর ধরালে পড়া যায় না। */
    public function maxColumns(): int
    {
        return match ($this->name) {
            self::THERMAL_58 => 3,
            self::THERMAL_80 => 4,
            default => 8,
        };
    }

    public function label(): string
    {
        return __('core.print.paper.'.str_replace('mm', '_mm', $this->name));
    }
}
