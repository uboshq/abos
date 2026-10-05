<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\CustomerSalesFilters;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * বিক্রয় বন্ধ — কেউ কেনেনি, কারও বিল পড়ে নেই ([[CustomerSalesFilters]])।
 *
 * ⓘ "কিনেছেন" ছাঁকনি তাই কাউকে রাখে না (শূন্য তালিকা মিথ্যা নয় — সত্যিই কেউ কেনেনি), আর "মেয়াদি বিল নেই" সবাইকে রাখে।
 */
final class NoCustomerSalesFilters implements CustomerSalesFilters
{
    public function salesTotal(string $from, string $to): ?Builder
    {
        return null;
    }

    public function boughtBetween(Builder $customers, string $from, string $to): Builder
    {
        return $customers->whereRaw('1 = 0');
    }

    public function noOverdueBill(Builder $customers): Builder
    {
        return $customers;
    }

    // ⓘ বিক্রয় বন্ধ — ড্যাশবোর্ডের বিক্রি-নির্ভর চার্টগুলো নেই, শূন্যের চার্ট নয় (৫ অক্টোবর ২০২৬)
    public function billsBetween(string $from, string $to): ?array
    {
        return null;
    }

    public function topBuyers(string $from, string $to, int $limit): ?array
    {
        return null;
    }

    public function overdueByCustomer(string $today): ?array
    {
        return null;
    }
}
