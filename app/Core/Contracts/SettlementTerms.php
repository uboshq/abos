<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * যে কাগজের "বিপরীতে" একটা ভাউচার লেখা যায়, সে নিজেই বলে কোন ভাউচার তাকে মেটাতে পারে — গ২, Accounts-Finance অডিট,
 * ৪ অক্টোবর ২০২৬।
 *
 * ⛔ আগে ভাউচারের লুকানো "কোন কাগজের বিপরীতে" ঘরে যা লেখা হত, সফটওয়্যার তা-ই মানত — অঙ্ক, পক্ষ, ধরন কিছুই দেখত না।
 * ১ টাকার রসিদে ৫ লাখের মূলধন "এসেছে" হত, সইয়ের অপেক্ষায় থাকা উত্তোলন "পরিশোধিত" হত, আর এক গ্রাহকের টাকায়
 * আরেক গ্রাহকের বিল শোধ দেখাত।
 *
 * ⓘ Accounts কারও উপর দাঁড়ায় না ([[BoundariesTest]]), তাই কাগজের ধরন সে চেনে না — চেনে কেবল এই চুক্তি। যাচাইটা
 * একবারই লেখা: [[VoucherService::assertAgainstFits()]]।
 */
interface SettlementTerms
{
    /**
     * @return array{
     *     voucher_type: string,
     *     amount: string,
     *     up_to?: bool,
     *     party_type?: string|null,
     *     party_id?: int|null,
     *     party_required?: bool,
     *     open: bool,
     * }
     *
     * voucher_type — `receipt` (টাকা আসে) বা `payment` (টাকা যায়)।
     * amount — কাগজের অঙ্ক; `up_to` সত্য হলে এটা সর্বোচ্চ (বিলের বাকি — আংশিক আদায় চলে), নাহলে হুবহু।
     * party_type / party_id — কার টাকা; null মানে কাগজে পক্ষ নেই, তাই মেলানোর কিছু নেই;
     *   ভাউচারে পক্ষ থাকলে মিলতেই হবে, আর `party_required` সত্য হলে ভাউচারে পক্ষ থাকতেই হবে।
     * open — এখনো মেটানো যায় কি না: খসড়া, বাতিল নয়, সইয়ের অপেক্ষায় নয়, আগে মেটানো নয়।
     */
    public function settlementTerms(): array;
}
