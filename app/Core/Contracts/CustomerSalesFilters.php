<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * গ্রাহকের তালিকার বিক্রি-নির্ভর ছাঁকনি — মালিক, ১ অক্টোবর ২০২৬ (টপ/বটম, ভালো কাস্টমার)।
 *
 * ⓘ গ্রাহক মডিউল বিক্রয়কে চেনে না (`depends_on`-এ নেই), অথচ "কে কত কিনেছে" আর "কার বিল মেয়াদ পেরিয়ে পড়ে আছে"
 * কেবল বিক্রয়ই জানে। ⓘ তাই [[CustomerTrade]]-এর ধাঁচে: কোর প্রশ্নটা রাখে, বিক্রয় উত্তর দেয়; বিক্রয় বন্ধ থাকলে
 * [[NoCustomerSalesFilters]] — কেউ কেনেনি, কারও বিল পড়ে নেই।
 *
 * প্রতিটা উত্তর `customers.id` ধরে জোড়া — তালিকার কোয়েরির ভেতরে বসে, সারিপ্রতি আলাদা কোয়েরি নয়।
 */
interface CustomerSalesFilters
{
    /** এক গ্রাহকের পাকা বিলের মোট, দুই তারিখের মধ্যে, হেডারে বাছা শাখায় — `null` মানে বিক্রয় নেই */
    public function salesTotal(string $from, string $to): ?Builder;

    /** যাঁরা দুই তারিখের মধ্যে অন্তত একটা পাকা বিল পেয়েছেন */
    public function boughtBetween(Builder $customers, string $from, string $to): Builder;

    /** যাঁদের কোনো পাকা বিল নিজের বাকির দিন (খালি হলে ৩০) পেরিয়ে অপরিশোধিত পড়ে নেই */
    public function noOverdueBill(Builder $customers): Builder;

    /*
     * ⭐ গ্রাহকের ড্যাশবোর্ডের বিক্রি-নির্ভর তিন প্রশ্ন — মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬।
     * ⓘ একই নিয়ম: পাকা বিল, হেডারে বাছা শাখায়; `null` মানে বিক্রয় মডিউলই নেই (মিথ্যা শূন্য নয়, চার্টই নেই)।
     */

    /**
     * দুই তারিখের মধ্যে পাকা বিলের সংখ্যা আর মোট টাকা
     *
     * @return array{count: int, total: string}|null
     */
    public function billsBetween(string $from, string $to): ?array;

    /**
     * দুই তারিখের মধ্যে সবচেয়ে বেশি কেনা গ্রাহক, বড়টা আগে
     *
     * @return list<array{customer_id: int, total: string}>|null
     */
    public function topBuyers(string $from, string $to, int $limit): ?array;

    /**
     * মেয়াদ (বিলের `due_on`, না থাকলে বিলের দিন) `$today`-র আগে পেরিয়েছে অথচ শোধ হয়নি — গ্রাহক ধরে, বড়টা আগে
     *
     * @return list<array{customer_id: int, bills: int, amount: string}>|null
     */
    public function overdueByCustomer(string $today): ?array;
}
