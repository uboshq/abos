<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ বাকির দেয়াল — বিক্রয়ের বিল যে দেয়াল মানে, স্টক ছাড়া বিক্রয় ভাউচারও (SV) সেটাই মানে (মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬)।
 *
 * ⓘ দেয়ালটা বিক্রয়ের (CreditExposure); Accounts কেবল এই চুক্তি চেনে।
 * বিক্রয় বন্ধ থাকলে কোরের [[\App\Core\Services\NoCreditWall]] — দেয়াল নেই, কারণ সীমার হিসাবই নেই।
 * ⛔ বাকির সীমা পরম (মালিক, ১ অক্টোবর ২০২৬) — এখানে কোনো ছাড়ের দরজা নেই।
 */
interface CreditWall
{
    /**
     * নতুন বাকি সীমার ভিতরে কি না — না হলে ValidationException।
     *
     * @param  bool  $locked  লেনদেনের ভিতরে, গ্রাহকের সারিতে তালা দিয়ে
     */
    public function assertRoom(int $customerId, string $adding, Model $paper, bool $locked = false): void;
}
