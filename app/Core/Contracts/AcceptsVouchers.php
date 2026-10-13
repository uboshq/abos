<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * যে কাগজের বিপরীতে টাকার ভাউচার লেখা যায়, সে এখনো ভাউচার নেয় কি না — অর্থের নিরীক্ষা (fe, ১৪ অক্টোবর ২০২৬;
 * [[AClosedFacilityTakesNoVoucherTest]])।
 *
 * ⓘ [[SettlementTerms]]-এর চেয়ে হালকা: অঙ্ক বা ভাউচারের ধরন বাঁধে না, কেবল দরজা খোলা কি না। ব্যাংক সুবিধা বন্ধ হলে তার বিপরীতে আর
 * টাকা তোলা বা জমা নয় — অথচ সুবিধার নিজের খাত (২২১১/২২১২/২১৭০) সব ঋণের ভাগের, তাই খাত বন্ধ করা যায় না। প্রশ্নটা কাগজকেই করা হয়।
 * ⓘ Accounts কারও উপর দাঁড়ায় না, তাই কাগজের ধরন চেনে না — চেনে কেবল এই চুক্তি ([[VoucherService::assertAgainstFits()]])।
 */
interface AcceptsVouchers
{
    /** চালু আছে, তাই নতুন ভাউচার নেয় */
    public function acceptsVouchers(): bool;
}
