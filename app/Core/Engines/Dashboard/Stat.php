<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use InvalidArgumentException;

/**
 * একটা সংখ্যা, আর তার পাশে কী বোঝায়।
 *
 * ── কেন `hint` বাধ্যতামূলক ───────────────────────────────────────────
 * "বকেয়া ১১,১৪,৭৩০" — কোন তারিখ পর্যন্ত? খসড়া ধরে? বাতিলগুলো বাদ?
 * সংখ্যাটা একা দাঁড়ালে প্রতিটা পাঠক নিজের মতো ধরে নেন, আর দুইজন দুই
 * সিদ্ধান্ত নেন একই পর্দা দেখে।
 *
 * তাই নির্মাণেই আটকানো: ব্যাখ্যা ছাড়া একটা সংখ্যা এই পর্দায় বসানো
 * **যায় না**। লিখতে বাধ্য হলে লেখকও একবার ভাবেন সংখ্যাটা আসলে কী।
 *
 * ── কেন `href` প্রায় সবসময় থাকা উচিত ─────────────────────────────────
 * মালিকের স্থায়ী নিয়ম: প্রতিটা সংখ্যা তার উৎসে নামতে দেয়। "ফুরিয়ে
 * আসছে ৯" দেখে পরের প্রশ্নটা সবসময় "কোন নয়টা"। তবু বাধ্যতামূলক নয় —
 * কিছু সংখ্যার (যেমন মজুদের মোট মূল্য) নামার মতো কোনো তালিকা নেই।
 */
final class Stat
{
    public const NEUTRAL = 'neutral';

    public const GOOD = 'good';

    public const WARN = 'warn';

    public const BAD = 'bad';

    /** অনুমতি না থাকলে সংখ্যার বদলে যা বসে। */
    public const HIDDEN = '••••';

    public function __construct(
        public readonly string $label,
        public readonly ?string $value,
        public readonly string $hint,
        public readonly ?string $href = null,
        public readonly string $tone = self::NEUTRAL,
        /**
         * সংখ্যাটা দেখার জন্য আলাদা কোনো চাবি লাগে কি না।
         *
         * খরচ ও দরের সংখ্যাগুলোয় লাগে ([[FieldSecurity]] একই কাজ ঘরের
         * স্তরে করে)। না দিলে মডিউলের পর্দা যাঁর কাছে খোলে, সংখ্যাটাও
         * তাঁর।
         */
        public readonly ?string $permission = null,

        /**
         * আগের বারের সংখ্যাটা — তুলনা দেখানোর জন্য।
         *
         * ── কেন তুলনা ছাড়া সংখ্যা অসম্পূর্ণ ──────────────────────────
         * "আজকের বিক্রয় ৪,৮২,৬০০" — বেশি না কম? যিনি রোজ দেখেন তিনি
         * মাথায় তুলনা করেন, আর যিনি মাসে একবার দেখেন তিনি পারেন না।
         * পাশে গতকালের সংখ্যাটা থাকলে দুইজনেই একই কথা পড়েন।
         *
         * `null` মানে তুলনার মতো কিছু নেই — মজুদের মোট মূল্যের
         * "গতকাল" হয় না, ওটা একটা অবস্থা, ঘটনা নয়।
         */
        public readonly ?string $previous = null,

        /** তুলনাটা কীসের সাথে — "গতকালের চেয়ে", "গত মাসের চেয়ে"। */
        public readonly ?string $previousLabel = null,
    ) {
        if (trim($label) === '') {
            throw new InvalidArgumentException('A stat with no label is a number nobody can read.');
        }

        if (trim($hint) === '') {
            throw new InvalidArgumentException(
                "Stat '{$label}' has no hint. A figure without its definition gets read two ways by two people."
            );
        }

        if (! in_array($tone, [self::NEUTRAL, self::GOOD, self::WARN, self::BAD], true)) {
            throw new InvalidArgumentException("Stat '{$label}' has an unknown tone '{$tone}'.");
        }
    }

    /**
     * আগের বারের চেয়ে কত শতাংশ বদলেছে।
     *
     * ── কেন শূন্য থেকে বাড়াকে "শতাংশ" বলা হয় না ─────────────────────
     * গতকাল ০, আজ ৫ — এটা "অসীম শতাংশ বৃদ্ধি" নয়, এটা "গতকাল কিছু
     * ছিল না"। শতাংশ দেখালে সংখ্যাটা হাস্যকর হত, আর পরেরবার কেউ
     * তুলনাটাই বিশ্বাস করতেন না।
     */
    public function change(): ?float
    {
        if ($this->previous === null || $this->value === null) {
            return null;
        }

        $was = (float) $this->previous;

        if ($was == 0.0) {
            return null;
        }

        return ((float) $this->value - $was) / abs($was) * 100;
    }

    /**
     * অনুমতি ছাড়া দেখা সংস্করণ।
     *
     * ⚠️ শূন্য নয়, **ঢাকা** — আর পার্থক্যটা এখানেই সব। শূন্য দেখালে
     * কেউ ভাবতেন গুদাম খালি বা বিক্রি হয়নি, আর সেই ভুল তথ্য নিয়ে
     * সিদ্ধান্ত নিতেন। `••••` বলে "এটা আছে, আপনার দেখার কথা নয়"।
     */
    public function masked(): self
    {
        return new self(
            label: $this->label,
            value: self::HIDDEN,
            hint: $this->hint,
            href: null,
            tone: self::NEUTRAL,
            permission: $this->permission,
            previous: null,
            previousLabel: null,
        );
    }
}
