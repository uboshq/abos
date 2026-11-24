<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services\MonthClose;

use App\Core\Contracts\PayableOpenBills;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Core\Support\ViewedBranch;
use App\Models\Branch;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\MonthEndChecklist;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\CreditControl\Services\ReceivableCreditLoss;
use App\Modules\Customer\Models\Customer;
use App\Modules\Finance\Models\Budget;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesTarget;
use App\Modules\Sales\Services\SalesResponsibility;
use App\Modules\Supplier\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ মাস শেষের হিসাব — মালিকের অনুমোদিত ১১ অংশ (১৭ নভেম্বর ২০২৬, "Ok" তারপর "Sob ok"): মাসের খবর, লাভ-ক্ষতি (পাঁচ তুলনা), বিক্রি,
 * টাকা এল-গেল ও মিল, স্থির খরচ বনাম বাজেট, পাওনা (বয়স ও সম্ভাব্য ক্ষতি), দেনা, মজুদ, মূসক ও কর, বেতন, মাস বন্ধের চেকলিস্ট, আর
 * মাস তালা ও সই।
 *
 * ── ⛔ নতুন করে কিছু গোনা হয় না ──────────────────────────────────────────
 * লাভ-ক্ষতির প্রতিটা লাইন খাতার আয়-খরচের খাত থেকে ([[MonthBooks::profitAndLossByAccount()]] — লাভ-ক্ষতির পাতার একই নিয়ম), তাই
 * "নিট মুনাফা" = লাভ-ক্ষতির পাতার নিট লাভ, পয়সায়। বিক্রির ভাগ বিক্রয়ের নিজের সংজ্ঞা ([[SalesMetrics]]), বয়সের ভাগ গ্রাহকের বয়সের
 * রিপোর্ট (`customer.ageing`), চেকলিস্ট মাস বন্ধের পাতার তালিকা ([[MonthEndChecklist]]) — মাস বন্ধ যেমন ছিল তেমনই।
 *
 * ⓘ মোট কেবল পাকা কাগজের (খসড়া, অপেক্ষায়, বাতিল কখনো নয়) — খাতায় কেবল পাকা কাগজ বসে, আর বিক্রয়ের সংজ্ঞাও `posted()`।
 * ⓘ শাখা: হেডারে বাছা শাখা সবখানে; বাজেট শাখা ধরে লেখা হয় না, তাই এক শাখা বাছা থাকলে বাজেটের ঘর ফাঁকা (ভুল তুলনার চেয়ে ফাঁকা ভালো)।
 */
final class MonthClosePack
{
    /**
     * ⓘ সম্ভাব্য ক্ষতির খরচের খাত — [[StandardChart::IMPAIRMENT_LOSS]] (5228)।
     * ⛔ আগে এখানে '5223' লেখা ছিল, যেটা এখন WPPF-এর খরচ ([[StandardChart::WPPF_EXPENSE]]) — মাস শেষের লাভ-ক্ষতিতে শ্রমিকের মুনাফার
     * ভাগ "সম্ভাব্য ক্ষতি" হয়ে বসত। এখন খাতের নিজের ধ্রুবক।
     */
    public const IMPAIRMENT_LOSS = StandardChart::IMPAIRMENT_LOSS;

    /** ⓘ বয়সের ভাগ → হারের সেটিং (ঐ শাখার [[ReceivableCreditLoss::RATES]]) আর মালিকের অনুমোদিত হার (২১ অক্টোবর ২০২৬: 1/3/10/50) */
    public const ECL_RATES = [
        'bucket_current' => [ReceivableCreditLoss::RATES['bucket_current'], ReceivableCreditLoss::DEFAULTS['bucket_current']],
        'bucket_30' => [ReceivableCreditLoss::RATES['bucket_30'], ReceivableCreditLoss::DEFAULTS['bucket_30']],
        'bucket_60' => [ReceivableCreditLoss::RATES['bucket_60'], ReceivableCreditLoss::DEFAULTS['bucket_60']],
        'bucket_90' => [ReceivableCreditLoss::RATES['bucket_90'], ReceivableCreditLoss::DEFAULTS['bucket_90']],
    ];

    /** ডেলিভারির খরচ — গাড়ি আর শ্রম */
    public const DELIVERY = [StandardChart::FUEL, StandardChart::VEHICLE_HIRE, StandardChart::LOADING, StandardChart::UNLOADING, StandardChart::HAMMALI];

    /** স্থির খরচের খাত — মাস ঘুরলেই আসে, বিক্রির সাথে ওঠানামা করে না */
    public const FIXED = [
        'salary' => [StandardChart::SALARY_EXPENSE],
        'rent' => [StandardChart::RENT],
        'utilities' => ['5203'],
        'marketing' => [StandardChart::MARKETING, StandardChart::PROMOTION_EXPENSE],
        'insurance' => [StandardChart::INSURANCE_PREMIUM],
        'depreciation' => [StandardChart::DEPRECIATION_EXPENSE],
        'interest' => [StandardChart::INTEREST_EXPENSE],
    ];

    /** লাভ-ক্ষতির পাঁচ ঘর */
    public const COLUMNS = ['month', 'prior', 'last_year', 'budget', 'ytd'];

    private MonthBooks $books;

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly SettingsService $settings,
    ) {
        $this->books = new MonthBooks;
    }

    /** @return array<string, mixed> */
    public function build(MonthPeriods $p): array
    {
        $this->books = new MonthBooks;
        $pl = $this->profitAndLoss($p);
        $sales = $this->sales($p);
        $money = $this->money($p);
        $receivables = $this->receivables($p);
        $payables = $this->payables($p);
        $stock = $this->stock($p);
        $vat = $this->vat($p);
        $checks = $this->checklist($p, $pl, $receivables);
        $lock = $this->lock($p);

        $pack = [
            'period' => $this->periodFacts($p, $lock),
            'pl' => $pl,
            'sales' => $sales,
            'money' => $money,
            'fixed' => $this->fixedVsBudget($pl),
            'receivables' => $receivables,
            'payables' => $payables,
            'stock' => $stock,
            'vat' => $vat,
            'payroll' => $this->payroll($p),
            'checks' => $checks,
            'lock' => $lock,
        ];

        $pack['news'] = $this->news($pack, $p);

        return $pack;
    }

    // ─────────────────────────────── মাথা ───────────────────────────────

    /** @return array<string, mixed> */
    private function periodFacts(MonthPeriods $p, array $lock): array
    {
        $branch = ViewedBranch::one();

        return [
            'key' => $p->key(),
            'label' => $this->monthLabel($p->month),
            'label_en' => strtoupper(__('executive::month_close.months.'.$p->month->month, [], 'en')).' '.$p->month->year,
            'from' => $p->from->toDateString(),
            'to' => $p->to->toDateString(),
            'range' => __('executive::month_close.range', ['from' => $p->from->format('d-m'), 'to' => $p->to->format('d-m')]),
            'working_days' => $p->workingDays(),
            'branch' => $branch !== null ? (string) Branch::query()->whereKey($branch)->first()?->name() : (string) __('executive::month_close.all_branches'),
            'one_branch' => $branch !== null,
            'closed_by' => $lock['by'],
            'printed_by' => (string) auth()->user()?->name,
            'printed_at' => now()->format('d-m-Y, h:i A'),
            'columns' => [
                'month' => __('executive::month_close.col.month'),
                'prior' => __('executive::month_close.col.prior'),
                'last_year' => $this->monthLabel($p->lastYear()->month),
                'budget' => __('executive::month_close.col.budget'),
                'ytd' => __('executive::month_close.col.ytd', [
                    'from' => __('executive::month_close.months_short.'.$p->yearStart()->month),
                    'to' => __('executive::month_close.months_short.'.$p->month->month),
                ]),
            ],
        ];
    }

    public function monthLabel(CarbonImmutable $month): string
    {
        return __('executive::month_close.months.'.$month->month).' '.$month->year;
    }

    // ─────────────────────────────── ১ লাভ-ক্ষতি ───────────────────────────────

    /** @return array<string, mixed> */
    private function profitAndLoss(MonthPeriods $p): array
    {
        $figures = [
            'month' => $this->plFigures($p->from->toDateString(), $p->to->toDateString()),
            'prior' => $this->plFigures($p->prior()->from->toDateString(), $p->prior()->to->toDateString()),
            'last_year' => $this->plFigures($p->lastYear()->from->toDateString(), $p->lastYear()->to->toDateString()),
            'budget' => ViewedBranch::one() === null ? $this->budgetFigures($p) : null,
            'ytd' => $this->plFigures($p->yearStart()->toDateString(), $p->to->toDateString()),
        ];

        $lines = [
            ['gross_bill', ''], ['returns', 'less'], ['vat', 'less'], ['discount', 'less'], ['net_sales', 'sub'],
            ['cogs', 'less'], ['gross_profit', 'sub'], ['delivery', 'less'], ['contribution', 'sub'], ['other_income', 'add'],
            ['other_expense', 'less'], ['fixed', 'less'], ['ecl', 'less'],
        ];

        $rows = [];

        foreach ($lines as [$key, $style]) {
            $values = [];
            $any = false;

            foreach (self::COLUMNS as $col) {
                $v = $figures[$col][$key] ?? null;
                $values[$col] = $v;
                $any = $any || ($v !== null && bccomp($v, '0', 4) !== 0);
            }

            // ⓘ আদায়ে ছাড় আর অন্যান্য আয় কেবল থাকলে — নমুনার সারিগুলো সবসময়
            if (! $any && in_array($key, ['discount', 'other_income'], true)) {
                continue;
            }

            $rows[] = ['key' => $key, 'style' => $style, 'values' => $values];
        }

        $margin = [];

        foreach (self::COLUMNS as $col) {
            $f = $figures[$col];
            $margin[$col] = $f === null ? null : $this->percent($f['net_profit'], $f['net_sales']);
        }

        return ['figures' => $figures, 'rows' => $rows, 'margin' => $margin];
    }

    /**
     * একটা সময়ের লাভ-ক্ষতি — খাতা থেকে, ভাগ করে। ⓘ প্রতিটা আয়-খরচের খাত ঠিক একটা ভাগে পড়ে, তাই
     * `net_profit` = সব আয়-খরচের খাতের `credit − debit` = লাভ-ক্ষতির পাতার নিট লাভ।
     *
     * @return array<string, string|array<string, string>>
     */
    public function plFigures(string $from, string $to): array
    {
        $byAccount = $this->books->profitAndLossByAccount($from, $to);
        $buckets = $this->classify($byAccount);

        [$vatBill, $vatReturn] = $this->salesVat($from, $to);

        $sales = $buckets['sales'];
        $returns = $this->neg($buckets['returns']);
        $discount = $this->neg($buckets['discount']);
        $netSales = bcsub(bcsub($sales, $returns, 4), $discount, 4);
        $cogs = $this->neg($buckets['cogs']);
        $gross = bcsub($netSales, $cogs, 4);
        $delivery = $this->neg($buckets['delivery']);
        $contribution = bcsub($gross, $delivery, 4);
        $otherIncome = $buckets['other_income'];
        $otherExpense = $this->neg($buckets['other_expense']);
        $ecl = $this->neg($buckets['ecl']);

        $fixedHeads = [];
        $fixed = '0.0000';

        foreach (array_keys(self::FIXED) as $head) {
            $fixedHeads[$head] = $this->neg($buckets['fixed:'.$head]);
            $fixed = bcadd($fixed, $fixedHeads[$head], 4);
        }

        $net = bcsub(bcsub(bcsub(bcadd($contribution, $otherIncome, 4), $otherExpense, 4), $fixed, 4), $ecl, 4);

        return [
            'gross_bill' => bcadd($sales, $vatBill, 4),
            'returns' => bcadd($returns, $vatReturn, 4),
            'vat' => bcsub($vatBill, $vatReturn, 4),
            // ⓘ খাতার বিক্রি (মূসক বাদে) − ফেরত — নিচের মূসকের লাইন দুটোকেই মূসকসহ দেখায়, তাই বিয়োগে নিট বিক্রিতে ফেরে
            'sales_books' => bcsub($sales, $returns, 4),
            'discount' => $discount,
            'net_sales' => $netSales,
            'cogs' => $cogs,
            'gross_profit' => $gross,
            'delivery' => $delivery,
            'contribution' => $contribution,
            'other_income' => $otherIncome,
            'other_expense' => $otherExpense,
            'fixed' => $fixed,
            'fixed_heads' => $fixedHeads,
            'ecl' => $ecl,
            'net_profit' => $net,
        ];
    }

    /**
     * বাজেট — এই মাসের, খাত ধরে লেখা অঙ্ক একই ভাগে ([[BudgetService]]-এর `fin_budgets`)। ⓘ মূসক আর ফেরতের বাজেট লেখা হয় না, তাই
     * সেই ঘর ফাঁকা (`null`); বিক্রির বাজেট নিট বিক্রি।
     *
     * @return array<string, string|array<string, string>|null>|null
     */
    private function budgetFigures(MonthPeriods $p): ?array
    {
        $rows = Budget::query()->where('year', $p->month->year)->where('month', $p->month->month)
            ->groupBy('account_id')->selectRaw('account_id, SUM(amount) as amount')->toBase()->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $byAccount = [];

        foreach ($rows as $row) {
            $type = (string) ($this->books->accounts()[(int) $row->account_id]->type ?? '');
            // ⓘ বাজেট খাতের স্বভাবে ধনাত্মক — আয়ে credit − debit-এর চিহ্নে, খরচে উল্টো
            $byAccount[(int) $row->account_id] = $type === Account::INCOME ? (string) $row->amount : bcmul((string) $row->amount, '-1', 4);
        }

        $b = $this->classify($byAccount);
        $netSales = bcsub(bcsub($b['sales'], $this->neg($b['returns']), 4), $this->neg($b['discount']), 4);
        $cogs = $this->neg($b['cogs']);
        $gross = bcsub($netSales, $cogs, 4);
        $delivery = $this->neg($b['delivery']);
        $fixedHeads = [];
        $fixed = '0.0000';

        foreach (array_keys(self::FIXED) as $head) {
            $fixedHeads[$head] = $this->neg($b['fixed:'.$head]);
            $fixed = bcadd($fixed, $fixedHeads[$head], 4);
        }

        $contribution = bcsub($gross, $delivery, 4);
        $otherExpense = $this->neg($b['other_expense']);
        $ecl = $this->neg($b['ecl']);

        return [
            'gross_bill' => null, 'returns' => null, 'vat' => null, 'sales_books' => null,
            'discount' => $this->neg($b['discount']),
            'net_sales' => $netSales, 'cogs' => $cogs, 'gross_profit' => $gross, 'delivery' => $delivery,
            'contribution' => $contribution, 'other_income' => $b['other_income'], 'other_expense' => $otherExpense,
            'fixed' => $fixed, 'fixed_heads' => $fixedHeads, 'ecl' => $ecl,
            'net_profit' => bcsub(bcsub(bcsub(bcadd($contribution, $b['other_income'], 4), $otherExpense, 4), $fixed, 4), $ecl, 4),
        ];
    }

    /**
     * খাত → ভাগ। ⓘ ক্রম গুরুত্বপূর্ণ: ফেরত আর ছাড় বিক্রির আগে (ছকে তারা বিক্রির পাশে, নিচে বসলেও যেন ভুল ভাগে না যায়)।
     *
     * @param  array<int, string>  $byAccount  খাত → `credit − debit`
     * @return array<string, string>
     */
    private function classify(array $byAccount): array
    {
        $groups = [
            'returns' => $this->books->subtree([StandardChart::SALES_RETURN]),
            'discount' => $this->books->subtree([StandardChart::COLLECTION_DISCOUNT]),
            'sales' => $this->books->subtree([StandardChart::SALES]),
            'cogs' => $this->books->subtree([StandardChart::COST_OF_GOODS_SOLD, StandardChart::PURCHASE_PRICE_VARIANCE]),
            'delivery' => $this->books->subtree(self::DELIVERY),
            'ecl' => $this->books->subtree([self::IMPAIRMENT_LOSS]),
        ];

        foreach (self::FIXED as $head => $codes) {
            $groups['fixed:'.$head] = $this->books->subtree($codes);
        }

        $out = array_fill_keys([...array_keys($groups), 'other_income', 'other_expense'], '0.0000');

        foreach ($byAccount as $id => $net) {
            $bucket = null;

            foreach ($groups as $name => $ids) {
                if (in_array($id, $ids, true)) {
                    $bucket = $name;
                    break;
                }
            }

            $bucket ??= ($this->books->accounts()[$id]->type ?? null) === Account::INCOME ? 'other_income' : 'other_expense';
            $out[$bucket] = bcadd($out[$bucket], $net, 4);
        }

        return $out;
    }

    /**
     * বিলের মূসক আর ফেরতের মূসক — বিক্রয়ের সংজ্ঞার একই ভিত (পাকা বিল/ফেরত, নিজের তারিখে, মডেলের শাখা-দেয়াল)।
     *
     * @return array{0: string, 1: string}
     */
    private function salesVat(string $from, string $to): array
    {
        return [
            Money::of(SalesInvoice::query()->posted()->whereBetween('trx_date', [$from, $to])->sum('tax')),
            Money::of(SalesReturn::query()->posted()->whereBetween('sal_returns.trx_date', [$from, $to])->sum('sal_returns.tax')),
        ];
    }

    // ─────────────────────────────── ২ বিক্রি ───────────────────────────────

    /** @return array<string, mixed> */
    private function sales(MonthPeriods $p): array
    {
        $from = $p->from->toDateString();
        $to = $p->to->toDateString();
        $parts = SalesMetrics::netParts($from, $to);
        $prior = SalesMetrics::netParts($p->prior()->from->toDateString(), $p->prior()->to->toDateString());

        return [
            'parts' => $parts,
            'prior' => $prior,
            'change' => $this->change($parts['gross'], bccomp($prior['gross'], '0', 4) === 0 ? null : $prior['gross']),
            'branches' => $this->byBranch($p, $parts),
            'groups' => $this->byGroup($from, $to, $parts),
            'sellers' => $this->bySeller($p),
            'top' => $this->topCustomers($from, $to, $parts),
            'kinds' => $this->kinds($from, $to, $parts),
            'margin' => $this->percent(bcsub($parts['net'], $this->cost($from, $to), 4), $parts['net']),
        ];
    }

    /** বিক্রীত মালের দাম, বিলের কাগজে — পাকা বিলের `cost_of_goods` বাদ পাকা ফেরতের */
    private function cost(string $from, string $to, ?int $branch = null): string
    {
        $bills = SalesInvoice::query()->posted()->whereBetween('trx_date', [$from, $to])
            ->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))->sum('cost_of_goods');
        $back = SalesReturn::query()->posted()->whereBetween('sal_returns.trx_date', [$from, $to])
            ->when($branch !== null, fn ($q) => $q->where('sal_returns.branch_id', $branch))->sum('sal_returns.cost_of_goods');

        return bcsub(Money::of($bills), Money::of($back), 4);
    }

    /** @return list<array<string, mixed>> */
    private function byBranch(MonthPeriods $p, array $parts): array
    {
        $now = SalesMetrics::netByBranch($p->from->toDateString(), $p->to->toDateString());
        $before = SalesMetrics::netByBranch($p->prior()->from->toDateString(), $p->prior()->to->toDateString());
        $names = Branch::query()->whereIn('id', array_keys($now) ?: [0])->get()->keyBy('id');
        $rows = [];

        foreach ($now as $id => $part) {
            $cost = $this->cost($p->from->toDateString(), $p->to->toDateString(), $id === 0 ? null : $id);

            $rows[] = [
                'name' => $id === 0 ? (string) __('executive::month_close.no_branch') : (string) $names->get($id)?->name(),
                'bill' => $part['gross'],
                'margin' => $id === 0 ? null : $this->percent(bcsub($part['net'], $cost, 4), $part['net']),
                'change' => $this->change($part['gross'], $before[$id]['gross'] ?? null),
            ];
        }

        usort($rows, fn ($a, $b) => bccomp($b['bill'], $a['bill'], 4));

        return $rows;
    }

    /**
     * পণ্যের দল ধরে — নিট বিক্রি ([[SalesMetrics::netByProduct()]]), বিক্রীত মালের দাম বিলের সারির `unit_cost` থেকে; সবচেয়ে বড়
     * পাঁচটা দল, বাকিটা "অন্যান্য"।
     *
     * @return list<array<string, mixed>>
     */
    private function byGroup(string $from, string $to, array $parts): array
    {
        $sold = SalesMetrics::netByProduct($from, $to)->get();

        if ($sold->isEmpty()) {
            return [];
        }

        $billIds = SalesInvoice::query()->posted()->whereBetween('trx_date', [$from, $to])->select('sal_invoices.id');
        $returnIds = SalesReturn::query()->posted()->whereBetween('sal_returns.trx_date', [$from, $to])->select('sal_returns.id');

        $cost = [];

        foreach (DB::table('sal_invoice_lines as il')->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
            ->where('i.company_id', CompanyContext::id())->whereIn('il.sales_invoice_id', $billIds)
            ->groupBy('il.product_id')->selectRaw('il.product_id, COALESCE(SUM(il.qty * il.unit_cost), 0) as c')->get() as $row) {
            $cost[(int) $row->product_id] = (string) $row->c;
        }

        foreach (DB::table('sal_return_lines as rl')->join('sal_invoice_lines as il', 'il.id', '=', 'rl.sales_invoice_line_id')
            ->where('rl.company_id', CompanyContext::id())->whereIn('rl.sales_return_id', $returnIds)
            ->groupBy('rl.product_id')->selectRaw('rl.product_id, COALESCE(SUM(rl.qty * il.unit_cost), 0) as c')->get() as $row) {
            $cost[(int) $row->product_id] = bcsub($cost[(int) $row->product_id] ?? '0', (string) $row->c, 4);
        }

        $products = Product::query()->with(['categoryRow', 'unit'])->whereIn('id', $sold->pluck('product_id')->all())->get()->keyBy('id');
        $groups = [];

        foreach ($sold as $row) {
            $product = $products->get((int) $row->product_id);
            $category = $product?->categoryRow;
            $key = $category !== null ? 'c'.$category->id : 'none';
            $name = $category !== null
                ? (app()->getLocale() === 'bn' && filled($category->name_bn) ? (string) $category->name_bn : (string) $category->name_en)
                : (string) __('executive::month_close.no_group');
            $unit = $product?->unit !== null ? (string) (app()->getLocale() === 'bn' && filled($product->unit->name_bn) ? $product->unit->name_bn : $product->unit->code) : '';

            $groups[$key] ??= ['name' => $name, 'qty' => '0', 'units' => [], 'amount' => '0', 'cost' => '0'];
            $groups[$key]['qty'] = bcadd($groups[$key]['qty'], (string) $row->qty, 4);
            $groups[$key]['units'][$unit] = true;
            $groups[$key]['amount'] = bcadd($groups[$key]['amount'], (string) $row->amount, 4);
            $groups[$key]['cost'] = bcadd($groups[$key]['cost'], $cost[(int) $row->product_id] ?? '0', 4);
        }

        usort($groups, fn ($a, $b) => bccomp($b['amount'], $a['amount'], 4));

        if (count($groups) > 6) {
            $rest = ['name' => (string) __('executive::month_close.other_groups'), 'qty' => '0', 'units' => ['' => true, '-' => true], 'amount' => '0', 'cost' => '0'];

            foreach (array_splice($groups, 5) as $g) {
                $rest['amount'] = bcadd($rest['amount'], $g['amount'], 4);
                $rest['cost'] = bcadd($rest['cost'], $g['cost'], 4);
            }

            $groups[] = $rest;
        }

        return array_map(fn (array $g) => [
            'name' => $g['name'],
            // ⓘ এক দলে একাধিক একক হলে পরিমাণ যোগ করা অর্থহীন — ড্যাশ
            'qty' => count($g['units']) === 1 ? Money::quantity($g['qty']).(array_key_first($g['units']) !== '' ? ' '.array_key_first($g['units']) : '') : null,
            'amount' => $g['amount'],
            'margin' => $this->percent(bcsub($g['amount'], $g['cost'], 4), $g['amount']),
        ], $groups);
    }

    /**
     * এসআর ধরে — নিট বিক্রি (⭐ বিল আর ফেরতে লেখা দায়িত্বপ্রাপ্ত জন, [[SalesMetrics::sellerOfInvoiceSql()]] /
     * [[SalesMetrics::sellerOfReturnSql()]]; মালিক, ২৩ নভেম্বর ২০২৬ — বিল যিনি লিখেছেন নন), মাসের লক্ষ্য ([[SalesTarget]]), অর্জন,
     * আর তাঁর ডিলারদের আদায়; এরিয়া = তাঁর সবচেয়ে বড় দুই গ্রাহকের পয়েন্ট।
     *
     * @return array{rows: list<array<string, mixed>>, target: string, net: string, collected: string}
     */
    private function bySeller(MonthPeriods $p): array
    {
        $from = $p->from->toDateString();
        $to = $p->to->toDateString();
        $sold = SalesMetrics::netBy($from, $to, SalesMetrics::sellerOfInvoiceSql(), SalesMetrics::sellerOfReturnSql());
        $targets = SalesTarget::query()->forMonth($p->month->toDateString())->get()
            ->groupBy('user_id')->map(fn ($rows) => $rows->reduce(fn (string $s, $t) => bcadd($s, (string) $t->amount, 4), '0'));

        /*
         * ⭐ আদায় — ডিলার ধরে, তারপর মাসের শেষ দিনে ডিলারের দায়িত্বপ্রাপ্ত জনের ঘরে ([[SalesResponsibility::forMany()]]; মালিক,
         * ২৩ নভেম্বর ২০২৬)। ⛔ আগে রসিদ যিনি লিখেছেন (`created_by`) — অথচ রসিদ লেখে অফিস, এসআর নন ("এসআর আদায় নেন না"),
         * তাই এসআরের আদায় ঘর প্রায় সবসময় শূন্য দেখাত।
         */
        $byCustomer = [];

        foreach ([
            Collection::query()->posted()->whereBetween('trx_date', [$from, $to])->groupBy('customer_id')->selectRaw('customer_id as c, SUM(amount) as a')->toBase()->get(),
            Voucher::query()->where('type', Voucher::RECEIPT)->where('party_type', Customer::drillSourceType())->posted()
                ->whereBetween('trx_date', [$from, $to])->groupBy('party_id')->selectRaw('party_id as c, SUM(amount) as a')->toBase()->get(),
        ] as $set) {
            foreach ($set as $row) {
                if ($row->c !== null) {
                    $byCustomer[(int) $row->c] = bcadd($byCustomer[(int) $row->c] ?? '0', (string) $row->a, 4);
                }
            }
        }

        $collected = [];
        $people = $byCustomer === [] ? [] : app(SalesResponsibility::class)->forMany(array_keys($byCustomer), $to);

        foreach ($byCustomer as $customer => $amount) {
            $person = $people[$customer] ?? null;

            if ($person !== null) {
                $collected[(int) $person] = bcadd($collected[(int) $person] ?? '0', $amount, 4);
            }
        }

        $ids = array_values(array_unique(array_filter([...array_map('intval', array_keys($sold)), ...array_map('intval', $targets->keys()->all())])));
        $users = User::query()->whereIn('id', $ids ?: [0])->get()->keyBy('id');
        $areas = $this->sellerAreas($from, $to, $ids);
        $rows = [];
        $total = ['target' => '0', 'net' => '0', 'collected' => '0'];

        foreach ($ids as $id) {
            $net = $sold[(string) $id]['net'] ?? '0.0000';
            $target = $targets->get($id);
            $rows[] = [
                'name' => (string) ($users->get($id)?->name ?? '#'.$id),
                'area' => $areas[$id] ?? '',
                'target' => $target,
                'net' => $net,
                'achieved' => $target === null ? null : $this->percent($net, $target),
                'collected' => $collected[$id] ?? '0.0000',
            ];
            $total['target'] = bcadd($total['target'], $target ?? '0', 4);
            $total['net'] = bcadd($total['net'], $net, 4);
            $total['collected'] = bcadd($total['collected'], $collected[$id] ?? '0', 4);
        }

        usort($rows, fn ($a, $b) => bccomp($b['net'], $a['net'], 4));

        return ['rows' => $rows] + $total + ['achieved' => bccomp($total['target'], '0', 4) > 0 ? $this->percent($total['net'], $total['target']) : null];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function sellerAreas(string $from, string $to, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // ⓘ বিলে লেখা দায়িত্বপ্রাপ্ত জন ধরে (মালিক, ২৩ নভেম্বর ২০২৬) — বিল যিনি লিখেছেন নন
        $pairs = SalesInvoice::query()->posted()->whereBetween('trx_date', [$from, $to])->whereIn('sales_person_id', $ids)
            ->groupBy('sales_person_id', 'customer_id')->selectRaw('sales_person_id, customer_id, SUM(total) as t')->orderByDesc('t')->toBase()->get();
        $customers = Customer::query()->with(Customer::PLACE)->whereIn('id', $pairs->pluck('customer_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $out = [];

        foreach ($pairs->groupBy('sales_person_id') as $seller => $rows) {
            $points = [];

            foreach ($rows as $row) {
                $point = $customers->get((int) $row->customer_id)?->pointName();

                if ($point !== null && ! in_array($point, $points, true)) {
                    $points[] = $point;
                }

                if (count($points) === 2) {
                    break;
                }
            }

            $out[(int) $seller] = implode(' · ', $points);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function topCustomers(string $from, string $to, array $parts): array
    {
        $by = SalesMetrics::netBy($from, $to, 'sal_invoices.customer_id', 'sal_returns.customer_id');
        uasort($by, fn ($a, $b) => bccomp($b['gross'], $a['gross'], 4));
        $top = array_slice($by, 0, 10, true);
        $customers = Customer::query()->with(Customer::PLACE)->whereIn('id', array_map('intval', array_keys($top)) ?: [0])->get()->keyBy('id');
        $rows = [];

        foreach ($top as $id => $part) {
            $c = $customers->get((int) $id);
            $rows[] = [
                'name' => $c?->name() ?? '#'.$id,
                'point' => $c?->placeName(),
                'bill' => $part['gross'],
                'share' => $this->percent($part['gross'], $parts['gross']),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function kinds(string $from, string $to, array $parts): array
    {
        [$vatBill, $vatReturn] = $this->salesVat($from, $to);
        $returns = SalesReturn::query()->posted()->whereBetween('sal_returns.trx_date', [$from, $to])->orderBy('sal_returns.id');

        return [
            'billed' => bcadd($parts['billed'], $vatBill, 4),
            'returned' => bcadd($parts['returned'], $vatReturn, 4),
            'return_count' => (clone $returns)->count(),
            'return_nos' => (clone $returns)->limit(4)->pluck('document_no')->filter()->values()->all(),
            'after_returns' => $parts['gross'],
            'collected' => SalesMetrics::collectionTotal($from, $to),
            'due_change' => SalesMetrics::receivableMovement($from, $to),
            'bills' => SalesInvoice::query()->posted()->whereBetween('trx_date', [$from, $to])->count(),
            'buyers' => SalesInvoice::query()->posted()->whereBetween('trx_date', [$from, $to])->distinct()->count('customer_id'),
        ];
    }

    // ─────────────────────────────── ৩ টাকা ───────────────────────────────

    /** @return array<string, mixed> */
    private function money(MonthPeriods $p): array
    {
        $from = $p->from->toDateString();
        $to = $p->to->toDateString();
        $accounts = $this->books->accounts();
        $order = array_flip(Account::MONEY_KINDS);
        $rows = [];
        $total = ['opening' => '0', 'in' => '0', 'out' => '0', 'closing' => '0'];

        foreach ($this->books->perAccount($this->books->moneyAccounts(), $from, $to) as $id => $m) {
            $closing = bcsub(bcadd($m['opening'], $m['in'], 4), $m['out'], 4);

            if (bccomp($m['opening'], '0', 4) === 0 && bccomp($m['in'], '0', 4) === 0 && bccomp($m['out'], '0', 4) === 0) {
                continue;
            }

            $a = $accounts[$id];
            $rows[] = [
                'id' => $id, 'kind' => (string) $a->money_kind, 'code' => (string) $a->code,
                'name' => app()->getLocale() === 'bn' && filled($a->name_bn) ? (string) $a->name_bn : (string) $a->name_en,
                'opening' => $m['opening'], 'in' => $m['in'], 'out' => $m['out'], 'closing' => $closing,
            ] + $this->reconciled((int) $id, (string) $a->money_kind, $closing, $from, $to);

            foreach (['opening', 'in', 'out', 'closing'] as $k) {
                $total[$k] = bcadd($total[$k], $k === 'closing' ? $closing : $m[$k], 4);
            }
        }

        usort($rows, fn ($a, $b) => [$order[$a['kind']] ?? 9, $a['code']] <=> [$order[$b['kind']] ?? 9, $b['code']]);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * ব্যাংক ও নগদের মিল — ব্যাংক বা MFS: মাসের সবশেষ নিশ্চিত মিলকরণের স্টেটমেন্টের জের; নগদ: ঐ খাতের টিলের মাসের সবশেষ নিশ্চিত
     * গোনা। ⓘ মিলকরণ আর গোনা কোম্পানি ধরে ([[MonthEndChecklist]]-এর মতোই সব শাখা জুড়ে)।
     *
     * @return array{counted: ?string, state: string, note: string}
     */
    private function reconciled(int $accountId, string $kind, string $book, string $from, string $to): array
    {
        if ($kind === Account::CASH) {
            $tills = CashTill::query()->withoutGlobalScope('viewed-branch')->where('account_id', $accountId)->pluck('id');
            $count = $tills->isEmpty() ? null : CashCount::acrossBranches()->whereIn('cash_till_id', $tills)
                ->where('status', DocumentStatus::CONFIRMED)->whereBetween('trx_date', [$from, $to])
                ->orderByDesc('trx_date')->orderByDesc('id')->first();

            if ($count === null) {
                return ['counted' => null, 'state' => 'pending', 'note' => (string) __('executive::month_close.recon.not_counted')];
            }

            $gap = Money::of($count->difference);

            return [
                'counted' => Money::of($count->counted_amount),
                'state' => Money::isZero($gap) ? 'ok' : 'gap',
                'note' => Money::isZero($gap)
                    ? (string) __('executive::month_close.recon.counted_ok', ['date' => DateFormat::format($count->trx_date)])
                    : (string) __('executive::month_close.recon.counted_gap', ['amount' => Money::format($gap), 'date' => DateFormat::format($count->trx_date)]),
            ];
        }

        $rec = BankReconciliation::acrossBranches()->where('bank_account_id', $accountId)
            ->where('status', BankReconciliation::CONFIRMED)->whereBetween('statement_date', [$from, $to])
            ->orderByDesc('statement_date')->orderByDesc('id')->first();

        if ($rec === null) {
            return ['counted' => null, 'state' => 'pending', 'note' => (string) __('executive::month_close.recon.not_reconciled')];
        }

        $gap = bcsub(Money::of($rec->statement_balance), $book, 4);

        return [
            'counted' => Money::of($rec->statement_balance),
            'state' => Money::isZero($gap) ? 'ok' : 'gap',
            'note' => Money::isZero($gap)
                ? (string) __('executive::month_close.recon.bank_ok', ['date' => DateFormat::format($rec->statement_date)])
                : (string) __('executive::month_close.recon.bank_gap', ['amount' => Money::format($gap), 'date' => DateFormat::format($rec->statement_date)]),
        ];
    }

    // ─────────────────────────────── ৪ স্থির খরচ ───────────────────────────────

    /** @return array<string, mixed> */
    private function fixedVsBudget(array $pl): array
    {
        $actual = $pl['figures']['month']['fixed_heads'];
        $budget = $pl['figures']['budget']['fixed_heads'] ?? null;
        $rows = [];
        $total = ['actual' => '0', 'budget' => '0'];

        foreach (array_keys(self::FIXED) as $head) {
            $b = $budget[$head] ?? null;

            if (bccomp($actual[$head], '0', 4) === 0 && ($b === null || bccomp($b, '0', 4) === 0)) {
                continue;
            }

            $gap = $b === null ? null : bcsub($actual[$head], $b, 4);
            $rows[] = ['key' => $head, 'actual' => $actual[$head], 'budget' => $b, 'gap' => $gap,
                'over' => $gap === null ? null : bccomp($gap, '0', 4)];
            $total['actual'] = bcadd($total['actual'], $actual[$head], 4);
            $total['budget'] = bcadd($total['budget'], $b ?? '0', 4);
        }

        return ['rows' => $rows, 'total' => $total + ['gap' => $budget === null ? null : bcsub($total['actual'], $total['budget'], 4)], 'has_budget' => $budget !== null];
    }

    // ─────────────────────────────── ৫ পাওনা ───────────────────────────────

    /** @return array<string, mixed> */
    private function receivables(MonthPeriods $p): array
    {
        $from = $p->from->toDateString();
        $to = $p->to->toDateString();
        $ar = $this->books->subtree([StandardChart::RECEIVABLE]);
        $opening = $this->books->balanceBefore($ar, $from);
        $roll = $this->books->rollBySource($ar, $from, $to, [
            'sales' => $this->books->subtree([StandardChart::SALES]),
            'returns' => $this->books->subtree([StandardChart::SALES_RETURN]),
            'collections' => [...$this->books->moneyAccounts(), ...$this->books->subtree([StandardChart::CHEQUES_IN_HAND])],
        ]);
        $closing = array_reduce($roll, fn (string $s, string $v) => bcadd($s, $v, 4), $opening);

        $ageing = $this->ageing($to);
        $before = $this->ageing($p->prior()->to->toDateString());

        return [
            'opening' => $opening,
            'sales' => $roll['sales'],
            'collections' => bcmul($roll['collections'], '-1', 4),
            'returns' => bcmul($roll['returns'], '-1', 4),
            'other' => $roll['other'],
            'closing' => $closing,
            'rate' => $this->percent(bcmul($roll['collections'], '-1', 4), bcadd($opening, $roll['sales'], 4)),
            'ageing' => $ageing,
            'ecl_before' => $before['ecl'],
            'ecl_change' => bcsub($ageing['ecl'], $before['ecl'], 4),
            'oldest' => $this->oldest($ageing['rows'], $to),
        ];
    }

    /**
     * গ্রাহকের বয়স — বয়সের রিপোর্টই (আদায় সবচেয়ে পুরনো বকেয়া থেকে, অগ্রিম আলাদা), আর প্রতিটা ভাগের সম্ভাব্য ক্ষতি = বকেয়া × হার
     * (IFRS 9 সরল পদ্ধতি; হার কোম্পানির সেটিং, না থাকলে মালিকের 1/3/10/50)।
     *
     * @return array{buckets: list<array<string, string>>, total: string, ecl: string, advance: string, rows: list<array<string, mixed>>}
     */
    public function ageing(string $asOf): array
    {
        $result = $this->reports->run('customer.ageing', ['from' => '2000-01-01', 'to' => $asOf], 1, 100000);
        $buckets = [];
        $total = '0';
        $ecl = '0';

        foreach (self::ECL_RATES as $key => [$setting, $default]) {
            $due = Money::round((string) ($result->totals[$key] ?? '0'), 4);
            $rate = (string) $this->settings->get($setting, $default);
            $rate = is_numeric($rate) ? $rate : $default;
            $loss = Money::round(bcdiv(bcmul($due, $rate, 8), '100', 8), 2);
            $buckets[] = ['key' => $key, 'due' => $due, 'rate' => $rate, 'loss' => $loss];
            $total = bcadd($total, $due, 4);
            $ecl = bcadd($ecl, $loss, 4);
        }

        foreach ($buckets as $i => $b) {
            $buckets[$i]['share'] = $this->percent($b['due'], $total);
        }

        return [
            'buckets' => $buckets, 'total' => $total, 'ecl' => $ecl,
            'advance' => Money::round((string) ($result->totals['advance'] ?? '0'), 4),
            'rows' => array_map(fn ($r) => (array) $r, $result->rows),
        ];
    }

    /**
     * সবচেয়ে পুরনো বকেয়া — পুরনো ভাগে সবচেয়ে বেশি যাঁদের, পাঁচজন; বয়স = সবচেয়ে পুরনো না-মেটা বিলের দিন থেকে।
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function oldest(array $rows, string $asOf): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => bccomp((string) ($r['outstanding'] ?? '0'), '0', 4) > 0));
        usort($rows, fn ($a, $b) => bccomp((string) $b['bucket_90'], (string) $a['bucket_90'], 4)
            ?: bccomp((string) $b['bucket_60'], (string) $a['bucket_60'], 4)
            ?: bccomp((string) $b['bucket_30'], (string) $a['bucket_30'], 4));
        $rows = array_slice($rows, 0, 5);
        $customers = Customer::query()->with(Customer::PLACE)->whereIn('id', array_map(fn ($r) => (int) $r['party_id'], $rows) ?: [0])->get()->keyBy('id');
        $out = [];

        foreach ($rows as $r) {
            $id = (int) $r['party_id'];
            $since = $this->books->oldestUnpaidDate(null, Customer::drillSourceType(), $id, $asOf);
            $seller = SalesInvoice::query()->posted()->where('customer_id', $id)->where('trx_date', '<=', $asOf)
                // ⓘ শেষ বিলে লেখা দায়িত্বপ্রাপ্ত জন (মালিক, ২৩ নভেম্বর ২০২৬) — বিল যিনি লিখেছেন নন
                ->orderByDesc('trx_date')->orderByDesc('id')->value('sales_person_id');

            $out[] = [
                'name' => $customers->get($id)?->name() ?? '#'.$id,
                'point' => $customers->get($id)?->placeName(),
                'seller' => $seller !== null ? (string) User::query()->whereKey($seller)->value('name') : '',
                'amount' => (string) $r['outstanding'],
                'days' => $since === null ? null : (int) CarbonImmutable::parse($since)->diffInDays(CarbonImmutable::parse($asOf)),
            ];
        }

        usort($out, fn ($a, $b) => ($b['days'] ?? -1) <=> ($a['days'] ?? -1));

        return $out;
    }

    // ─────────────────────────────── ৬ দেনা ───────────────────────────────

    /** @return array<string, mixed> */
    private function payables(MonthPeriods $p): array
    {
        $from = $p->from->toDateString();
        $to = $p->to->toDateString();
        $ap = $this->books->subtree([StandardChart::PAYABLE]);
        $opening = bcmul($this->books->balanceBefore($ap, $from), '-1', 4);
        $move = $this->books->movement($ap, $from, $to);
        $closing = bcsub(bcadd($opening, $move['credit'], 4), $move['debit'], 4);
        $nextEnd = $p->month->addMonthNoOverflow()->endOfMonth()->toDateString();

        $owed = array_map(fn (string $n) => bcmul($n, '-1', 4), $this->books->byParty($ap, Supplier::drillSourceType(), $to));
        $owed = array_filter($owed, fn (string $v) => bccomp($v, '0', 4) > 0);
        arsort($owed, SORT_NUMERIC);
        $top = array_slice($owed, 0, 5, true);
        $names = Supplier::query()->whereIn('id', array_keys($top) ?: [0])->get()->keyBy('id');
        $rows = [];
        $dueTotal = '0';

        foreach ($top as $id => $amount) {
            $due = '0';
            $last = null;

            foreach (app(PayableOpenBills::class)->openBills(Supplier::drillSourceType(), (int) $id) as $bill) {
                if ((string) $bill['due_date'] <= $nextEnd) {
                    $due = bcadd($due, (string) $bill['outstanding'], 4);
                    $last = $last === null || (string) $bill['due_date'] < $last ? (string) $bill['due_date'] : $last;
                }
            }

            $rows[] = ['name' => (string) $names->get($id)?->name(), 'amount' => $amount, 'due_next' => $due, 'due_on' => $last];
            $dueTotal = bcadd($dueTotal, $due, 4);
        }

        return [
            'rows' => $rows, 'opening' => $opening, 'purchases' => $move['credit'], 'paid' => $move['debit'], 'closing' => $closing,
            'due_total' => $dueTotal, 'others' => max(0, count($owed) - count($top)),
            'cheques_out' => bcmul($this->books->balanceBefore($this->books->subtree([StandardChart::CHEQUES_ISSUED]), $p->to->addDay()->toDateString()), '-1', 4),
            'next_month' => $this->monthLabel($p->month->addMonthNoOverflow()),
        ];
    }

    // ─────────────────────────────── ৭ মজুদ ───────────────────────────────

    /** @return array<string, mixed> */
    private function stock(MonthPeriods $p): array
    {
        $from = $p->from->toDateString();
        $inv = $this->books->subtree([StandardChart::INVENTORY]);
        $opening = $this->books->balanceBefore($inv, $from);
        $roll = $this->books->rollBySource($inv, $from, $p->to->toDateString(), [
            'cogs' => $this->books->subtree([StandardChart::COST_OF_GOODS_SOLD, StandardChart::PURCHASE_PRICE_VARIANCE]),
            'shortage' => $this->books->subtree([StandardChart::INVENTORY_SHORTAGE_SURPLUS]),
        ]);

        $slow = app(StockFacts::class)->slowMovingList(60, 5)->map(fn (Product $product) => [
            'name' => $product->name(),
            'qty' => Money::quantity((string) $product->getAttribute('available_qty')).($product->unit !== null ? ' '.(app()->getLocale() === 'bn' && filled($product->unit->name_bn) ? $product->unit->name_bn : $product->unit->code) : ''),
            'value' => bcmul((string) $product->getAttribute('available_qty'), (string) ($product->purchase_price ?? '0'), 4),
        ])->values()->all();

        return [
            'opening' => $opening,
            'in' => $roll['other'],
            'cogs' => bcmul($roll['cogs'], '-1', 4),
            'shortage' => bcmul($roll['shortage'], '-1', 4),
            'closing' => bcadd(bcadd(bcadd($opening, $roll['other'], 4), $roll['cogs'], 4), $roll['shortage'], 4),
            'slow' => $slow,
        ];
    }

    // ─────────────────────────────── ৮ মূসক ও কর ───────────────────────────────

    /** @return array<string, mixed> */
    private function vat(MonthPeriods $p): array
    {
        $from = $p->from->toDateString();
        $to = $p->to->toDateString();
        [$bill, $back] = $this->salesVat($from, $to);
        $output = bcsub($bill, $back, 4);
        $input = $this->books->movement($this->books->subtree([StandardChart::INPUT_VAT_RECOVERABLE]), $from, $to)['debit'];

        return [
            'output' => $output,
            'input' => $input,
            'due' => bcsub($output, $input, 4),
            'due_on' => DateFormat::format($p->nextMonthDay(15)->toDateString()),
            'vds_by_customers' => $this->books->movement($this->books->subtree([StandardChart::VDS_RECEIVABLE]), $from, $to)['debit'],
            'vds_on_suppliers' => $this->books->movement($this->books->subtree([StandardChart::VDS_PAYABLE]), $from, $to)['credit'],
            'tds_on_suppliers' => $this->books->movement($this->books->subtree([StandardChart::TAX_DEDUCTED_AT_SOURCE]), $from, $to)['credit'],
        ];
    }

    // ─────────────────────────────── ৯ বেতন ───────────────────────────────

    /** @return array<string, mixed>|null মাসের পাকা বেতন না থাকলে null */
    private function payroll(MonthPeriods $p): ?array
    {
        $runs = PayrollRun::query()->where('month', $p->month->toDateString())->whereIn('status', DocumentStatus::POSTED)->get();

        if ($runs->isEmpty()) {
            return null;
        }

        $name = app()->getLocale() === 'bn' ? 'COALESCE(NULLIF(l.head_name_bn, \'\'), l.head_name_en)' : 'l.head_name_en';
        $lines = DB::table((new PayslipLine)->getTable().' as l')
            ->join('hr_payslips as s', 's.id', '=', 'l.payslip_id')
            ->where('l.company_id', CompanyContext::id())
            ->whereIn('s.payroll_run_id', $runs->pluck('id')->all())
            ->where('l.kind', SalaryHead::DEDUCTION)
            ->groupBy('l.head_code', 'l.head_name_en', 'l.head_name_bn')
            ->orderBy('l.head_code')
            ->selectRaw("{$name} as name, COALESCE(SUM(l.amount), 0) as amount")
            ->get();

        return [
            'staff' => (int) $runs->sum('employee_count'),
            'gross' => array_reduce($runs->all(), fn (string $s, $r) => bcadd($s, (string) $r->gross_total, 4), '0'),
            'deductions' => $lines->map(fn ($l) => ['name' => (string) $l->name, 'amount' => bcadd((string) $l->amount, '0', 4)])->all(),
            'net' => array_reduce($runs->all(), fn (string $s, $r) => bcadd($s, (string) $r->net_total, 4), '0'),
            'pay_by' => DateFormat::format($p->nextMonthDay(7)->toDateString()),
        ];
    }

    // ─────────────────────────────── ১০ চেকলিস্ট ───────────────────────────────

    /**
     * মাস বন্ধের চেকলিস্ট — মাস বন্ধের পাতার নয় ধাপ হুবহু ([[MonthEndChecklist]], সব শাখা জুড়ে), সাথে খাতা আর কাগজের দুইটা মিল।
     *
     * @return list<array<string, mixed>>
     */
    private function checklist(MonthPeriods $p, array $pl, array $receivables): array
    {
        $rows = [];

        foreach (app(MonthEndChecklist::class)->run($p->month) as $row) {
            $rows[] = [
                'key' => $row['key'],
                'label' => (string) (__('accounts::control.checks.'.$row['key'])[0] ?? $row['key']),
                'state' => $row['state'],
                'result' => $row['key'] === 'locked' ? (string) __('executive::month_close.check.'.($row['state'] === MonthEndChecklist::OK ? 'locked_yes' : 'locked_no')) : match ($row['state']) {
                    MonthEndChecklist::OK => (string) __('accounts::control.state_ok'),
                    MonthEndChecklist::NOT_APPLICABLE => (string) __('accounts::control.state_na'),
                    default => (string) __('accounts::control.state_pending', ['count' => $row['count']]),
                },
                'route' => $row['route'],
                'params' => $row['params'],
            ];
        }

        // ⭐ খাতার বিক্রি = বিলের নিট বিক্রি — খাতায় না বসা বা দুইবার বসা বিল এখানে ধরা পড়ে (নমুনার "দিন-শেষগুলোর যোগ = মাসের অঙ্ক")
        $books = $pl['figures']['month']['sales_books'];
        $papers = SalesMetrics::netParts($p->from->toDateString(), $p->to->toDateString())['net'];
        $same = bccomp(Money::round($books, 2), Money::round($papers, 2), 2) === 0;
        $rows[] = [
            'key' => 'sales_books', 'label' => (string) __('executive::month_close.check.sales_books'),
            'state' => $same ? MonthEndChecklist::OK : MonthEndChecklist::PENDING,
            'result' => Money::format($books).($same ? ' = ' : ' ≠ ').Money::format($papers),
            'route' => 'accounts.integrity', 'params' => [],
        ];

        // ⓘ সম্ভাব্য ক্ষতি — হিসাবটা দেখায়, খাতায় বসানো ভাতার সাথে মেলানো ec-র ভাতার খাত আসার পরে
        $rows[] = [
            'key' => 'ecl', 'label' => (string) __('executive::month_close.check.ecl'),
            'state' => MonthEndChecklist::NOT_APPLICABLE,
            'result' => Money::format($receivables['ageing']['ecl']),
            'route' => 'accounts.period.index', 'params' => [],
        ];

        // ⓘ তালা শেষ ধাপ — সবার নিচে
        usort($rows, fn ($a, $b) => ($a['key'] === 'locked') <=> ($b['key'] === 'locked'));

        return $rows;
    }

    // ─────────────────────────────── ১১ তালা ───────────────────────────────

    /**
     * ⓘ বাকি রেখে বন্ধ হলে কারণ আর কী বাকি ছিল — তালার সাথেই লেখা ([[PeriodLockController::close()]]; ২১ নভেম্বর ২০২৬), কাগজেও।
     *
     * @return array{locked: bool, by: ?string, at: ?string, pending: int, reason: ?string, left: ?string}
     */
    private function lock(MonthPeriods $p): array
    {
        $lock = PeriodLock::query()->where('year', $p->month->year)->where('month', $p->month->month)->first();

        return [
            'locked' => $lock !== null,
            'by' => $lock?->locked_by !== null ? (string) User::query()->whereKey($lock->locked_by)->value('name') : null,
            'at' => $lock?->locked_at !== null ? DateFormat::formatWithTime($lock->locked_at) : null,
            'pending' => 0,
            'reason' => $lock?->reason ?: null,
            'left' => empty($lock?->open_items) ? null : MonthEndChecklist::itemList($lock->open_items),
        ];
    }

    // ─────────────────────────────── মাসের খবর ───────────────────────────────

    /**
     * মাসের খবর — ছয়টা ছোট বাক্য, প্রতিটা নিচের কোনো অংশের অঙ্ক থেকে (নতুন কিছু গোনা হয় না)। ⓘ অঙ্ক আর নাম আগে `e()`, তারপর বোল্ড —
     * বাক্যগুলো ভাষার ফাইলের, তাই HTML নিরাপদ।
     *
     * @return array{left: list<string>, right: list<string>}
     */
    private function news(array $pack, MonthPeriods $p): array
    {
        $b = fn (string $v) => '<b>'.e($v).'</b>';
        $t = fn (string $k, array $r = []) => (string) __('executive::month_close.news.'.$k, $r);
        $sales = $pack['sales'];
        $month = $pack['pl']['figures']['month'];
        $prior = $pack['pl']['figures']['prior'];
        $left = [];

        $line = $t('sales', ['amount' => $b(Money::format($sales['parts']['gross']))]);

        if ($sales['sellers']['achieved'] !== null) {
            $line .= $t('sales_target', ['pct' => $b($sales['sellers']['achieved']), 'gap' => e(Money::format(bcsub($sales['sellers']['target'], $sales['sellers']['net'], 4)))]);
        }

        $change = $this->change($sales['parts']['gross'], $sales['prior']['gross']);
        $left[] = $line.($change !== null ? $t(str_starts_with($change, '-') ? 'sales_down' : 'sales_up', ['pct' => e(ltrim($change, '-'))]) : '');

        $left[] = $t(bccomp($month['net_profit'], '0', 4) >= 0 ? 'profit' : 'loss', [
            'amount' => $b(Money::format(ltrim($month['net_profit'], '-'))),
            'pct' => e((string) $pack['pl']['margin']['month']),
            'prior' => e(Money::format($prior['net_profit'])),
        ]);

        $gm = $this->percent($month['gross_profit'], $month['net_sales']);
        $budget = $pack['pl']['figures']['budget'];
        $left[] = $budget !== null
            ? $t('gross_margin_budget', ['pct' => e((string) $gm), 'budget' => e((string) $this->percent($budget['gross_profit'], $budget['net_sales']))])
            : $t('gross_margin', ['pct' => e((string) $gm), 'prior' => e((string) $this->percent($prior['gross_profit'], $prior['net_sales']))]);

        $r = $pack['receivables'];
        $over90 = collect($r['ageing']['buckets'])->firstWhere('key', 'bucket_90')['due'] ?? '0';
        $right = [];
        $line = $t(bccomp($r['closing'], $r['opening'], 4) >= 0 ? 'dues_up' : 'dues_down', ['amount' => $b(Money::format($r['closing'])), 'over90' => e(Money::format($over90))]);
        $oldest = $r['oldest'][0] ?? null;

        if ($oldest !== null && $oldest['days'] !== null) {
            $line .= $t('dues_oldest', ['name' => e($oldest['name'].($oldest['point'] ? ' · '.$oldest['point'] : '')), 'days' => $oldest['days']]);
        }

        $right[] = $line;

        $checks = collect($pack['checks'])->reject(fn ($c) => $c['state'] === MonthEndChecklist::NOT_APPLICABLE);
        $banks = collect($pack['money']['rows'])->whereIn('kind', [Account::BANK, Account::MFS]);
        $right[] = $t('checks', [
            'done' => $b((string) $checks->where('state', MonthEndChecklist::OK)->count()), 'all' => $checks->count(),
            'banks' => $banks->where('state', 'ok')->count(), 'bank_all' => $banks->count(),
        ]);

        $right[] = $t(bccomp($pack['vat']['due'], '0', 4) >= 0 ? 'vat' : 'vat_credit', [
            'date' => $b($pack['vat']['due_on']), 'amount' => e(Money::format(ltrim($pack['vat']['due'], '-'))),
        ]);

        return ['left' => $left, 'right' => $right];
    }

    // ─────────────────────────────── ছোট সাহায্য ───────────────────────────────

    private function neg(string $v): string
    {
        return bcmul($v, '-1', 4);
    }

    /** ভাগ, এক দশমিকে, "%" সহ — ভাজক শূন্য হলে null */
    public function percent(?string $part, ?string $whole): ?string
    {
        if ($part === null || $whole === null || bccomp($whole, '0', 4) === 0) {
            return null;
        }

        return Money::round(bcdiv(bcmul($part, '100', 8), $whole, 8), 1).'%';
    }

    /** আগের থেকে বদল, শতাংশে — আগে শূন্য বা না থাকলে null */
    private function change(string $now, ?string $before): ?string
    {
        return $before === null ? null : $this->percent(bcsub($now, $before, 4), $before);
    }
}
