<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বিলের বাকি লেজার ধরে, অটো (মালিক, ২৫ নভেম্বর ২০২৬: "লেজারে ডেবিট ক্রেডিট হবে, বিলওয়াইজ পেমেন্টের দরকার নাই, অটো বসার কথা")।
 *
 * গ্রাহকের খাতার জের যত, ততটাই তাঁর সবচেয়ে নতুন বিলগুলোর বাকি; তার আগের বিল আপনা থেকে শোধ। উদাহরণ: তিনটা বিল ১০,০০০ করে, বিলের নাম ছাড়া
 * ২৫,০০০ এসেছে → জের ৫,০০০ → নতুন বিলে বাকি ৫,০০০, আগের দুটো শোধ। ⓘ বিলে বাঁধা টাকা (কাউন্টার) আগের মতোই সেই বিলের; এখানে কেবল নাম-ছাড়া
 * টাকার ভাগ। ⓘ বয়সের রিপোর্ট ([[PartyReports]]) আগে থেকেই এই নিয়মে।
 *
 * ⛔ রসিদ, বিল বা খাতায় কিছু লেখা হয় না — `sal_auto_dues` গোনা অঙ্ক, খাতা বদলালে আবার গোনা ([[refresh()]]); কোনো কাগজের কাজ (বাতিল, ফেরত) একে
 *   দেখে না, কেবল "বাকি" ([[SalesInvoice::dueAmount()]], [[SalesInvoice::dueSql()]])।
 */
final class AutoDues
{
    public function refresh(int $companyId, int $customerId): void
    {
        $balance = (string) (DB::table('ledger_entries')
            ->where('company_id', $companyId)
            ->where('party_type', Customer::drillSourceType())
            ->where('party_id', $customerId)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as b')->value('b') ?? '0');

        // ⓘ সব শাখার বিল — খাতার জেরও গোটা কোম্পানির; নতুনটা আগে
        $bills = SalesInvoice::query()->withoutGlobalScopes()
            ->where('sal_invoices.company_id', $companyId)
            ->where('sal_invoices.customer_id', $customerId)
            ->whereNull('sal_invoices.deleted_at')
            ->posted()
            ->withCollected(auto: false)
            ->orderByDesc('sal_invoices.trx_date')->orderByDesc('sal_invoices.id')
            ->get();

        $rows = self::spread($balance, $bills->map(fn (SalesInvoice $b) => ['id' => (int) $b->id, 'due' => $b->ownDue()])->all());

        DB::transaction(function () use ($companyId, $customerId, $rows): void {
            DB::table('sal_auto_dues')->where('company_id', $companyId)->where('customer_id', $customerId)->delete();

            foreach (array_chunk($rows, 500, true) as $chunk) {
                DB::table('sal_auto_dues')->insert(array_map(
                    fn (int $id, string $settled) => ['sales_invoice_id' => $id, 'company_id' => $companyId, 'customer_id' => $customerId, 'settled' => $settled],
                    array_keys($chunk), $chunk));
            }
        });
    }

    /**
     * জের নতুন বিল থেকে পুরোনোর দিকে — প্রতিটা বিলে যতটা ধরে; বাকিটা "আপনা থেকে শোধ"।
     *
     * @param  list<array{id: int, due: string}>  $bills  নতুনটা আগে
     * @return array<int, string>  বিল => আপনা থেকে শোধ, শূন্যগুলো বাদ
     */
    public static function spread(string $balance, array $bills): array
    {
        $left = bccomp($balance, '0', 4) > 0 ? bcadd($balance, '0', 4) : '0.0000';
        $settled = [];

        foreach ($bills as $bill) {
            $due = bccomp($bill['due'], '0', 4) > 0 ? bcadd($bill['due'], '0', 4) : '0.0000';
            $open = bccomp($left, $due, 4) < 0 ? $left : $due;
            $left = bcsub($left, $open, 4);
            $auto = bcsub($due, $open, 4);

            if (bccomp($auto, '0', 4) > 0) {
                $settled[$bill['id']] = $auto;
            }
        }

        return $settled;
    }

    /** গোটা কোম্পানি — প্রথমবার, বা মিলিয়ে দেখতে ([[RebuildAutoDues]]) */
    public function company(int $companyId): int
    {
        $customers = SalesInvoice::query()->withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('customer_id')
            ->distinct()->pluck('customer_id');

        foreach ($customers as $customer) {
            $this->refresh($companyId, (int) $customer);
        }

        return $customers->count();
    }
}
