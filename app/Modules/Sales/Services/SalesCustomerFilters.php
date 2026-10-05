<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\CustomerSalesFilters;
use App\Core\Support\CompanyContext;
use App\Core\Support\ViewedBranch;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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

    /**
     * ⭐ গ্রাহকের ড্যাশবোর্ড — এ সময়কালের পাকা বিল, সংখ্যা ও মোট (৫ অক্টোবর ২০২৬)।
     * ⓘ [[SalesMetrics::invoiceTotal()]]-এর ছাঁকনি (`posted()` + `trx_date`), হেডারে বাছা শাখায় — বিক্রয়ের পর্দার
     * "এ মাসের বিক্রয়" আর গ্রাহকের পর্দার সংখ্যা এক।
     */
    public function billsBetween(string $from, string $to): ?array
    {
        $row = $this->posted($from, $to)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(sal_invoices.total), 0) as total')
            ->toBase()
            ->first();

        return ['count' => (int) ($row->n ?? 0), 'total' => bcadd((string) ($row->total ?? '0'), '0', 4)];
    }

    /** ⭐ সবচেয়ে বেশি কেনা গ্রাহক — একই ছাঁকনি, গ্রাহক ধরে; সমান হলে আগের id আগে (৫ অক্টোবর ২০২৬) */
    public function topBuyers(string $from, string $to, int $limit): ?array
    {
        return $this->posted($from, $to)
            ->selectRaw('sal_invoices.customer_id, COALESCE(SUM(sal_invoices.total), 0) as total')
            ->groupBy('sal_invoices.customer_id')
            ->orderByRaw('SUM(sal_invoices.total) desc')
            ->orderBy('sal_invoices.customer_id')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn ($r): array => ['customer_id' => (int) $r->customer_id, 'total' => bcadd((string) $r->total, '0', 4)])
            ->all();
    }

    /**
     * ⭐ মেয়াদ পেরোনো বাকি, গ্রাহক ধরে — আদায়ের সূচির ([[CollectionDueReport]]) "মেয়াদ পেরোনো" ঘরের হুবহু নিয়ম
     * (৫ অক্টোবর ২০২৬): বিলের বাকি = মোট − আদায় − রসিদ − পাকা ফেরত ([[SalesInvoice::scopeWithCollected()]]),
     * মেয়াদ `due_on`, না লেখা থাকলে বিলের দিন, আর আজকের **আগে** (আজ পড়লে এখনো পেরোয়নি)।
     * ⓘ আজকের দিনটা PHP থেকে আসে — ডাটাবেজের ঘড়ি নয়।
     */
    public function overdueByCustomer(string $today): ?array
    {
        $bills = ViewedBranch::narrow(SalesInvoice::query()->posted(), 'sal_invoices.branch_id')->withCollected()->toBase();
        $due = '(i.total - i.collected_total - i.voucher_total - i.returned_total)';

        return DB::query()
            ->fromSub($bills, 'i')
            ->where('i.company_id', CompanyContext::id())
            ->whereRaw("{$due} > 0.0001")
            ->whereRaw('COALESCE(i.due_on, i.trx_date) < ?', [$today])
            ->groupBy('i.customer_id')
            ->orderByRaw("SUM({$due}) desc")
            ->orderBy('i.customer_id')
            ->selectRaw("i.customer_id, COUNT(*) as bills, SUM({$due}) as amount")
            ->get()
            ->map(fn ($r): array => [
                'customer_id' => (int) $r->customer_id,
                'bills' => (int) $r->bills,
                'amount' => bcadd((string) $r->amount, '0', 4),
            ])
            ->all();
    }

    /** পাকা বিল, সময়কালে, হেডারে বাছা শাখায় — গ্রাহকের সাথে জোড়া ছাড়া (ড্যাশবোর্ডের জন্য) */
    private function posted(string $from, string $to): \Illuminate\Database\Eloquent\Builder
    {
        return ViewedBranch::narrow(SalesInvoice::query()->posted(), 'sal_invoices.branch_id')
            ->whereBetween('sal_invoices.trx_date', [$from, $to]);
    }

    private function bills(string $from, string $to): \Illuminate\Database\Eloquent\Builder
    {
        return ViewedBranch::narrow(SalesInvoice::query()->posted(), 'sal_invoices.branch_id')
            ->whereColumn('sal_invoices.customer_id', 'customers.id')
            ->whereBetween('sal_invoices.trx_date', [$from, $to]);
    }
}
