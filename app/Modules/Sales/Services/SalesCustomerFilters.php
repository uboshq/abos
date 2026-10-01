<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\CustomerSalesFilters;
use App\Core\Support\ViewedBranch;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * গ্রাহকের তালিকার বিক্রি-নির্ভর ছাঁকনি, বিক্রয়ের দিক থেকে — মালিক, ১ অক্টোবর ২০২৬ ([[CustomerSalesFilters]])।
 *
 * ⓘ "পাকা বিল" = [[HasDocumentStatus::scopePosted()]] (নিশ্চিত বা বন্ধ), আর সবই হেডারে বাছা শাখায় — এক শাখা বেছে
 * অন্য শাখার বিক্রি গোনা হয় না ([[ViewedBranch]])।
 */
final class SalesCustomerFilters implements CustomerSalesFilters
{
    public function salesTotal(string $from, string $to): ?Builder
    {
        return $this->bills($from, $to)->selectRaw('COALESCE(SUM(sal_invoices.total), 0)')->toBase();
    }

    public function boughtBetween(Builder $customers, string $from, string $to): Builder
    {
        return $customers->whereExists($this->bills($from, $to)->selectRaw('1')->toBase());
    }

    /**
     * ⓘ "অপরিশোধিত" = মোট > আদায় + রসিদ-ভাউচার + পাকা ফেরত — [[SalesInvoice::scopeWithCollected()]]-এর হুবহু শর্ত,
     * তাই বিলের পাতা যা বাকি বলে, এখানেও তাই। ⓘ মেয়াদ গ্রাহকের নিজের বাকির দিন; খালি (০) হলে ৩০ দিন।
     */
    public function noOverdueBill(Builder $customers): Builder
    {
        $bills = ViewedBranch::narrow(SalesInvoice::query()->posted(), 'sal_invoices.branch_id')->withCollected()->toBase();

        return $customers->whereNotExists(fn ($q) => $q->fromSub($bills, 'b')
            ->selectRaw('1')
            ->whereColumn('b.customer_id', 'customers.id')
            ->whereRaw('b.total > b.collected_total + b.voucher_total + b.returned_total')
            ->whereRaw('b.trx_date < DATE_SUB(CURDATE(), INTERVAL COALESCE(NULLIF(customers.credit_days, 0), 30) DAY)'));
    }

    private function bills(string $from, string $to): \Illuminate\Database\Eloquent\Builder
    {
        return ViewedBranch::narrow(SalesInvoice::query()->posted(), 'sal_invoices.branch_id')
            ->whereColumn('sal_invoices.customer_id', 'customers.id')
            ->whereBetween('sal_invoices.trx_date', [$from, $to]);
    }
}
