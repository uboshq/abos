<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\DepreciationEntry;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Models\Voucher;
use Carbon\CarbonImmutable;

/**
 * মাস-শেষের চেকলিস্ট — মাসটা বন্ধ করার আগে কী কী ঠিক থাকা দরকার।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ফিন্যান্স মানচিত্রের §২৪ "মাস-শেষের চেকলিস্ট" বাকি ছিল। মাস বন্ধ করার
 * পর্দা ([[PeriodLockController]]) আগে থেকেই আছে, কিন্তু সেটা জিজ্ঞেস করে
 * না মাসটা বন্ধ করার মতো অবস্থায় আছে কি না। ⓘ হিসাবরক্ষক কাগজে যে তালিকা
 * ধরে মাস বন্ধ করেন, এটা সেই তালিকা, খাতা থেকে গুনে।
 *
 * ⭐ প্রতিটা সারি তিনটার একটা: ঠিক আছে, বাকি আছে (কতগুলো সহ, আর কোথায়
 * গেলে সারানো যায়), অথবা এই মাসে প্রযোজ্য নয়। ⛔ "প্রযোজ্য নয়" আর "ঠিক
 * আছে" আলাদা, কারণ স্থায়ী সম্পদ নেই এমন কোম্পানিতে অবচয়ের সারি সবুজ
 * দেখালে মনে হত অবচয় পোস্ট হয়েছে।
 */
final class MonthEndChecklist
{
    public const OK = 'ok';

    public const PENDING = 'pending';

    public const NOT_APPLICABLE = 'na';

    /**
     * @return list<array{key: string, state: string, count: int, route: string, params: array<string, mixed>}>
     */
    public function run(CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        return [
            $this->drafts($from, $to),
            $this->awaiting($from, $to),
            $this->bankReconciled($from, $to),
            $this->cashCounted($from, $to),
            $this->depreciated($from, $to),
            $this->balanced($from, $to),
            $this->locked($month),
        ];
    }

    /** মাসের তারিখে কোনো খসড়া ভাউচার পড়ে নেই। */
    private function drafts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $count = Voucher::query()
            ->where('status', DocumentStatus::DRAFT)
            ->whereBetween('trx_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        return $this->row('drafts', $count, 'accounts.voucher.list');
    }

    /** মাসের কোনো ভাউচার অনুমোদনের অপেক্ষায় নেই। */
    private function awaiting(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = Approval::query()
            ->where('approvable_type', Voucher::class)
            ->pending()
            ->pluck('approvable_id');

        $count = $ids->isEmpty() ? 0 : Voucher::query()
            ->whereIn('id', $ids)
            ->whereBetween('trx_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        return $this->row('awaiting', $count, 'approval.inbox.index');
    }

    /**
     * মাসে যে ব্যাংক খাতে লেনদেন হয়েছে, তার প্রতিটার মাসের মিলকরণ নিশ্চিত।
     *
     * ⓘ লেনদেনহীন ব্যাংক খাত বাদ — চুপচাপ পড়ে থাকা একটা খাতের জন্য প্রতি
     * মাসে মিলকরণ চাইলে তালিকাটা কখনো সবুজ হত না, আর লোকে এটা দেখা ছেড়ে দিত।
     */
    private function bankReconciled(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $banks = Account::query()->ofMoneyKind(Account::BANK)->pluck('id');

        $used = $banks->isEmpty() ? collect() : LedgerEntry::query()
            ->whereIn('account_id', $banks)
            ->whereBetween('trx_date', [$from->toDateString(), $to->toDateString()])
            ->distinct()
            ->pluck('account_id');

        if ($used->isEmpty()) {
            return $this->row('bank_reconciled', 0, 'accounts.reconciliation.index', applicable: false);
        }

        $done = BankReconciliation::query()
            ->whereIn('bank_account_id', $used)
            ->where('status', BankReconciliation::CONFIRMED)
            ->whereBetween('statement_date', [$from->toDateString(), $to->toDateString()])
            ->distinct()
            ->pluck('bank_account_id');

        return $this->row('bank_reconciled', $used->diff($done)->count(), 'accounts.reconciliation.index');
    }

    /** প্রতিটা চালু ক্যাশ টিলের মাসে অন্তত একবার নিশ্চিত গোনা হয়েছে। */
    private function cashCounted(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tills = CashTill::query()->where('is_active', true)->pluck('id');

        if ($tills->isEmpty()) {
            return $this->row('cash_counted', 0, 'accounts.count.index', applicable: false);
        }

        $counted = CashCount::query()
            ->whereIn('cash_till_id', $tills)
            ->where('status', DocumentStatus::CONFIRMED)
            ->whereBetween('trx_date', [$from->toDateString(), $to->toDateString()])
            ->distinct()
            ->pluck('cash_till_id');

        return $this->row('cash_counted', $tills->diff($counted)->count(), 'accounts.count.index');
    }

    /** মাস শেষের আগে কেনা প্রতিটা চালু সম্পদের এই মাসের অবচয় বসেছে। */
    private function depreciated(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $assets = FixedAsset::query()
            ->where('status', FixedAsset::ACTIVE)
            ->where('acquired_on', '<=', $to->toDateString())
            ->pluck('id');

        if ($assets->isEmpty()) {
            return $this->row('depreciated', 0, 'accounts.asset.index', applicable: false);
        }

        $done = DepreciationEntry::query()
            ->whereIn('fixed_asset_id', $assets)
            ->whereBetween('period_end', [$from->toDateString(), $to->toDateString()])
            ->distinct()
            ->pluck('fixed_asset_id');

        return $this->row('depreciated', $assets->diff($done)->count(), 'accounts.asset.index');
    }

    /**
     * মাসের খাতায় ডেবিট আর ক্রেডিট সমান।
     *
     * ⓘ গোনা হয় পয়সায় (ফারাক টাকার অঙ্কে নয়, সংখ্যায় ১ মানে গরমিল আছে) —
     * পুরো যাচাই [[BooksIntegrityController]]-এ, এখানে কেবল এই মাসের যোগ।
     */
    private function balanced(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sum = LedgerEntry::query()
            ->whereBetween('trx_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $off = bccomp((string) $sum->d, (string) $sum->c, 2) !== 0;

        return $this->row('balanced', $off ? 1 : 0, 'accounts.integrity');
    }

    /** মাসটা বন্ধ করা হয়েছে — শেষ ধাপ, বাকিগুলো সবুজ হওয়ার পরে। */
    private function locked(CarbonImmutable $month): array
    {
        $locked = PeriodLock::query()
            ->where('year', (int) $month->year)
            ->where('month', (int) $month->month)
            ->exists();

        return $this->row('locked', $locked ? 0 : 1, 'accounts.period.index');
    }

    private function row(string $key, int $count, string $route, bool $applicable = true): array
    {
        return [
            'key' => $key,
            'state' => ! $applicable ? self::NOT_APPLICABLE : ($count === 0 ? self::OK : self::PENDING),
            'count' => $count,
            'route' => $route,
            'params' => [],
        ];
    }
}
