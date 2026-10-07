<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Dashboard\HomePeriod;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Services\LedgerBalances;
use App\Core\Services\DataScope;
use App\Models\Branch;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ViewedBranch;
use App\Models\Approval;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Dashboard\AccountsDashboard;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * হিসাবের ড্যাশবোর্ড — মালিকের পুরো নকশা (৫ অক্টোবর ২০২৬): এ মাসের নিট লাভ, চলতি সম্পদ-দায়-নিট সম্পদ, আজ পোস্ট হওয়া
 * ভাউচার, এ মাসে উল্টানো দাখিলা, পিছনের তারিখের ভাউচার, শূন্যের নিচের ড্রয়ার, সইয়ের অপেক্ষায় ভাউচার।
 *
 * ⓘ দাবি, একই মালিক দুইবার (সুইচ বন্ধ, তারপর চালু): বন্ধে নতুন একটা ঘরও নেই; চালুতে প্রতিটা সংখ্যা খাতার কাঁচা যোগফলের
 * সাথে হুবহু মেলে, আর আসল কাগজ বসালে ঠিক ততটা নড়ে — গতকালের তারিখে লেখা ভাউচারে "পিছনের তারিখ" +১, আজকেরটায় নয়।
 */
final class TheAccountsDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private const STATS = ['net_profit_month', 'posted_today', 'reversed_this_month', 'backdated_this_month',
        'tills_below_zero', 'awaiting_signature'];

    public function test_every_new_figure_matches_the_books_and_moves_with_real_papers(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        // ── সুইচ বন্ধ: নতুন একটা ঘরও নেই ──
        config(['abos.dashboards_v2' => false]);
        $off = AccountsDashboard::dashboard();
        foreach (self::STATS as $key) {
            $this->assertNull($this->stat($off, $key), "⛔ সুইচ বন্ধেও '{$key}'।");
        }
        $this->assertNull($this->panel($off, 'current_position'), '⛔ সুইচ বন্ধেও চলতি অবস্থান।');

        config(['abos.dashboards_v2' => true]);
        $before = $this->read();

        // ── খাতার সাথে হুবহু: নিট লাভ, চলতি সম্পদ, চলতি দায়, নিট সম্পদ ──
        $this->assertSame(0, bccomp($before['profit'], $this->rawProfit(), 2), '⛔ নিট লাভ খাতার আয় − ব্যয়ের সাথে মেলে না।');
        $this->assertSame(0, bccomp($before['current_assets'], $this->rawBalance(['1100']), 2), '⛔ চলতি সম্পদ খাতার ১১০০ দলের সাথে মেলে না।');
        $this->assertSame(0, bccomp($before['current_liabilities'], $this->rawBalance(['2100']), 2), '⛔ চলতি দায় খাতার ২১০০ দলের সাথে মেলে না।');
        $this->assertSame(0, bccomp($before['net_assets'], bcsub($this->rawBalance(['1000']), $this->rawBalance(['2000']), 4), 2), '⛔ নিট সম্পদ মোট সম্পদ − মোট দায় নয়।');

        // ── আসল কাগজ: আয় ১,০০০ বাকিতে, খরচ ৩০০ নগদে ──
        $receivable = $this->leaf(StandardChart::RECEIVABLE);
        $income = (int) Account::query()->where('type', Account::INCOME)->where('is_group', false)->value('id');
        $expense = $this->leaf(StandardChart::OPERATING_EXPENSES);
        $payable = $this->leaf(StandardChart::PAYABLE);
        $this->assertGreaterThan(0, $income, 'প্রস্তুতিটাই ভুল — আয়ের পাতা-খাত নেই।');

        $this->paper('spec_probe', 1, [[$receivable, '1000', '0'], [$income, '0', '1000']]);
        $this->paper('spec_probe', 2, [[$expense, '300', '0'], [$payable, '0', '300']]);

        // ── উল্টানো: এক কাগজের দুই সারি উল্টালে গোনা একবার ──
        $this->paper('spec_probe:reversal', 2, [[$payable, '300', '0'], [$expense, '0', '300']]);

        // ── ভাউচার: গতকালের তারিখে একটা, আজকের তারিখে একটা — দুটোই আজ পোস্ট ──
        $this->voucher('JV-SPEC-1', Carbon::yesterday()->toDateString(), DocumentStatus::CONFIRMED);
        $this->voucher('JV-SPEC-2', Carbon::today()->toDateString(), DocumentStatus::CONFIRMED);

        // ── সইয়ের অপেক্ষায় একটা খসড়া ──
        $draft = $this->voucher('JV-SPEC-3', Carbon::today()->toDateString(), DocumentStatus::DRAFT);
        Approval::query()->forceCreate([
            'company_id' => $this->company->id, 'approvable_type' => Voucher::class, 'approvable_id' => $draft->id,
            'module' => 'accounts', 'action' => Voucher::JOURNAL, 'amount' => '10', 'status' => Approval::PENDING,
            'requested_by' => $owner->id, 'requested_at' => now(),
        ]);

        // ── একটা ড্রয়ার শূন্যের নিচে ──
        $facts = app(AccountsFacts::class);
        $tills = $facts->tills();
        $this->assertNotEmpty($tills, 'প্রস্তুতিটাই ভুল — ডেমোতে কোনো ক্যাশ ড্রয়ার নেই।');
        $balances = $facts->tillBalances($tills);
        $till = $tills->first(fn ($t) => bccomp($balances[$t->id], '0', 4) >= 0);
        $this->assertNotNull($till, 'প্রস্তুতিটাই ভুল — শূন্য বা বেশি জেরের কোনো ড্রয়ার নেই।');
        $this->paper('spec_probe', 3, [[$expense, bcadd($balances[$till->id], '100', 4), '0'], [(int) $till->account_id, '0', bcadd($balances[$till->id], '100', 4)]]);

        $after = $this->read();

        // নিট লাভ: আয় ১,০০০ − খরচ (৩০০ − ৩০০ উল্টানো + ড্রয়ারের খরচ)
        $tillSpend = bcadd($balances[$till->id], '100', 4);
        $this->assertSame(0, bccomp(bcsub($after['profit'], $before['profit'], 2), bcsub('1000', $tillSpend, 4), 2), '⛔ নিট লাভ আয় − ব্যয়ের মতো নড়েনি।');
        $this->assertSame(0, bccomp($after['profit'], $this->rawProfit(), 2), '⛔ কাগজের পরে নিট লাভ খাতার সাথে মেলে না।');

        // চলতি সম্পদ: প্রাপ্য +১,০০০, ড্রয়ার −(জের + ১০০); দায় অপরিবর্তিত (৩০০ বসে আবার উল্টেছে)
        $this->assertSame(0, bccomp(bcsub($after['current_assets'], $before['current_assets'], 2), bcsub('1000', $tillSpend, 4), 2), '⛔ চলতি সম্পদ ঠিক ততটা নড়েনি।');
        $this->assertSame(0, bccomp($after['current_liabilities'], $before['current_liabilities'], 2), '⛔ উল্টানো দায় চলতি দায়ে রয়ে গেছে।');
        $this->assertSame(0, bccomp($after['current_assets'], $this->rawBalance(['1100']), 2), '⛔ কাগজের পরে চলতি সম্পদ খাতার সাথে মেলে না।');
        $this->assertSame(0, bccomp($after['net_assets'], bcsub($this->rawBalance(['1000']), $this->rawBalance(['2000']), 4), 2), '⛔ কাগজের পরে নিট সম্পদ খাতার সাথে মেলে না।');

        $this->assertSame($before['posted_today'] + 2, $after['posted_today'], '⛔ আজ পোস্ট হওয়া দুইটা ভাউচার গোনা হয়নি (খসড়া গোনা হলে +৩)।');
        $this->assertSame($before['reversed_this_month'] + 1, $after['reversed_this_month'], '⛔ উল্টানো কাগজ একবার গোনা হয়নি।');
        $this->assertSame($before['backdated_this_month'] + 1, $after['backdated_this_month'], '⛔ গতকালের তারিখের ভাউচার পিছনের তারিখে +১ নয় (আজকেরটাও গোনা হলে +২)।');
        $this->assertSame($before['tills_below_zero'] + 1, $after['tills_below_zero'], '⛔ শূন্যের নিচে নামা ড্রয়ার গোনা হয়নি।');
        $this->assertSame($before['awaiting_signature'] + 1, $after['awaiting_signature'], '⛔ সইয়ের অপেক্ষার ভাউচার গোনা হয়নি।');

        $def = AccountsDashboard::dashboard();
        $this->assertSame('warn', $this->stat($def, 'backdated_this_month')->tone, '⛔ পিছনের তারিখ শূন্যের বেশি, অথচ সাবধানের রং নয়।');
    }

    public function test_the_first_chart_shows_income_expense_and_profit_for_the_homes_chosen_period(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ── সুইচ বন্ধ: পুরনো দুই ভাগ, হুবহু ──
        config(['abos.dashboards_v2' => false]);
        $old = AccountsDashboard::dashboard()->panels[0];
        $this->assertSame(__('accounts::dashboard.month_so_far'), $old->label);
        $this->assertCount(2, $old->parts, '⛔ সুইচ বন্ধেও প্রথম চার্ট বদলেছে।');

        config(['abos.dashboards_v2' => true]);
        $income = (int) Account::query()->where('type', Account::INCOME)->where('is_group', false)->value('id');
        $this->paper('first_chart_probe', 1, [[$this->leaf(StandardChart::RECEIVABLE), '1000', '0'], [$income, '0', '1000']]);
        $this->paper('first_chart_probe', 2, [[$this->leaf(StandardChart::OPERATING_EXPENSES), '1700', '0'], [$this->leaf(StandardChart::PAYABLE), '0', '1700']]);

        // ── হোমে "আজ" বাছা: আজকের খাতার যোগফল, আজকের তারিখ ──
        $today = HomePeriod::during('today', fn () => AccountsDashboard::dashboard())->panels[0];
        $day = Carbon::today()->toDateString();
        $this->assertSame(__('accounts::dashboard.today_so_far'), $today->label, '⛔ "আজ" বাছা, অথচ চার্টের নাম আজকের নয়।');
        $this->assertSame(DateRange::label($day, $day), $today->range, '⛔ চার্ট বলে না কোন দিনের।');
        $this->assertSame('columns', $today->chart);
        $rawIncome = $this->rawNet(Account::INCOME, $day, $day);
        $rawExpense = $this->rawNet(Account::EXPENSE, $day, $day);
        $this->assertSame(0, bccomp($this->num($today->parts[0]['value']), $rawIncome, 2), '⛔ "আজ" বাছা, অথচ আয় আজকের খাতার যোগফল নয় (মাস দেখাচ্ছে?)।');
        $this->assertSame(0, bccomp($this->num($today->parts[1]['value']), $rawExpense, 2), '⛔ ব্যয় আজকের খাতার যোগফল নয়।');
        $this->assertSame(0, bccomp($this->num($today->parts[2]['value']), bcsub($rawIncome, $rawExpense, 4), 2), '⛔ নিট লাভ আয় − ব্যয় নয়।');
        $this->assertLessThan(0, (float) $this->num($today->parts[2]['value']), 'প্রস্তুতিটাই ভুল — লোকসানের দিন বানানো যায়নি।');

        // ── মডিউলের নিজের পাতা (কোনো বাছা নেই): এ মাস ──
        $month = AccountsDashboard::dashboard()->panels[0];
        $start = Carbon::today()->startOfMonth()->toDateString();
        $this->assertSame(__('accounts::dashboard.month_so_far'), $month->label);
        $this->assertSame(DateRange::label($start, $day), $month->range, '⛔ মাসের চার্ট বলে না কবে থেকে কবে।');
        $this->assertSame(0, bccomp($this->num($month->parts[0]['value']), $this->rawNet(Account::INCOME, $start, $day), 2), '⛔ মাসের আয় খাতার সাথে মেলে না।');
        $this->assertSame(0, bccomp($this->num($month->parts[2]['value']), $this->rawProfit(), 2), '⛔ মাসের নিট লাভ খাতার আয় − ব্যয় নয়।');
    }

    /** এক ধরনের খাতের কাঁচা নিট, স্বাভাবিক দিকে ধনাত্মক — বছর বন্ধ বাদ */
    private function rawNet(string $type, string $from, string $to): string
    {
        $net = (string) LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('accounts.type', $type)
            ->whereBetween('ledger_entries.trx_date', [$from, $to])
            ->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) - COALESCE(SUM(ledger_entries.credit), 0) as net')
            ->value('net');

        return $type === Account::INCOME ? bcmul($net, '-1', 4) : bcadd($net, '0', 4);
    }

    public function test_the_first_chart_follows_the_branch_picked_in_the_header(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        config(['abos.dashboards_v2' => true]);

        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();

        // ── হেডারে শাখা A ──
        $this->post(route('branch.switch'), ['branch_id' => $a->id]);
        $this->pick();
        $beforeA = $this->num(AccountsDashboard::dashboard()->panels[0]->parts[0]['value']);

        // ── শাখা B-তে আসল পথে একটা নিশ্চিত বিল, ৭,৭৭৭ ──
        $this->post(route('branch.switch'), ['branch_id' => 'all']);
        $this->pick();
        $beforeAll = $this->num(AccountsDashboard::dashboard()->panels[0]->parts[0]['value']);
        $warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('branch_id', $b->id)->orderBy('id')->value('id');
        $this->assertNotNull($warehouse, 'প্রস্তুতিটাই ভুল — শাখা B-র গুদাম নেই।');
        CompanyContext::set($this->company->id, $b->id);
        $invoice = app(SalesInvoiceService::class)->create([
            'customer_id' => Customer::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->value('id'),
            'branch_id' => $b->id, 'warehouse_id' => $warehouse, 'trx_date' => Carbon::today()->toDateString(),
        ], [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => '7777']]);
        app(SalesInvoiceService::class)->confirm($invoice);
        $this->assertSame($b->id, (int) $invoice->fresh()->branch_id, 'বিলটা শাখা B-তে বসেনি — দাবিটা কিছু মাপছে না।');

        // ── শাখা A: চার্ট অপরিবর্তিত ──
        $this->post(route('branch.switch'), ['branch_id' => $a->id]);
        $this->pick();
        $this->assertSame(0, bccomp($this->num(AccountsDashboard::dashboard()->panels[0]->parts[0]['value']), $beforeA, 2),
            '⛔ শাখা A বাছা, অথচ শাখা B-র বিক্রিতে প্রথম চার্টের আয় বদলেছে।');

        // ── সব শাখা: ঠিক ৭,৭৭৭ বেশি ──
        $this->post(route('branch.switch'), ['branch_id' => 'all']);
        $this->pick();
        $this->assertSame(0, bccomp(bcsub($this->num(AccountsDashboard::dashboard()->panels[0]->parts[0]['value']), $beforeAll, 2), '7777', 2),
            '⛔ সব শাখায় প্রথম চার্টের আয় ৭,৭৭৭ বাড়েনি।');
    }

    /** হেডারে যা বাছা হলো, পরের পড়াটা যেন সেটাই দেখে */
    private function pick(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $owner->current_branch_id);
        app(DataScope::class)->forget();
        app(LedgerBalances::class)->forget();
        $this->actingAs($owner);
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        // ⓘ জের আগে-তোলা থাকে এক অনুরোধ ধরে ([[LedgerBalances]]); পরীক্ষায় একই অ্যাপে দুইবার পড়া, তাই ভুলিয়ে দেওয়া
        app(LedgerBalances::class)->forget();
        $def = AccountsDashboard::dashboard();

        $position = $this->panel($def, 'current_position');
        $this->assertNotNull($position, 'চালুতে চলতি অবস্থান নেই।');
        $out = [
            'current_assets' => $this->num($position->parts[0]['value']),
            'current_liabilities' => $this->num($position->parts[1]['value']),
            'net_assets' => $this->num($position->parts[2]['value']),
        ];

        foreach (self::STATS as $key) {
            $stat = $this->stat($def, $key);
            $this->assertNotNull($stat, "চালুতে '{$key}' নেই।");
            $out[$key] = $key === 'net_profit_month' ? $this->num($stat->value) : (int) $stat->value;
        }
        $out['profit'] = $out['net_profit_month'];

        return $out;
    }

    private function stat(DashboardDefinition $def, string $key): ?object
    {
        return collect($def->stats)->firstWhere('label', __('accounts::dashboard.'.$key));
    }

    private function panel(DashboardDefinition $def, string $key): ?object
    {
        return collect($def->panels)->firstWhere('label', __('accounts::dashboard.'.$key));
    }

    private function num(string $value): string
    {
        return str_replace(',', '', $value);
    }

    /** খাতার কাঁচা যোগফল: আয় (ক্রেডিট − ডেবিট) − ব্যয় (ডেবিট − ক্রেডিট), মাসের ১ তারিখ থেকে আজ, বছর বন্ধ বাদ */
    private function rawProfit(): string
    {
        $sum = fn (string $type) => LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('accounts.type', $type)
            ->whereBetween('ledger_entries.trx_date', [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()])
            ->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) - COALESCE(SUM(ledger_entries.credit), 0) as net')
            ->value('net');

        return bcsub(bcmul((string) $sum(Account::INCOME), '-1', 4), (string) $sum(Account::EXPENSE), 4);
    }

    /** একটা দলের পুরো বংশের কাঁচা জের, খাতের স্বভাব ধরে চিহ্ন — দেখার শাখায় */
    private function rawBalance(array $codes): string
    {
        $total = '0';
        $branch = ViewedBranch::one();

        foreach ($codes as $code) {
            foreach (StandardChart::find($code)->selfAndDescendants() as $account) {
                if ($account->is_group) {
                    continue;
                }
                $row = LedgerEntry::query()->where('account_id', $account->id)
                    ->when($branch, fn ($q) => $q->where('branch_id', $branch))
                    ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();
                $net = bcsub((string) $row->d, (string) $row->c, 4);
                $total = bcadd($total, $account->nature === Account::CREDIT ? bcmul($net, '-1', 4) : $net, 4);
            }
        }

        return $total;
    }

    private function leaf(string $code): int
    {
        $ids = StandardChart::find($code)?->selfAndDescendants()->pluck('id') ?? collect();
        $id = Account::query()->whereIn('id', $ids)->where('is_group', false)->value('id');
        $this->assertNotNull($id, "খাত {$code}-এর নিচে কোনো পাতা-খাত নেই।");

        return (int) $id;
    }

    private function voucher(string $no, string $date, string $status): Voucher
    {
        return Voucher::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('company_id', $this->company->id)->value('id'),
            'type' => Voucher::JOURNAL, 'document_no' => $no, 'trx_date' => $date,
            'amount' => '10', 'status' => $status,
            'approved_at' => $status === DocumentStatus::CONFIRMED ? now() : null,
        ]);
    }

    /** @param list<array{0: int, 1: string, 2: string}> $lines */
    private function paper(string $source, int $sourceId, array $lines): void
    {
        $year = FinancialYear::query()->where('company_id', $this->company->id)->value('id');

        foreach ($lines as [$account, $debit, $credit]) {
            LedgerEntry::query()->create([
                'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
                'financial_year_id' => $year, 'account_id' => $account, 'trx_date' => now()->toDateString(),
                'debit' => $debit, 'credit' => $credit, 'source_type' => $source, 'source_id' => $sourceId,
            ]);
        }
    }
}
