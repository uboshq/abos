<?php

declare(strict_types=1);

namespace App\Modules\Hr\Support;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ কর্মীর খোলা অগ্রিম — একটাই নিয়ম, দুই জায়গায় ([[PayrollService]] বেতন থেকে কাটার সীমা, [[ExpenseClaimService]] খরচের দাবি
 * অগ্রিম থেকে মেটানো; পুরো-ERP অডিট HR ⛔৪ আর মালিকের আদেশ ৭ অক্টোবর ২০২৬)।
 *
 * ⓘ খাতায় বসা দেওয়া − আদায়, কর্মীর নামে (১১৩১ আর তার নিচের খাত, পক্ষ `employee`), দিন পর্যন্ত; গোটা কোম্পানি — অগ্রিম
 * মানুষের, শাখার নয়।
 */
final class AdvanceBalance
{
    /** @var list<int>|null */
    private ?array $accounts = null;

    /** খাতটা কি কর্মীর অগ্রিম (১১৩১ বা তার নিচে) */
    public function isAdvance(?int $accountId): bool
    {
        return $accountId !== null && in_array($accountId, $this->accounts(), true);
    }

    /** দিন পর্যন্ত খোলা অগ্রিম — ঋণাত্মক হতে পারে (বেশি ফেরত দিয়েছেন) */
    public function open(Employee $employee, Carbon $on): string
    {
        return bcadd((string) DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->whereIn('account_id', $this->accounts())
            ->where('party_type', Employee::drillSourceType())
            ->where('party_id', $employee->id)
            ->where('trx_date', '<=', $on->toDateString())
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')
            ->value('n'), '0', 2);
    }

    /** @return list<int> */
    private function accounts(): array
    {
        return $this->accounts ??= StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)?->selfAndDescendants()
            ->map(fn ($a) => (int) $a->id)->values()->all() ?? [];
    }
}
