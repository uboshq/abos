<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

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

        if (! in_array($type, [self::TEXT, self::MONEY, self::QUANTITY, self::DATE, self::DOCUMENT], true)) {
            throw new InvalidArgumentException("Report column '{$definition['key']}' has unknown type '{$type}'.");
        }

        return new self(
            key: $definition['key'],
            label: $definition['label'],
            type: $type,
            // টাকা ও পরিমাণ ডিফল্টে যোগ হয়; তারিখ বা লেখা নয়। একটা রিপোর্টে
            // "মোট" সারিতে তারিখের যোগফল দেখানোর কোনো মানে হয় না।
            total: $definition['total'] ?? in_array($type, [self::MONEY, self::QUANTITY], true),
            width: $definition['width'] ?? null,
            sourceTypeKey: $definition['source_type'] ?? null,
            sourceIdKey: $definition['source_id'] ?? null,
            permission: $definition['permission'] ?? null,
        );
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
        return in_array($this->type, [self::MONEY, self::QUANTITY], true);
    }

    /** এই কলামের মান ক্লিক করলে উৎস ডকুমেন্টে যাবে — নিয়ম ১। */
    public function isDrillable(): bool
    {
        return $this->sourceTypeKey !== null && $this->sourceIdKey !== null;
    }

    public function decimals(): int
    {
        return match ($this->type) {
            self::MONEY => 2,
            self::QUANTITY => 3,
            default => 0,
        };
    }
}
