<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\OpenPeriod;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\AssetUsage;
use App\Modules\Accounts\Models\DepreciationEntry;
use App\Modules\Accounts\Models\DepreciationRun;
use App\Modules\Accounts\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ অবচয়ের ইঞ্জিন — তিন পদ্ধতি, প্রথম মাসের ভাগ, মাসে একবার, শাখায় একটা কাগজ (স্থায়ী সম্পদ ধাপ ২; IAS 16.50-62)।
 *
 * ── এক মাসের অঙ্ক ([[amountFor()]]) ─────────────────────────────────────
 *   · শুরু ব্যবহার শুরুর দিন থেকে ([[FixedAsset::depreciatesFrom()]]); তার আগের মাসে কিছু নয়।
 *   · সমান হারে: বাকি ক্ষয় ÷ বাকি আয়ুর মাস। ⓘ কিছু না বদলালে প্রতি মাসে হুবহু (দাম − শেষ দাম) ÷ আয়ু — আগের অঙ্ক;
 *     আয়ু বা শেষ দাম বদলালে বাকি অঙ্ক বাকি মাসে ভাগ হয়, অতীত ছোঁয়া হয় না (IAS 8: আগামীর দিকে)।
 *   · অবশিষ্ট দামের উপর: খাতায় এখনকার দাম × বছরের হার ÷ ১২।
 *   · ব্যবহারের এককে: বাকি ক্ষয় × এ মাসের একক ÷ বাকি একক — মাসের একক না লিখলে সেই মাসে কিছু নয়।
 *   · প্রথম মাস: মালিকের সেটিং — পুরো মাস (আজকের আচরণ) নাকি দিন ধরে ভাগ।
 *   · শেষ দামে থামে — বাকি ক্ষয়ের বেশি কখনো নয়। অলস জিনিস ক্ষয় ধরে (IAS 16.55), যদি না মালিক সুইচটা চালু করেন।
 *
 * ── মাসের দৌড় ([[run()]]) ───────────────────────────────────────────────
 *   · আগে দেখা ([[preview()]]) — কিছু লেখে না; দৌড় ঠিক সেই সারিগুলোই বসায়।
 *   · বন্ধ মাসে থামে, বাংলায় কারণ বলে। প্রতি শাখায় একটা কাগজ (DEP-…), ভেতরে খাতের জোড়া ধরে সারি (শ্রেণির খাত)।
 *   · দুইবার চালালে একবারই বসে: (কোম্পানি, মাস, শাখা) ডাটাবেজে অনন্য, আর প্রতিটা সম্পদের মাসও অনন্য।
 */
final class DepreciationEngine
{
    public const PRORATA = 'accounts.asset.prorata';

    public const FULL_MONTH = 'full_month';

    public const DAILY = 'daily';

    public const IDLE_STOPS = 'accounts.asset.idle_stops_depreciation';

    public const AUTO_RUN = 'accounts.asset.auto_run';

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
        private readonly SettingsService $settings,
    ) {}

    /**
     * এক সম্পদের এক মাসের অঙ্ক — শূন্য হলে কেন, সেটাও (পর্দায় দেখানোর জন্য)।
     *
     * @return array{amount: string, reason: ?string}
     */
    public function amountFor(FixedAsset $asset, Carbon|string $month, bool $once = true): array
    {
        $periodEnd = Carbon::parse($month)->endOfMonth()->startOfDay();
        $none = fn (string $why) => ['amount' => '0.0000', 'reason' => $why];

        if (! $asset->isInService()) {
            return $none('not_in_service');
        }

        if ($asset->status === FixedAsset::IDLE && (bool) $this->settings->get(self::IDLE_STOPS, false)) {
            return $none('idle');
        }

        $start = $asset->depreciatesFrom();

        if ($periodEnd->lessThan($start->copy()->endOfMonth()->startOfDay())) {
            return $none('not_started');
        }

        // ⓘ `$once` না দিলে এই প্রশ্নটা ডাটাবেজের অনন্য সারি তোলে ([[FixedAssetService::depreciate()]] — আগের আচরণ)
        if ($once && $asset->depreciation()->where('period_end', $periodEnd->toDateString())->exists()) {
            return $none('already');
        }

        $left = $asset->depreciableLeft($periodEnd);

        if (bccomp($left, '0', 4) <= 0) {
            return $none('fully_depreciated');
        }

        $fraction = $this->firstMonthShare($start, $periodEnd);

        $amount = match ($asset->method) {
            FixedAsset::REDUCING => bcmul(bcdiv(bcmul($asset->bookValue($periodEnd), bcdiv((string) $asset->rate, '100', 8), 8), '12', 8), $fraction, 8),
            FixedAsset::UNITS => $this->byUnits($asset, $periodEnd, $left),
            default => bcmul($this->straightShare($asset, $start, $periodEnd, $left), $fraction, 8),
        };

        if ($amount === null) {
            return $none('no_usage');
        }

        $amount = Money::of(bcadd($amount, '0', 4));

        if (bccomp($amount, $left, 4) > 0) {
            $amount = $left;
        }

        return bccomp($amount, '0', 4) > 0 ? ['amount' => $amount, 'reason' => null] : $none('fully_depreciated');
    }

    /**
     * ⭐ আগে দেখা — এ মাসে কোন সম্পদে কত বসবে, শাখা ধরে; কিছু লেখে না।
     *
     * ⓘ সব শাখা — মাসের দৌড় গোটা কোম্পানির কাজ (মাস বন্ধের মতোই), শাখার দেয়ালে আটকালে অন্য শাখার ক্ষয় কোনোদিন বসত না।
     *
     * @return Collection<int, array{asset: FixedAsset, amount: string, reason: ?string}>
     */
    public function preview(Carbon|string $month): Collection
    {
        return FixedAsset::acrossBranches()
            ->inService()
            ->with(['category', 'branch'])
            ->orderBy('branch_id')->orderBy('document_no')
            ->get()
            ->map(fn (FixedAsset $asset) => ['asset' => $asset, ...$this->amountFor($asset, $month)]);
    }

    /**
     * মাসের দৌড় — শাখায় একটা কাগজ; দুইবার চালালে দ্বিতীয়বার কিছু বসে না।
     *
     * @return array{posted: int, skipped: int, total: string, runs: list<int>}
     */
    public function run(Carbon|string $month): array
    {
        $periodEnd = Carbon::parse($month)->endOfMonth()->startOfDay();

        // ⛔ বন্ধ মাসে নয় — কারণ বাংলায়, ফরমের "মাস" ঘরে ([[OpenPeriod::assertOpen()]])
        app(OpenPeriod::class)->assertOpen($periodEnd, 'month');

        $rows = $this->preview($periodEnd);
        $posted = 0;
        $skipped = $rows->whereNotNull('reason')->count();
        $total = '0';
        $runs = [];

        foreach ($rows->whereNull('reason')->groupBy(fn (array $row) => (int) ($row['asset']->branch_id ?? 0)) as $branchKey => $group) {
            $done = $this->runBranch($periodEnd, (int) $branchKey, $group);

            if ($done === null) {
                $skipped += $group->count();

                continue;
            }

            $runs[] = (int) $done->id;
            $posted += (int) $done->assets_count;
            $skipped += $group->count() - (int) $done->assets_count;
            $total = bcadd($total, (string) $done->total, 4);
        }

        return ['posted' => $posted, 'skipped' => $skipped, 'total' => $total, 'runs' => $runs];
    }

    /**
     * এক শাখার কাগজ — সম্পদের সারি, তারপর খাতের জোড়া ধরে দাখিলা।
     *
     * @param  Collection<int, array{asset: FixedAsset, amount: string, reason: ?string}>  $group
     */
    private function runBranch(Carbon $periodEnd, int $branchKey, Collection $group): ?DepreciationRun
    {
        $already = DepreciationRun::acrossBranches()
            ->where('period_end', $periodEnd->toDateString())
            ->where('branch_key', $branchKey)
            ->exists();

        if ($already) {
            return null;
        }

        $branchId = $branchKey === 0 ? null : $branchKey;

        return DB::transaction(function () use ($periodEnd, $branchKey, $branchId, $group) {
            $run = DepreciationRun::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $branchId,
                'branch_key' => $branchKey,
                'period_end' => $periodEnd->toDateString(),
                'document_no' => $this->numbers->next(DepreciationRun::SERIES, $branchId, $periodEnd),
                'created_by' => auth()->id(),
            ]);

            $pairs = [];
            $count = 0;
            $total = '0';

            foreach ($group as $row) {
                /** @var FixedAsset $asset */
                $asset = $row['asset'];

                // ⓘ অনন্য সারি (সম্পদ, মাস) — অন্য পথে এ মাসে বসে গেলে এখানে থামে, লেনদেন ফেরে না
                if (DepreciationEntry::query()->where('fixed_asset_id', $asset->id)->where('period_end', $periodEnd->toDateString())->exists()) {
                    continue;
                }

                DepreciationEntry::query()->create([
                    'company_id' => $asset->company_id,
                    'fixed_asset_id' => $asset->id,
                    'run_id' => $run->id,
                    'period_end' => $periodEnd->toDateString(),
                    'amount' => $row['amount'],
                    // ⓘ সারির নম্বর অনন্য — দৌড়ের নম্বর আর সম্পদের নম্বর জোড়া
                    'document_no' => $run->document_no.'/'.$asset->document_no,
                    'created_by' => auth()->id(),
                ]);

                $key = $asset->expense_account_id.'|'.$asset->accumulated_account_id;
                $pairs[$key] = bcadd($pairs[$key] ?? '0', $row['amount'], 4);
                $total = bcadd($total, $row['amount'], 4);
                $count++;
            }

            if ($count === 0) {
                $run->delete();

                return null;
            }

            $lines = [];

            foreach ($pairs as $key => $amount) {
                [$expense, $accumulated] = array_map('intval', explode('|', $key));
                // ⓘ সারির নিজের শাখা — শাখাহীন সম্পদে সত্যিই শাখাহীন, হেডারের শাখা নয় ([[PostingEngine]])
                $lines[] = ['account_id' => $expense, 'debit' => $amount, 'branch_id' => $branchId];
                $lines[] = ['account_id' => $accumulated, 'credit' => $amount, 'branch_id' => $branchId];
            }

            $this->posting->post(
                sourceType: DepreciationRun::drillSourceType(),
                sourceId: $run->id,
                trxDate: $periodEnd->toDateString(),
                lines: $lines,
                documentNo: $run->document_no,
                branchId: $branchId,
            );

            $run->update(['total' => $total, 'assets_count' => $count]);

            return $run->refresh();
        });
    }

    /** প্রথম মাসের ভাগ — পুরো মাস, নাকি দিন ধরে (মালিকের সেটিং) */
    private function firstMonthShare(Carbon $start, Carbon $periodEnd): string
    {
        if ($this->settings->get(self::PRORATA, self::FULL_MONTH) !== self::DAILY || ! $start->isSameMonth($periodEnd)) {
            return '1';
        }

        $days = (int) $periodEnd->daysInMonth;

        return bcdiv((string) ($days - (int) $start->day + 1), (string) $days, 8);
    }

    /** সমান হারে: বাকি ক্ষয় ÷ বাকি আয়ুর মাস (এ মাসসহ) — আয়ু শেষ হলে বাকিটা এক মাসে */
    private function straightShare(FixedAsset $asset, Carbon $start, Carbon $periodEnd, string $left): string
    {
        $elapsed = (int) $start->copy()->startOfMonth()->diffInMonths($periodEnd->copy()->startOfMonth());
        $monthsLeft = (int) $asset->life_months - $elapsed;

        return $monthsLeft <= 1 ? $left : bcdiv($left, (string) $monthsLeft, 8);
    }

    /** ব্যবহারের এককে: বাকি ক্ষয় × এ মাসের একক ÷ বাকি একক; একক না লেখা থাকলে `null` */
    private function byUnits(FixedAsset $asset, Carbon $periodEnd, string $left): ?string
    {
        $units = AssetUsage::query()->where('fixed_asset_id', $asset->id)
            ->where('period_end', $periodEnd->toDateString())->value('units');

        if ($units === null || bccomp((string) $asset->total_units, '0', 4) <= 0) {
            return null;
        }

        $used = (string) AssetUsage::query()->where('fixed_asset_id', $asset->id)
            ->where('period_end', '<', $periodEnd->toDateString())->sum('units');
        $remaining = bcsub((string) $asset->total_units, $used, 4);

        return bccomp($remaining, (string) $units, 4) <= 0 ? $left : bcdiv(bcmul($left, (string) $units, 8), $remaining, 8);
    }

    /**
     * একটা মাসের দৌড় ফেরত দেওয়ার ভাষা — পর্দার জন্য।
     *
     * @throws ValidationException
     */
    public static function assertMonth(?string $month): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m', (string) $month)->endOfMonth()->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['month' => __('accounts::asset.month_wrong')]);
        }
    }
}
