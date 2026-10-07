<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * সদ্য যা হয়েছে — একটা ঘটনা।
 *
 * ── কেন এটা "যা করা বাকি"-র উল্টো দিক ────────────────────────────────
 * হোম পর্দার করণীয় তালিকা বলে **কী আটকে আছে**। কিন্তু দিনের শুরুতে
 * মালিকের প্রথম প্রশ্নটা সেটা নয় — প্রশ্নটা হলো *"আমি না থাকতে কী কী
 * হলো"*। আজ পর্যন্ত তার উত্তর পেতে হলে বিক্রয়, আদায়, ক্রয় আর নগদ
 * গণনার চারটা তালিকা আলাদা করে খুলতে হত, আর তারিখ ধরে ছাঁকতে হত।
 *
 * ── কেন প্রতিটা ঘটনা নিজের চাবি বলে ─────────────────────────────────
 * এই তালিকায় টাকার অঙ্ক থাকে। ডেলিভারিম্যানের পর্দায় "INV-0031 ·
 * ৳4,050" ভেসে উঠলে সেটা ফাঁস — অথচ পর্দাটা সবার। তাই ছাঁকাটা এক
 * জায়গায় (`ActivityRegistry`), আর প্রতিটা ঘটনা কেবল বলে দেয় কোন চাবি
 * লাগে।
 */
final class Happening
{
    public function __construct(
        /** কখন — সারিগুলো এটা ধরেই সাজানো হয় */
        public readonly Carbon $when,

        /** কী হয়েছে — "INV-0031 · ৳4,050" */
        public readonly string $title,

        /** কার সাথে, বা কোথায় — "রহিম এন্টারপ্রাইজ" */
        public readonly string $subtitle,

        /** সেটের একটা আইকনের নাম (`x-ui.icon`) */
        public readonly string $icon,

        public readonly string $permission,

        /**
         * সারিটার চরিত্র — রঙ এখান থেকেই।
         *
         * টাকা আসা আর টাকা যাওয়া দুইটা আলাদা ঘটনা, আর তালিকায় চোখ
         * বুলিয়ে সেটা আলাদা করতে পারা দরকার।
         */
        public readonly string $tone = 'neutral',

        /*
         * উৎসে যাওয়ার ঠিকানা — নিয়ম ১।
         *
         * "৳4,050 বিক্রয়" পড়ে মালিক জানতে চান কার কাছে, কী কী। লিংক
         * ছাড়া সারিটা কেবল একটা ঘোষণা, আর ঘোষণা যাচাই করা যায় না।
         */
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
    ) {
        if (! in_array($tone, Widget::TONES, true)) {
            throw new InvalidArgumentException(
                "Unknown activity tone '{$tone}'. Allowed: ".implode(', ', Widget::TONES).'.'
            );
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException(
                'An activity row needs a title. A blank line on the home screen is a hole.'
            );
        }

        if (trim($permission) === '') {
            throw new InvalidArgumentException(
                "The activity row '{$title}' declares no permission. This list carries money figures, "
                .'and the home screen is open to everybody.'
            );
        }
    }

    public function isDrillable(): bool
    {
        return $this->sourceType !== null && $this->sourceId !== null;
    }
}
