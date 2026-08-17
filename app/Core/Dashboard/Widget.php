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
     * year  — "বছরটা কেমন যাচ্ছে"
     * todo  — "কী করা বাকি"
     *
     * শেষেরটাই আসলে ড্যাশবোর্ডের কাজ: খসড়া বিল, অপেক্ষমাণ অনুমোদন,
     * মেয়াদ পেরোনো বকেয়া — এগুলো কেউ ইচ্ছাকৃতভাবে ফেলে রাখে না, শুধু
     * ভুলে যায়।
     */
    public const GROUPS = ['today', 'month', 'year', 'todo'];

    /**
     * যে তিনটা দলের মধ্যে পর্দার উপরের সারিটা বদলায়।
     *
     * করণীয় এর বাইরে: ওটা কোনো কালপর্ব নয়, ওটা একটা তালিকা — আর
     * "এই বছরের করণীয়" বলে কিছু নেই।
     */
    public const PERIODS = ['today', 'month', 'year'];

    /** সংখ্যার চরিত্র — রঙ ও গুরুত্ব এখান থেকেই আসে। */
    public const TONES = ['neutral', 'money', 'good', 'warn'];

    public function __construct(
        public readonly string $group,
        public readonly string $label,
        public readonly string $value,
        public readonly string $href,
        public readonly string $permission,
        public readonly string $tone = 'neutral',
        /*
         * তুলনাটা কিসের সাথে — "গত বৃহস্পতিবারের তুলনায়"।
         *
         * ছোট, আর `delta`-র ঠিক পাশে বসে। "↑ ১২.৪%" নিজে কিছু বলে না;
         * কিসের তুলনায় সেটা না থাকলে সংখ্যাটা পড়াই যায় না।
         */
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

        /*
         * সাত দিনের রেখা — কার্ডের নিচে।
         *
         * ── কেন সংখ্যাটার পাশে একটা রেখা ────────────────────────────
         * "আজ ৪,০৫০" একটা বিন্দু, আর একটা বিন্দু দিয়ে কোনো দিক বোঝা
         * যায় না। শতাংশের তুলনাটা (`delta`) একটা দিক দেয়, কিন্তু
         * সেটাও দুইটা বিন্দুর। রেখাটা বলে দেয় গতকালের লাফটা অস্বাভাবিক
         * নাকি রোজকার — আর সেটা কোনো সংখ্যাতেই থাকে না।
         *
         * ঐচ্ছিক: যে সংখ্যার ইতিহাস নেই (যেমন "কী করা বাকি") তার রেখাও
         * নেই, আর জোর করে সমতল একটা রেখা আঁকা মানে মিথ্যা বলা।
         *
         * @var list<int|float|string>
         */
        public readonly array $spark = [],

        /*
         * সংখ্যাটা কীভাবে গোনা হয় — পুরো বাক্যটা।
         *
         * ── কেন `hint` থেকে আলাদা ───────────────────────────────────
         * দুইটা আলাদা কাজ এক ঘরে ছিল, আর তাতে একটা আরেকটাকে সরিয়ে
         * দিচ্ছিল: সংজ্ঞাটা লম্বা (চারটা প্রশ্নের উত্তর), তুলনার
         * প্রসঙ্গটা ছোট (চার শব্দ)। একই ঘরে রাখায় কার্ডে সংজ্ঞাটা
         * বসত আর "কিসের তুলনায়" কথাটা কোথাও যেত না।
         *
         * এটা টুলটিপে যায়, `hint` কার্ডের গায়ে — দুইটাই থাকে, কেউ
         * কাউকে ঠেলে সরায় না।
         */
        public readonly ?string $definition = null,
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
