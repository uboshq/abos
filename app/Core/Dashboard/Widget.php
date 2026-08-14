<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use InvalidArgumentException;

/**
 * হোম পর্দার একটা সংখ্যা।
 *
 * ── কেন প্রতিটা উইজেটে লিংক বাধ্যতামূলক ─────────────────────────────
 * নিয়ম ১: প্রতিটা সংখ্যা তার উৎসে নিয়ে যায়। "বকেয়া ৪,৩০,০০০" দেখে
 * ক্লিক করলে কোন গ্রাহকের কত তা দেখা যাবে — নাহলে ব্যবহারকারীকে সংখ্যাটা
 * বিশ্বাস করতে হয়, যাচাই করার উপায় থাকে না। আর যে সংখ্যা যাচাই করা যায়
 * না, ভুল হলেও কেউ ধরতে পারে না।
 *
 * এটা মন্তব্য নয়, নির্মাণেই আটকানো: লিংক ছাড়া উইজেট তৈরিই করা যায় না।
 */
final class Widget
{
    /**
     * তিনটা দল, আর তিনটাই আলাদা প্রশ্নের উত্তর।
     *
     * today — "আজ কী হলো"
     * month — "মাসটা কেমন যাচ্ছে"
     * todo  — "কী করা বাকি"
     *
     * শেষেরটাই আসলে ড্যাশবোর্ডের কাজ: খসড়া বিল, অপেক্ষমাণ অনুমোদন,
     * মেয়াদ পেরোনো বকেয়া — এগুলো কেউ ইচ্ছাকৃতভাবে ফেলে রাখে না, শুধু
     * ভুলে যায়।
     */
    public const GROUPS = ['today', 'month', 'todo'];

    /** সংখ্যার চরিত্র — রঙ ও গুরুত্ব এখান থেকেই আসে। */
    public const TONES = ['neutral', 'money', 'good', 'warn'];

    public function __construct(
        public readonly string $group,
        public readonly string $label,
        public readonly string $value,
        public readonly string $href,
        public readonly string $permission,
        public readonly string $tone = 'neutral',
        public readonly ?string $hint = null,
        public readonly int $sort = 50,

        /*
         * সেটের একটা আইকনের নাম (`x-ui.icon`)।
         *
         * করণীয়ের তালিকায় প্রতিটা সারি এখন একটা লাইন লেখা; আইকন ছাড়া
         * বিশটা সারি একই রকম দেখায়, আর কোনটা টাকার আর কোনটা মালের তা
         * পড়ে বের করতে হয়। ঐচ্ছিক: না দিলে সারিটা কেবল লেখাতেই চলে।
         */
        public readonly ?string $icon = null,

        /*
         * আগের সময়ের সাথে তুলনা — যেমন `+12.4%` বা `-4.1%`।
         *
         * ── কেন লেখা, সংখ্যা নয় ─────────────────────────────────────
         * "কিসের তুলনায়" প্রশ্নটার উত্তর মডিউলই জানে (গত বৃহস্পতিবার?
         * গত মাস?), আর সেটা `hint`-এ লেখা থাকে। এখানে কেবল চিহ্নটা।
         * উপরে না নিচে সেটা প্রথম অক্ষর দেখেই বোঝা যায়, তাই আলাদা
         * "দিক" পাঠানোর দরকার নেই।
         */
        public readonly ?string $delta = null,

        /*
         * একটা সংখ্যার ভেতরের ভাগ — `['নগদে' => '৳১,২০০', …]`।
         *
         * "হাতে ও ব্যাংকে মোট ৩,৬৩,৬৫৬" নিজে থেকে বলে না টাকাটা কোথায়।
         * ভাগটা পাশে থাকলে প্রশ্নটাই ওঠে না। শুধু প্রধান কার্ডে দেখানো
         * হয় — ছোট কার্ডে তিনটা ভাগ ধরে না।
         *
         * @var array<string, string>
         */
        public readonly array $parts = [],
    ) {
        if (! in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException(
                "Unknown dashboard group '{$group}'. Allowed: ".implode(', ', self::GROUPS).'.'
            );
        }

        if (! in_array($tone, self::TONES, true)) {
            throw new InvalidArgumentException(
                "Unknown widget tone '{$tone}'. Allowed: ".implode(', ', self::TONES).'.'
            );
        }

        if (trim($label) === '' || trim($value) === '') {
            throw new InvalidArgumentException(
                'A widget needs both a label and a value. An empty tile is a hole on the home screen.'
            );
        }

        if (trim($href) === '') {
            throw new InvalidArgumentException(
                "The widget '{$label}' has no link. Every figure must open the rows behind it (rule 1) — a number "
                .'nobody can check is a number nobody can correct.'
            );
        }

        if (trim($permission) === '') {
            throw new InvalidArgumentException(
                "The widget '{$label}' declares no permission. It would show today's sales to the delivery man."
            );
        }
    }
}
