<?php

declare(strict_types=1);

namespace App\Core\Panels;

/**
 * এক টুকরো তথ্য, যেটা অন্য মডিউল একটা রেকর্ড সম্পর্কে জানে।
 *
 * "শেষ কেনা: ১২ জুলাই" — গ্রাহকের পাতায় বসে, কিন্তু কথাটা বিক্রয়ের।
 * তাই এটা একটা সারি নয়, একটা **অবদান**: যে মডিউল জানে সে দেয়, যে পাতা
 * দেখায় সে কার কাছ থেকে এল তা জানে না।
 */
final class Fact
{
    /**
     * @param  string  $label  অনুবাদের চাবি — কাঁচা লেখা নয়, দুই ভাষাই লাগে
     * @param  string|null  $value  দেখানোর মতো লেখা; খালি হলে সারিটা বসে না
     * @param  string|null  $url  সংখ্যাটা কোথা থেকে এল — নিয়ম ১, প্রতিটা
     *                            অঙ্ক তার উৎসে নিয়ে যায়
     */
    public function __construct(
        public readonly string $label,
        public readonly ?string $value,
        public readonly ?string $url = null,
        public readonly int $sort = 100,
    ) {}
}
