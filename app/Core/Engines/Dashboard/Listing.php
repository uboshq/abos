<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * ছোট একটা তালিকা — "এই নয়টা পণ্য ফুরিয়ে আসছে"।
 *
 * ── কেন ড্যাশবোর্ডে তালিকাও থাকে ─────────────────────────────────────
 * সংখ্যা বলে **কতটা**, তালিকা বলে **কোনটা**। "৯টা পণ্য সীমার নিচে"
 * দেখে পরের প্রশ্ন সবসময় "কোন নয়টা", আর তার জন্য আরেকটা পর্দায় গেলে
 * প্রশ্নটা প্রায়ই আর করাই হয় না।
 *
 * ── কেন `href` লাগে ──────────────────────────────────────────────────
 * এখানে কয়েকটা সারিই দেখানো হয়, তাই **পুরোটা কোথায়** সেটা বলতেই হবে।
 * না বললে মানুষ ধরে নিতেন এগুলোই সব।
 */
final class Listing
{
    /**
     * @param  list<array{key: string, label: string, render: callable, width?: string}>  $columns
     * @param  Collection<int, mixed>  $rows
     */
    public function __construct(
        public readonly string $label,
        public readonly array $columns,
        public readonly Collection $rows,
        public readonly string $empty,
        public readonly ?string $href = null,
        /*
         * শিরোনামের নিচে ছোট এক লাইন — যেমন "সময়কাল: 26/09/2026 – আজ পর্যন্ত"। ঐচ্ছিক।
         */
        public readonly ?string $note = null,
        /*
         * ⭐ হোমের "হাতে ও ব্যাংকে মোট" বাক্সের রূপে — গাঢ় সবুজাভ, পুরো সারি জুড়ে ([[x-dashboard.hero-listing]])।
         * মালিক, ৬ অক্টোবর ২০২৬: প্রিন্সিপালের কমিশনের জন্য *"main dashboard-এর মতো একটা same box"*।
         */
        public readonly bool $hero = false,
        /*
         * ⭐ নিচে "সর্বমোট"-এর সারি — কলামের চাবি → দেখানোর লেখা, সার্ভারে হিসাব করা (মালিক, ১৯ অক্টোবর ২০২৬: প্রিন্সিপালের
         * কমিশনের বাক্সে মোট নেই)। ⓘ লেখা, অঙ্ক নয় — যোগফল bcmath-এ আর রূপ (লাখের কমা, "দিতে হবে") তালিকার মালিক ঠিক করে;
         * পাতা কিছু গোনে না। যে কলামের চাবি নেই সেখানে ফাঁকা।
         *
         * @var array<string, string>|null
         */
        public readonly ?array $total = null,
        /*
         * ⭐ হোমের কার্ডে চার্টের বদলে এই তালিকা — প্রথম কলাম আর শেষ কলাম, ছোট করে (মালিকের অনুমোদিত নমুনা, ২০ অক্টোবর ২০২৬:
         * অনুমোদনের কার্ডে "কাগজ · পক্ষ" আর অবস্থার চিপ)। ⓘ মডিউল একটাই চিহ্নিত করে; না করলে হোমে আগের মতো প্রথম চার্ট।
         */
        public readonly bool $onHome = false,
    ) {
        if ($columns === []) {
            throw new InvalidArgumentException("Listing '{$label}' has no columns.");
        }

        if (trim($empty) === '') {
            throw new InvalidArgumentException(
                "Listing '{$label}' has no empty message. An empty box with no words reads as a broken screen."
            );
        }
    }
}
