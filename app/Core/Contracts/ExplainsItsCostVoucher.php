<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ যে কাগজের খরচ (গাড়ি ভাড়া, লোডিং) একটা ভাউচারে বসে, সে ভাউচার খুললে নিজের বিস্তারিত দেখায় — মালিক, ২১ নভেম্বর ২০২৬
 * (নমুনা v6, ক: *"আরও বিস্তারিত (ভাড়া, মাধ্যম, ড্রাইভারের মোবাইল, সব চালান) — ভাউচার খুললে"*)।
 *
 * ⓘ হিসাব মডিউল বিক্রয়ের কোনো ক্লাস চেনে না (BoundariesTest) — ভাউচারের পাতা কাগজটা মূল ড্রিল-রেজিস্ট্রি দিয়ে খোঁজে
 * ([[DrillResolver]]), আর কাগজ এই চুক্তি মানলে সারিগুলো যেমন আছে তেমন আঁকে। লেবেল আর লেখা কাগজের মালিক মডিউলের।
 */
interface ExplainsItsCostVoucher
{
    /**
     * সারিগুলো ক্রমে; খালি ঘর বাদ দিয়ে। `links` থাকলে মান লেখার বদলে লিংকগুলো বসে (যেমন সব চালান)।
     *
     * @return array{title: string, rows: list<array{key: string, label: string, value: string, numeric?: bool, links?: list<array{label: string, url: ?string}>}>}
     */
    public function costVoucherFacts(): array;
}
