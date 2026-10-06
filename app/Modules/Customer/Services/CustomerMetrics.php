<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\ViewedBranch;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * গ্রাহকদের কাছে মোট কত পাওনা, আর কয়টা দোকানে — এক প্রশ্ন, এক উত্তর।
 *
 * ⓘ খাতার নিয়ম [[PartyReports::dueList()]]-এর: কোম্পানির খতিয়ান, পক্ষ
 * গ্রাহক, আজ পর্যন্ত, গ্রাহক ধরে জের। ⚠️ তফাত একটাই, ইচ্ছাকৃত: তালিকা
 * অগ্রিমও দেখায় (জের ঋণাত্মক), কিন্তু এখানে কেবল **ধনাত্মক** জের গোনা
 * হয়। অগ্রিম যোগ করলে একজনের অগ্রিম আরেকজনের বকেয়া ঢেকে দিত — "বাজারে
 * কত আটকে আছে" প্রশ্নের উত্তর তখন কম আসত।
 */
final class CustomerMetrics
{
    /**
     * @return array{amount: string, shops: int} টাকা স্ট্রিং, চার ঘর
     */
    public function dues(User $user, string $asOf): array
    {
        $perShop = ViewedBranch::narrow(DB::table('ledger_entries'), 'ledger_entries.branch_id', $user)
            ->join('customers', 'customers.id', '=', 'ledger_entries.party_id')
            ->where('ledger_entries.company_id', CompanyContext::id())
            ->where('ledger_entries.party_type', Customer::drillSourceType())
            // ⭐ বিক্রয়কর্মী কেবল নিজের ডিলারের বকেয়া (⛔১৬, ২ অক্টোবর ২০২৬)
            ->tap(fn ($q) => app(\App\Core\Services\DealerScope::class)->restrict($q, 'customers.id', $user))
            ->where('ledger_entries.trx_date', '<=', $asOf)
            /*
             * ⚠️ শাখায় সীমিত ব্যবহারকারী কেবল নিজের শাখার জের দেখেন —
             * [[ScopedToUserBranch]]-এর একই নিয়ম (শাখাহীন সারিও থাকে),
             * যাতে বিক্রয়ের সংখ্যা আর বকেয়া দুই রকম সীমায় না দাঁড়ায়।
             */
            /*
             * ⭐ ৩০ সেপ্টেম্বর ২০২৬: নাগালের পাশাপাশি হেডারে বাছা শাখাও — এক শাখা বাছা
             * থাকলে কেবল সেটা, শাখাহীন সারি ছাড়া ([[ViewedBranch::narrow()]])।
             */
            ->groupBy('ledger_entries.party_id')
            ->havingRaw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) > 0')
            ->selectRaw('SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as outstanding');

        $row = DB::query()->fromSub($perShop, 'due')
            ->selectRaw('COALESCE(SUM(outstanding), 0) as amount, COUNT(*) as shops')
            ->first();

        return [
            'amount' => bcadd((string) ($row->amount ?? '0'), '0', 4),
            'shops' => (int) ($row->shops ?? 0),
        ];
    }
}
