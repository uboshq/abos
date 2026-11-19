<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Models\User;

/**
 * ⭐ হোমের গোটা-ব্যবসার বোর্ড — মালিকের অনুমোদিত নমুনা, ১৯ নভেম্বর ২০২৬ (claude.ai artifact AAjAfjZ8TnCfnjtt3Kthjz v11; ছবি ২৩):
 * গ্রাফ আগে (৮টা), তারপর ৮টা ঘর, প্রিন্সিপালকে দেনা · কমিশন · পয়েন্ট ধরে বকেয়া, সব প্রতিষ্ঠানের টেবিল, আজ যা করতে হবে।
 *
 * ⓘ কোর কোনো মডিউল চেনে না — বিক্রি, বকেয়া, মজুদ, টাকা জানে মডিউলরা, আর সব মিলিয়ে বলে Executive ([[HomeSalesFilters]]-এর
 * ধাঁচে: কোর প্রশ্নটা রাখে, মডিউল উত্তর দেয়)। Executive বন্ধ থাকলে [[NoHomeBoard]] — কিছুই নেই, হোম আগের অংশগুলো নিয়েই থাকে।
 *
 * ⛔ প্রতিটা সংখ্যা নিজের চাবির পেছনে (খরচ বা লাভ কেবল খরচের চাবিতে), শাখার দেয়াল মেনে, কেবল পাকা কাগজ গুনে; উত্তরদাতা
 * সেটা নিজে দেখে — কোর অঙ্ক বানায় না।
 */
interface HomeBoard
{
    /**
     * হোমের বাছা সময়ে (`today` · `month` · `year`) — ছাঁকনি ([[HomeFilter]]) চালু থাকলে তার ভিতরে ডাকা হয়।
     *
     * @return array{
     *     charts: list<array<string, mixed>>,
     *     cards: list<array<string, mixed>>,
     *     lists: list<array<string, mixed>>,
     *     companies: ?array<string, mixed>,
     *     todo: list<array<string, mixed>>,
     * }
     */
    public function for(User $user, string $period): array;
}
