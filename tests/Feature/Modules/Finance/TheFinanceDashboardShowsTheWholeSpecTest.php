<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Dashboard\HomePeriod;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Services\LedgerBalances;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ViewedBranch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Dashboard\FinanceDashboard;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Purchase\Models\PurchaseBill;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * অর্থের ড্যাশবোর্ড — মালিকের পুরো নকশা (৫ অক্টোবর ২০২৬): মোট তহবিল, আজকের আদায় ও পরিশোধ, এ মাসের নিট নগদ প্রবাহ,
 * ব্যাংক অনুযায়ী জের, মিলকরণ বাকি, সামনের ৭ দিনের ও মেয়াদোত্তীর্ণ দেনা, বাজেটের পার্থক্য।
 *
 * ⓘ দাবি, একই মালিক দুইবার (সুইচ বন্ধ, তারপর চালু): বন্ধে নতুন একটা ঘরও নেই; চালুতে তহবিল টাকার খাতের কাঁচা জেরের যোগফল,
 * ব্যাংকের দণ্ড সেই খাতের নিজের জের, দেনা বিলের নিজের বাকির ([[PurchaseBill::dueAmount()]]) যোগফল; আর আসল কাগজ বসালে
 * প্রতিটা ঠিক ততটা নড়ে — আদায় এলে তহবিল ঠিক ততটা বাড়ে, বিলের বিপরীতে পরিশোধ ভাউচারে দেনা ঠিক ততটা কমে।
 */
final class TheFinanceDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private const STATS = ['total_fund', 'today_collection', 'today_payment', 'net_cash_flow_month',
        'reconciliation_pending', 'payables_next_week', 'payables_overdue'];

    public function test_every_new_figure_matches_the_books_and_moves_with_real_papers(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $bank = $this->bankLeaf();
        $cash = $this->leaf(StandardChart::CASH_IN_HAND);
        $receivable = $this->leaf(StandardChart::RECEIVABLE);
        $payable = $this->leaf(StandardChart::PAYABLE);
        $expense = $this->leaf(StandardChart::OPERATING_EXPENSES);

        // বাজেট: এ মাসের খরচের একটা খাতে ১০,০০০
        Budget::query()->forceCreate([
            'company_id' => $this->company->id, 'year' => (int) now()->year, 'month' => (int) now()->month,
            'account_id' => $expense, 'amount' => '10000',
        ]);

        // ── সুইচ বন্ধ: নতুন একটা ঘরও নেই ──
        config(['abos.dashboards_v2' => false]);
        $off = FinanceDashboard::dashboard();
        foreach (self::STATS as $key) {
            $this->assertNull($this->stat($off, 'finance::dashboard.'.$key), "⛔ সুইচ বন্ধেও '{$key}'।");
        }
        $this->assertNull($this->stat($off, 'finance::budget.month_variance'), '⛔ সুইচ বন্ধেও বাজেটের পার্থক্য।');
        $this->assertNull(collect($off->panels)->firstWhere('label', __('finance::dashboard.bank_wise')), '⛔ সুইচ বন্ধেও ব্যাংক অনুযায়ী জের।');

        config(['abos.dashboards_v2' => true]);
        $before = $this->read($bank);

        // ── খাতার সাথে হুবহু ──
        $this->assertSame(0, bccomp($before['total_fund'], $this->rawFund(), 2), '⛔ মোট তহবিল টাকার খাতের জেরের যোগফল নয়।');
        $this->assertSame(0, bccomp($before['bank'], Account::query()->findOrFail($bank)->balanceOn(null, ViewedBranch::one()), 2), '⛔ ব্যাংকের দণ্ড খাতের নিজের জের নয়।');
        $this->assertSame(0, bccomp($before['payables_next_week'], $this->rawDue(false), 2), '⛔ সামনের ৭ দিনের দেনা বিলের বাকির যোগফল নয়।');
        $this->assertSame(0, bccomp($before['payables_overdue'], $this->rawDue(true), 2), '⛔ মেয়াদোত্তীর্ণ দেনা বিলের বাকির যোগফল নয়।');
        $status = app(BudgetService::class)->monthStatus();
        $this->assertNotNull($status, 'প্রস্তুতিটাই ভুল — বাজেট বসেনি।');
        $this->assertSame(0, bccomp($before['variance'], bcsub($status['budget'], $status['actual'], 4), 2), '⛔ বাজেটের পার্থক্য বাজেট − প্রকৃত নয়।');

        // ── আসল কাগজ ──
        $this->paper(1, [[$cash, '1000', '0'], [$receivable, '0', '1000']]);   // গ্রাহকের টাকা নগদে এল
        $this->paper(2, [[$payable, '400', '0'], [$cash, '0', '400']]);        // সরবরাহকারীকে নগদে দেওয়া
        $this->paper(3, [[$bank, '2500', '0'], [$receivable, '0', '2500']]);   // গ্রাহকের টাকা ব্যাংকে এল
        $this->paper(4, [[$expense, '300', '0'], [$cash, '0', '300']]);        // বাজেটের খাতে খরচ

        // মিলকরণ: ব্যাংক জানে, আমরা জানি না
        BankStatementLine::query()->forceCreate([
            'company_id' => $this->company->id, 'bank_account_id' => $bank, 'trx_date' => now()->toDateString(),
            'description' => 'probe', 'debit' => '0', 'credit' => '75', 'fingerprint' => str_repeat('a', 64),
        ]);

        // দেনা: তিন দিন পরে মেয়াদ ৫,০০০ আর দশ দিন আগে পেরোনো ২,০০০; প্রথমটায় ১,২০০ পরিশোধ ভাউচার
        $soon = $this->bill('PB-SPEC-1', Carbon::today()->addDays(3)->toDateString(), '5000');
        $this->bill('PB-SPEC-2', Carbon::today()->subDays(10)->toDateString(), '2000');
        $this->bill('PB-SPEC-3', Carbon::today()->addDays(30)->toDateString(), '9000');   // সপ্তাহের বাইরে — কোনো ঘরে নয়
        Voucher::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('company_id', $this->company->id)->value('id'),
            'type' => Voucher::PAYMENT, 'document_no' => 'PV-SPEC-1', 'trx_date' => now()->toDateString(),
            'amount' => '1200', 'status' => DocumentStatus::CONFIRMED,
            'against_type' => PurchaseBill::drillSourceType(), 'against_id' => $soon->id,
        ]);

        $after = $this->read($bank);

        $this->assertSame(0, bccomp(bcsub($after['total_fund'], $before['total_fund'], 2), '2800', 2), '⛔ তহবিল ১,০০০ − ৪০০ + ২,৫০০ − ৩০০ = ২,৮০০ বাড়েনি।');
        $this->assertSame(0, bccomp($after['total_fund'], $this->rawFund(), 2), '⛔ কাগজের পরে তহবিল টাকার খাতের জেরের সাথে মেলে না।');
        $this->assertSame(0, bccomp(bcsub($after['today_collection'], $before['today_collection'], 2), '3500', 2), '⛔ আজকের আদায় ১,০০০ + ২,৫০০ বাড়েনি।');
        $this->assertSame(0, bccomp(bcsub($after['today_payment'], $before['today_payment'], 2), '400', 2), '⛔ আজকের পরিশোধ ৪০০ বাড়েনি।');
        $this->assertSame(0, bccomp(bcsub($after['net_cash_flow_month'], $before['net_cash_flow_month'], 2), '2800', 2), '⛔ নিট নগদ প্রবাহ ২,৮০০ নড়েনি।');
        $this->assertSame(0, bccomp(bcsub($after['bank'], $before['bank'], 2), '2500', 2), '⛔ ব্যাংকের দণ্ড ২,৫০০ বাড়েনি।');
        $this->assertSame(0, bccomp($after['bank'], Account::query()->findOrFail($bank)->balanceOn(null, ViewedBranch::one()), 2), '⛔ কাগজের পরে ব্যাংকের দণ্ড খাতের জের নয়।');
        $this->assertSame($before['reconciliation_pending'] + 1, $after['reconciliation_pending'], '⛔ না-মেলানো বিবরণীর সারি গোনা হয়নি।');
        $this->assertSame(0, bccomp(bcsub($after['payables_next_week'], $before['payables_next_week'], 2), '3800', 2), '⛔ সামনের ৭ দিনের দেনা ৫,০০০ − ১,২০০ বাড়েনি (বা ৩০ দিনেরটাও ঢুকেছে)।');
        $this->assertSame(0, bccomp(bcsub($after['payables_overdue'], $before['payables_overdue'], 2), '2000', 2), '⛔ মেয়াদোত্তীর্ণ দেনা ২,০০০ বাড়েনি।');
        $this->assertSame(0, bccomp($after['payables_next_week'], $this->rawDue(false), 2), '⛔ কাগজের পরে সপ্তাহের দেনা বিলের বাকির সাথে মেলে না।');
        $this->assertSame(0, bccomp($after['payables_overdue'], $this->rawDue(true), 2), '⛔ কাগজের পরে মেয়াদোত্তীর্ণ দেনা বিলের বাকির সাথে মেলে না।');
        $this->assertSame(0, bccomp(bcsub($before['variance'], $after['variance'], 2), '300', 2), '⛔ বাজেটের খাতে ৩০০ খরচে পার্থক্য ৩০০ কমেনি।');
    }

    public function test_where_the_money_went_follows_the_homes_chosen_period(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        config(['abos.dashboards_v2' => true]);

        // আজ একটা খরচ — যাতে আজকের ভাগটা খালি না থাকে
        $this->paper(9, [[$this->leaf(StandardChart::OPERATING_EXPENSES), '450', '0'], [$this->leaf(StandardChart::CASH_IN_HAND), '0', '450']]);
        $day = Carbon::today()->toDateString();

        $today = HomePeriod::during('today', fn () => FinanceDashboard::dashboard())->panels[0];
        $this->assertSame(__('finance::dashboard.where_money_went_today'), $today->label, '⛔ "আজ" বাছা, অথচ প্রথম চার্ট আজকের খরচ নয়।');
        $this->assertSame(DateRange::label($day, $day), $today->range, '⛔ চার্ট বলে না কোন দিনের।');
        $sum = array_reduce($today->parts, fn (string $s, array $p) => bcadd($s, $this->num($p['value']), 4), '0');
        $raw = (string) LedgerEntry::query()
            ->whereIn('account_id', StandardChart::find(StandardChart::OPERATING_EXPENSES)->selfAndDescendants()->pluck('id'))
            ->where('trx_date', $day)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')->value('net');
        $this->assertLessThanOrEqual(6, count($today->parts));
        if (count($today->parts) < 6) {
            $this->assertSame(0, bccomp($sum, $raw, 2), '⛔ আজকের খরচের ভাগের যোগফল আজকের খাতার খরচ নয়।');
        }

        $month = FinanceDashboard::dashboard()->panels[0];
        $this->assertSame(__('finance::dashboard.where_money_went'), $month->label, '⛔ নিজের পাতায় প্রথম চার্ট এ মাসের খরচ নয়।');
        $this->assertSame(DateRange::label(Carbon::today()->startOfMonth()->toDateString(), $day), $month->range);
    }

    /** @return array<string, mixed> */
    private function read(int $bank): array
    {
        // ⓘ জের আগে-তোলা থাকে এক অনুরোধ ধরে ([[LedgerBalances]]); পরীক্ষায় একই অ্যাপে দুইবার পড়া, তাই ভুলিয়ে দেওয়া
        app(LedgerBalances::class)->forget();
        $def = FinanceDashboard::dashboard();
        $out = [];

        foreach (self::STATS as $key) {
            $stat = $this->stat($def, 'finance::dashboard.'.$key);
            $this->assertNotNull($stat, "চালুতে '{$key}' নেই।");
            $out[$key] = $key === 'reconciliation_pending' ? (int) $stat->value : $this->num($stat->value);
        }

        $variance = $this->stat($def, 'finance::budget.month_variance');
        $this->assertNotNull($variance, 'চালুতে বাজেটের পার্থক্য নেই।');
        $out['variance'] = $this->num($variance->value);

        $panel = collect($def->panels)->firstWhere('label', __('finance::dashboard.bank_wise'));
        $this->assertNotNull($panel, 'চালুতে ব্যাংক অনুযায়ী জের নেই।');
        $this->assertSame('hbars', $panel->chart);
        $part = collect($panel->parts)->firstWhere('label', Account::query()->findOrFail($bank)->name());
        $this->assertNotNull($part, 'পরীক্ষার ব্যাংক খাত দণ্ডে নেই।');
        $out['bank'] = $this->num($part['value']);

        return $out;
    }

    private function stat(DashboardDefinition $def, string $key): ?object
    {
        return collect($def->stats)->firstWhere('label', __($key));
    }

    private function num(string $value): string
    {
        return str_replace(',', '', $value);
    }

    /** নগদ + ব্যাংক + MFS — তিন দলের পুরো বংশের কাঁচা ডেবিট − ক্রেডিট, দেখার শাখায় */
    private function rawFund(): string
    {
        $ids = collect([StandardChart::CASH_IN_HAND, StandardChart::BANK, StandardChart::MOBILE_MONEY])
            ->flatMap(fn (string $code) => StandardChart::find($code)->selfAndDescendants()->pluck('id'))->all();
        $branch = ViewedBranch::one();

        $row = LedgerEntry::query()->whereIn('account_id', $ids)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();

        return bcsub((string) $row->d, (string) $row->c, 4);
    }

    /** বিলের নিজের বাকি ([[PurchaseBill::dueAmount()]]) — পোস্ট হওয়া, মেয়াদ লেখা বিল; মেয়াদোত্তীর্ণ বা সামনের ৭ দিনে */
    private function rawDue(bool $overdue): string
    {
        $today = Carbon::today()->toDateString();

        return PurchaseBill::query()->posted()->withPaid()
            ->whereNotNull('due_on')
            ->when($overdue,
                fn ($q) => $q->where('due_on', '<', $today),
                fn ($q) => $q->whereBetween('due_on', [$today, Carbon::today()->addDays(7)->toDateString()]))
            ->get()
            ->reduce(fn (string $sum, PurchaseBill $bill) => bcadd($sum, $bill->dueAmount(), 4), '0');
    }

    private function bill(string $no, string $dueOn, string $total): PurchaseBill
    {
        return PurchaseBill::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('company_id', $this->company->id)->value('id'),
            'document_no' => $no,
            'supplier_id' => (int) DB::table('suppliers')->where('company_id', $this->company->id)->value('id'),
            'trx_date' => now()->toDateString(), 'due_on' => $dueOn,
            'subtotal' => $total, 'total' => $total, 'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    /** ব্যাংক-চিহ্নিত একটা পাতা-খাত — নতুন, যাতে দণ্ডটা খুঁজে পাওয়া যায় আর জের শূন্য থেকে শুরু */
    private function bankLeaf(): int
    {
        $group = StandardChart::find(StandardChart::BANK);
        $this->assertNotNull($group, 'ব্যাংকের দল (১১০২) নেই — দাবিটা ফাঁকা।');

        return (int) Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-SPEC',
            'name_en' => 'Spec Probe Bank',
            'name_bn' => 'পরীক্ষার ব্যাংক',
            'parent_id' => $group->id,
            'type' => $group->type,
            'nature' => $group->nature,
            'money_kind' => Account::BANK,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ])->id;
    }

    private function leaf(string $code): int
    {
        $ids = StandardChart::find($code)?->selfAndDescendants()->pluck('id') ?? collect();
        $id = Account::query()->whereIn('id', $ids)->where('is_group', false)->value('id');
        $this->assertNotNull($id, "খাত {$code}-এর নিচে কোনো পাতা-খাত নেই।");

        return (int) $id;
    }

    /** @param list<array{0: int, 1: string, 2: string}> $lines */
    private function paper(int $sourceId, array $lines): void
    {
        $year = FinancialYear::query()->where('company_id', $this->company->id)->value('id');

        foreach ($lines as [$account, $debit, $credit]) {
            LedgerEntry::query()->create([
                'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
                'financial_year_id' => $year, 'account_id' => $account, 'trx_date' => now()->toDateString(),
                'debit' => $debit, 'credit' => $credit, 'source_type' => 'finance_spec_probe', 'source_id' => $sourceId,
            ]);
        }
    }
}
