<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ পক্ষের সই-পাওয়া ব্যাংক হিসাব, পুরো — ব্যাংক ফাইলের (BEFTN) সারির জন্য (নকশার "যা পরের ধাপে": "ব্যাংক ফাইল"; cb-ma-4, ২৫ অক্টোবর ২০২৬)।
 *
 * ⓘ [[PayeeBankView]] পর্দার জন্য — নাম আর শেষ চার অঙ্ক; এটা ব্যাংকে পাঠানোর জন্য — পুরো হিসাব নম্বর আর রাউটিং। ⛔ কেবল দুজনের সইয়ে
 * যাচাই-করা তথ্য: সই-না-পাওয়া বা অপেক্ষার বদল কখনো ফাইলে যায় না (জালিয়াতি ঠেকানো, SAP vendor master dual control)।
 * ⓘ ব্যাংক তথ্য পক্ষের মালিক মডিউলের (সরবরাহকারী); মডিউল বন্ধ থাকলে কোরের [[\App\Core\Services\NoPayeeBankAccount]] — `null`।
 */
interface PayeeBankAccount
{
    /**
     * @return array{bank: string, branch: string, account_name: string, account_no: string, routing_no: string}|null
     */
    public function accountOf(string $partyType, int $partyId): ?array;
}
