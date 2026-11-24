<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\WppfProvision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ শ্রমিকের মুনাফায় অংশগ্রহণ তহবিল (WPPF) — বাংলাদেশ শ্রম আইন ২০০৬, অধ্যায় ১৫ (a6, ২১ অক্টোবর ২০২৬;
 * [[WorkersGetTheirShareOfTheProfitTest]])।
 *
 * ── আইন ──────────────────────────────────────────────────────────────
 * ধারা ২৩৪(১)(খ), বাংলাদেশ শ্রম (সংশোধন) আইন ২০১৩ (২০১৩ সনের ৩০ নং আইন) দিয়ে সংশোধিত: প্রতি বছর শেষ হওয়ার নয় মাসের মধ্যে
 * আগের বছরের **নিট মুনাফার ৫%** — **৮০:১০:১০** অনুপাতে অংশগ্রহণ তহবিল, কল্যাণ তহবিল, আর বাংলাদেশ শ্রমিক কল্যাণ ফাউন্ডেশন
 * আইন ২০০৬-এর তহবিলে। ⓘ অংশগ্রহণ তহবিলের ভাগ যোগ্য সব সদস্যের মধ্যে সমান (ধারা ২৪২: দুই-তৃতীয়াংশ নগদে, এক-তৃতীয়াংশ
 * বিনিয়োগে) — সদস্যের তালিকা আর বণ্টন এই ধাপে নয়, কেবল তহবিলের অঙ্ক আর তার দেনা।
 * ⓘ কোন প্রতিষ্ঠানে খাটে (ধারা ২৩২: পরিশোধিত মূলধন ≥ ১ কোটি বা স্থায়ী সম্পদ ≥ ২ কোটি) — যন্ত্র আন্দাজ করে না; কোম্পানির
 * সুইচ `hr.wppf.enabled`, ডিফল্টে বন্ধ। ⛔ বন্ধ থাকলে কোথাও কিছু নেই — মেনু নেই, দরজা ৪০৪।
 *
 * ── অঙ্কটা কোথা থেকে ─────────────────────────────────────────────────
 * ⭐ ওয়েবের লাভ-ক্ষতির রিপোর্টের হুবহু একই উৎস — রিপোর্ট ইঞ্জিনের `accounts.profit_loss`, অর্থবছরের প্রথম থেকে শেষ দিন, সব শাখা;
 * নিট = ক্রেডিট − ডেবিট (রিপোর্টের নিজের সারাংশের সূত্র, [[CoreReports::profitAndLoss()]])। ⛔ নিজে আলাদা কোয়েরি লিখলে একদিন
 * দুই পর্দা দুই মুনাফা বলত (বছর বন্ধের সারি, বিক্রয় ফেরত…)। ⓘ গোটা কোম্পানির মুনাফা — শাখায় সীমিত মানুষ পর্দাটা পান না।
 *
 * ── গোল করা ───────────────────────────────────────────────────────────
 * ৫% পয়সায় গোল (অর্ধেক হলে উপরে, [[Money::round()]]); ৮০% আর ১০% পয়সায় গোল, শেষ ভাগটা বাকি — তিন ভাগের যোগ সবসময় ঠিক ৫%।
 * ক্ষতির বছরে তহবিল শূন্য।
 *
 * ── জাবেদা (ঐচ্ছিক) ───────────────────────────────────────────────────
 * "সই দিন" চাপলে বছরের শেষ দিনে: খরচ ৫২২৩ ডেবিট, দেনা ২১৩৩ ক্রেডিট — কোম্পানির প্রধান শাখায়। কোম্পানির নিজের ছক (`hr` ·
 * `wppf_provision`) থাকলে আগে সেই সই ([[DocumentApproval]]); না থাকলে চাপ দেওয়া মানুষটিই সই — চাবি `hr.wppf.manage`। বছরে একবার।
 */
final class WppfService
{
    public const SETTING = 'hr.wppf.enabled';

    public const MODULE = 'hr';

    public const ACTION = 'wppf_provision';

    /** ⓘ ধারা ২৩৪(১)(খ) — নিট মুনাফার শতকরা */
    public const RATE = '5';

    /** ⓘ ধারা ২৩৪(১)(খ), ২০১৩-র সংশোধনী — অংশগ্রহণ : কল্যাণ : ফাউন্ডেশন */
    public const PARTICIPATION = '80';

    public const WELFARE = '10';

    public const FOUNDATION = '10';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PostingEngine $posting,
        private readonly DocumentApproval $approvals,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING);
    }

    /**
     * ⓘ গোটা কোম্পানি দেখেন কি না — শাখায় সীমিত হলে মুনাফাটা আধা, তাই পর্দাটাই নয়।
     */
    public function seesTheWholeCompany(User $user): bool
    {
        $allowed = app(DataScope::class)->idsFor($user, UserDataScope::BRANCH);

        if ($allowed === null) {
            return true;
        }

        $every = DB::table('branches')->where('company_id', CompanyContext::id())->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $every !== [] && array_diff($every, array_map('intval', $allowed)) === [];
    }

    /**
     * ⭐ বছরের হিসাব — লাভ-ক্ষতির নিট মুনাফা, তহবিলের নিজের খরচ ফিরিয়ে যোগ করে ভিত্তি, ৫%, আর তিন ভাগ।
     *
     * ⓘ ভিত্তি = লাভ-ক্ষতির নিট + এই বছরে ৫২২৩-এ বসা তহবিলের খরচ। ⛔ না ফেরালে জাবেদা বসার পরের দিন পর্দা কম মুনাফা দেখাত, আর
     * ৫% নিজের উপরেই কমত — তহবিল হিসাব হয় তহবিলের খরচের আগের মুনাফায়। জাবেদার আগে খরচ শূন্য, তাই ভিত্তি = লাভ-ক্ষতি, পয়সায় পয়সায়।
     *
     * @return array{pl_net: string, charged: string, net_profit: string, wppf: string, participation_fund: string, welfare_fund: string, foundation_fund: string}
     */
    public function figuresFor(FinancialYear $year): array
    {
        $from = $year->starts_on->toDateString();
        $to = $year->ends_on->toDateString();

        $result = app(ReportEngine::class)->run('accounts.profit_loss', ['from' => $from, 'to' => $to, 'branch_id' => null], 1, 1);
        $plNet = bcsub((string) ($result->totals['credit'] ?? '0') ?: '0', (string) ($result->totals['debit'] ?? '0') ?: '0', 4);

        $charged = (string) (DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', CompanyContext::id())
            ->where('accounts.code', StandardChart::WPPF_EXPENSE)
            ->whereBetween('ledger_entries.trx_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(ledger_entries.debit - ledger_entries.credit), 0) as net')
            ->value('net') ?? '0');

        return [
            'pl_net' => $plNet,
            'charged' => bcadd($charged, '0', 4),
            ...self::split(bcadd($plNet, $charged, 4)),
        ];
    }

    /**
     * ⓘ নিট মুনাফা থেকে তহবিল আর ভাগ — ক্ষতিতে শূন্য।
     *
     * @return array{net_profit: string, wppf: string, participation_fund: string, welfare_fund: string, foundation_fund: string}
     */
    public static function split(string $net): array
    {
        $base = bccomp($net, '0', 4) > 0 ? $net : '0';
        $wppf = Money::round(bcdiv(bcmul($base, self::RATE, 6), '100', 6));
        $participation = Money::round(bcdiv(bcmul($wppf, self::PARTICIPATION, 6), '100', 6));
        $welfare = Money::round(bcdiv(bcmul($wppf, self::WELFARE, 6), '100', 6));

        return [
            'net_profit' => bcadd($net, '0', 4),
            'wppf' => bcadd($wppf, '0', 4),
            'participation_fund' => bcadd($participation, '0', 4),
            'welfare_fund' => bcadd($welfare, '0', 4),
            // ⓘ বাকিটা — তিন ভাগের যোগ ঠিক ৫%, পয়সার গোলে এক পয়সাও হারায় না
            'foundation_fund' => bcsub(bcsub($wppf, $participation, 4), $welfare, 4),
        ];
    }

    public function provisionFor(FinancialYear $year): ?WppfProvision
    {
        return WppfProvision::query()->where('financial_year_id', $year->id)->first();
    }

    /**
     * ⭐ "সই দিন" — বছরের তহবিলের জাবেদা।
     *
     * @throws ValidationException বন্ধ, আগেই বসেছে, মুনাফা নেই, বা কোম্পানির ছকে সইয়ের অপেক্ষায় ([[HeldForApproval]])
     */
    public function provision(FinancialYear $year, User $by): WppfProvision
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages(['provision' => __('hr::wppf.off')]);
        }

        $figures = $this->figuresFor($year);

        if (bccomp($figures['wppf'], '0', 4) <= 0) {
            throw ValidationException::withMessages(['provision' => __('hr::wppf.no_profit')]);
        }

        $row = DB::transaction(function () use ($year, $by, $figures) {
            $row = WppfProvision::query()->where('financial_year_id', $year->id)->lockForUpdate()->first();

            if ($row?->isPosted()) {
                throw ValidationException::withMessages(['provision' => __('hr::wppf.already_posted')]);
            }

            $values = [
                'company_id' => CompanyContext::id(),
                'financial_year_id' => $year->id,
                'period_from' => $year->starts_on->toDateString(),
                'period_to' => $year->ends_on->toDateString(),
                // ⓘ ভিত্তি (তহবিলের খরচের আগের নিট মুনাফা) আর ভাগগুলো — পর্দার বাকি দুই ঘর সারিতে নয়
                ...array_intersect_key($figures, array_flip(['net_profit', 'wppf', 'participation_fund', 'welfare_fund', 'foundation_fund'])),
                'status' => WppfProvision::DRAFT,
            ];

            if ($row === null) {
                return WppfProvision::query()->create([...$values, 'created_by' => $by->id]);
            }

            // ⓘ খাতা বদলালে নতুন অঙ্ক — ছাপ বদলায়, তাই পুরনো সই আর খাটে না ([[DocumentApproval::stopping()]])
            $row->fill($values);

            if ($row->isDirty()) {
                $row->save();
            }

            return $row;
        });

        $print = $this->approvals->assertClear(
            document: $row,
            module: self::MODULE,
            action: self::ACTION,
            field: 'provision',
            amount: (string) $row->wppf,
            reason: __('hr::wppf.asked', ['year' => $year->name]),
        );

        return DB::transaction(function () use ($row, $by, $print, $year) {
            $locked = WppfProvision::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPosted()) {
                throw ValidationException::withMessages(['provision' => __('hr::wppf.already_posted')]);
            }

            $this->approvals->assertUnchangedSince($locked, $print, 'provision');

            [$expense, $payable] = $this->accounts();
            $branch = Company::query()->find(CompanyContext::id())?->defaultBranch()?->id;
            $narration = __('hr::wppf.narration', ['year' => $year->name]);
            $amount = (string) $locked->wppf;

            $this->posting->post(
                sourceType: WppfProvision::SOURCE_TYPE,
                sourceId: (int) $locked->id,
                trxDate: $year->ends_on->toDateString(),
                lines: [
                    ['account_id' => (int) $expense->id, 'debit' => $amount, 'narration' => $narration, 'branch_id' => $branch],
                    ['account_id' => (int) $payable->id, 'credit' => $amount, 'narration' => $narration, 'branch_id' => $branch],
                ],
                branchId: $branch,
                userId: (int) $by->id,
            );

            $locked->forceFill(['status' => WppfProvision::POSTED, 'posted_by' => $by->id, 'posted_at' => now()])->save();

            return $locked->fresh();
        });
    }

    /** @return array{0: Account, 1: Account} খরচ আর দেনা — ছকে না থাকলে ছকটা আবার বসে (যা নেই কেবল তা) */
    private function accounts(): array
    {
        $find = fn (): array => [StandardChart::accountFor(StandardChart::WPPF_EXPENSE), StandardChart::accountFor(StandardChart::WPPF_PAYABLE)];

        try {
            return $find();
        } catch (ValidationException) {
            app(StandardChart::class)->install();
        }

        try {
            return $find();
        } catch (ValidationException) {
            throw ValidationException::withMessages(['provision' => __('hr::wppf.no_account')]);
        }
    }
}
