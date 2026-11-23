<?php

declare(strict_types=1);

namespace App\Modules\Sales\Profile;

use App\Core\Contracts\ContributesToAProfile;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesTargetService;
use Illuminate\Support\Carbon;

/**
 * ⭐ "এই মাসে আমার কাজ"-এর বিক্রয়ের সংখ্যা — মোট বিক্রি, নিজের বিক্রি, অর্ডার, লক্ষ্যের %, মেলানো জমা ([[ContributesToAProfile]])।
 *
 * ⛔ মোট কেবল পাকা কাগজের ([[DocumentStatus::POSTED]]; মালিকের নিয়ম — খসড়া, অপেক্ষায় বা বাতিল যোগে নয়)। ⓘ টাকা ইংরেজি অঙ্কে।
 */
final class SalesProfile implements ContributesToAProfile
{
    public function identityOf(User $owner): array
    {
        return [];
    }

    public function stat(string $key, User $owner, Carbon $from, Carbon $to): ?string
    {
        return match ($key) {
            'sales_total' => $this->taka($this->salesTotal(null, $from, $to)),
            'my_sales' => $this->taka($this->salesTotal($owner, $from, $to)),
            'my_orders' => number_format($this->ordersBy($owner, $from, $to)),
            'target' => $this->targetPercent($owner, $from),
            'deposits_matched' => number_format($this->depositsBy($owner, $from, $to)),
            default => null,
        };
    }

    private function salesTotal(?User $by, Carbon $from, Carbon $to): string
    {
        return (string) SalesInvoice::acrossBranches()
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereBetween('trx_date', [$from->toDateString(), $to->toDateString()])
            // ⭐ "নিজের বিক্রি" = বিলে লেখা দায়িত্বপ্রাপ্ত জন আমি (মালিক, ২৩ নভেম্বর ২০২৬) — বিল আমি লিখেছি কি না নয়
            ->when($by !== null, fn ($q) => $q->where('sales_person_id', $by->getKey()))
            ->sum('total');
    }

    private function ordersBy(User $by, Carbon $from, Carbon $to): int
    {
        return SalesOrder::acrossBranches()
            ->where('created_by', $by->getKey())
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereBetween('trx_date', [$from->toDateString(), $to->toDateString()])
            ->count();
    }

    private function targetPercent(User $by, Carbon $month): ?string
    {
        foreach (app(SalesTargetService::class)->scoreboard($month) as $row) {
            if ((int) $row['user']->getKey() === (int) $by->getKey()) {
                return $row['percent'] === null ? null : rtrim(rtrim((string) $row['percent'], '0'), '.').'%';
            }
        }

        // ⓘ লক্ষ্য বসানো নেই — ঘরটা বাদ, পরের সংখ্যা আসে
        return null;
    }

    private function depositsBy(User $by, Carbon $from, Carbon $to): int
    {
        return DepositClaim::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->where('decided_by', $by->getKey())
            ->where('status', DepositClaim::ACCEPTED)
            ->whereBetween('decided_at', [$from, $to])
            ->count();
    }

    private function taka(string $amount): string
    {
        return '৳'.Money::format($amount, 0);
    }
}
