<?php

declare(strict_types=1);

namespace App\Core\Integrity;

/**
 * একটা যাচাই যেখানে আটকেছে — একটা নির্দিষ্ট কাগজ, একটা নির্দিষ্ট অমিল।
 *
 * ── কেন সংখ্যা নয়, সারি ─────────────────────────────────────────────
 * "৩টা বিলে গরমিল" বললে কাজ এগোয় না — কোন তিনটা, সেটাই তো প্রশ্ন।
 * নিয়ম ১: প্রতিটা সংখ্যা তার উৎসে নিয়ে যায়। যাচাইয়ের ফল তার
 * ব্যতিক্রম নয়, বরং সবচেয়ে বেশি করে তারই দাবিদার — কারণ এখানে
 * সংখ্যাটা দেখার জন্য নয়, সারানোর জন্য।
 */
final class IntegrityFinding
{
    public function __construct(
        /** যেটা আটকেছে — বিলের নম্বর, খাতের নাম, পণ্যের নাম */
        public readonly string $what,

        /** কীভাবে আটকেছে — দুইটা সংখ্যা আর তাদের পার্থক্য */
        public readonly string $detail,

        /*
         * উৎসে যাওয়ার ঠিকানা — `x-ui.drill` এটুকুই চায়।
         *
         * ঐচ্ছিক, কারণ কিছু অমিলের কোনো একক কাগজ নেই (যেমন গোটা
         * খাতার Dr ও Cr-এর পার্থক্য)। তখন সারিটা কেবল বলে কী
         * মিলছে না, আর সেটাই যা বলার আছে।
         */
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
    ) {}

    public function isDrillable(): bool
    {
        return $this->sourceType !== null && $this->sourceId !== null;
    }
}
