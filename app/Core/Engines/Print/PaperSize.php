<?php

declare(strict_types=1);

namespace App\Core\Engines\Print;

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

    /** @return list<string> */
    public static function all(): array
    {
        return [self::A4, self::THERMAL_80, self::THERMAL_58];
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
