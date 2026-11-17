<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\MonthClose\MonthPeriods;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ মাস শেষের হিসাব — মালিক, ১৭ নভেম্বর ২০২৬ (নমুনা "Ok", তারপর "Sob ok"; [[MonthClosePack]], [[MonthCloseController]])।
 *
 * দাবি:
 *  - নিট মুনাফা = খাতার আয়-খরচের `credit − debit` = লাভ-ক্ষতির পাতার (রিপোর্ট-ইঞ্জিনের) নিট — পয়সায়; নিট বিক্রি = বিক্রির খাতগুলোর নিট।
 *  - একটা বিল প্রতিটা লাইনকে ঠিক নিজের অঙ্কে নড়ায় — মোট বিল, মূসক, নিট বিক্রি, বিক্রীত মালের দাম, পাওনার বাকিতে বিক্রি, মজুদ।
 *  - খসড়া বিল কোনো যোগে নেই।
 *  - তুলনার সময় ঠিক — আগের মাস, গত বছর, অর্থবছরের শুরু; ৩১ তারিখে "আগের মাস" এই মাস নয়।
 *  - হেডারে এক শাখা বাছলে কেবল সেই শাখার বিক্রি আর খাতা।
 *  - দুই ভাষাতেই কাঁচা চাবি নেই; PDF সত্যিই PDF; চাবি ছাড়া ৪০৩, চাবি থাকলে ২০০।
 */
final class TheMonthClosePackTellsTheMonthTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $mms;

    private Branch $ntk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 11:00:00'));
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->mms = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($this->company->id, $this->mms->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_net_profit_is_the_books_and_the_profit_and_loss_page(): void
    {
        $this->sell($this->mms, '700');
        $this->sell($this->ntk, '300');
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));

        $m = $this->pack('2026-10');
        $books = $this->booksNet('2026-10-01', '2026-10-31');
        $this->assertNotSame(0, bccomp($books, '0', 2), 'দৃশ্যটাই ভুল — অক্টোবরের খাতায় কোনো আয়-খরচ নেই।');

        $this->assertSame(0, bccomp($m['pl']['figures']['month']['net_profit'], $books, 2),
            "⛔ মাস শেষের নিট মুনাফা {$m['pl']['figures']['month']['net_profit']}, খাতা বলে {$books}।");

        $engine = app(ReportEngine::class)->run('accounts.profit_loss', ['from' => '2026-10-01', 'to' => '2026-10-31'], 1, 100000)->totals;
        $page = bcsub((string) ($engine['credit'] ?? '0'), (string) ($engine['debit'] ?? '0'), 4);
        $this->assertSame(0, bccomp($m['pl']['figures']['month']['net_profit'], $page, 2),
            "⛔ মাস শেষের নিট মুনাফা লাভ-ক্ষতির পাতার ({$page}) সাথে মেলে না।");

        $sales = $this->booksNet('2026-10-01', '2026-10-31', StandardChart::NET_SALES);
        $this->assertSame(0, bccomp($m['pl']['figures']['month']['net_sales'], $sales, 2),
            "⛔ নিট বিক্রি {$m['pl']['figures']['month']['net_sales']}, বিক্রির খাতগুলো বলে {$sales}।");

        // ⓘ মোট বিল − ফেরত − মূসক − ছাড় = নিট বিক্রি (লাইনগুলো নিজেদের মধ্যে মেলে)
        $f = $m['pl']['figures']['month'];
        $this->assertSame(0, bccomp(bcsub(bcsub(bcsub($f['gross_bill'], $f['returns'], 4), $f['vat'], 4), $f['discount'], 4), $f['net_sales'], 2),
            '⛔ লাভ-ক্ষতির উপরের চার লাইন নিট বিক্রিতে নামে না।');

        $sales = collect($m['checks'])->firstWhere('key', 'sales_books');
        $this->assertSame('ok', $sales['state'] ?? null, '⛔ খাতার বিক্রি আর বিলের নিট বিক্রি মিলল না: '.($sales['result'] ?? '—'));
    }

    public function test_one_bill_moves_every_line_by_its_own_amount(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));
        $before = $this->pack('2026-10');
        Carbon::setTestNow(Carbon::parse('2026-10-15 11:00:00'));
        $bill = $this->sell($this->mms, '700');
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));
        $after = $this->pack('2026-10');

        $net = bcsub(bcsub((string) $bill->total, (string) $bill->tax, 4), (string) $bill->freight_charge, 4);
        $moved = fn (callable $read) => bcsub((string) $read($after), (string) $read($before), 4);

        $claims = [
            'মোট বিল (মূসকসহ)' => [$moved(fn ($m) => $m['pl']['figures']['month']['gross_bill']), bcadd($net, (string) $bill->tax, 4)],
            'মূসক' => [$moved(fn ($m) => $m['pl']['figures']['month']['vat']), (string) $bill->tax],
            'নিট বিক্রি' => [$moved(fn ($m) => $m['pl']['figures']['month']['net_sales']), $net],
            'বিক্রীত পণ্যের ব্যয়' => [$moved(fn ($m) => $m['pl']['figures']['month']['cogs']), (string) $bill->cost_of_goods],
            'পাওনা — বাকিতে বিক্রি' => [$moved(fn ($m) => $m['receivables']['sales']), (string) $bill->total],
            'পাওনা — শেষ' => [$moved(fn ($m) => $m['receivables']['closing']), (string) $bill->total],
            'মজুদ — বিক্রীত পণ্যের ব্যয়' => [$moved(fn ($m) => $m['stock']['cogs']), (string) $bill->cost_of_goods],
            'মূসক — দিতে হবে' => [$moved(fn ($m) => $m['vat']['due']), (string) $bill->tax],
            'বছরে এ পর্যন্ত — নিট বিক্রি' => [$moved(fn ($m) => $m['pl']['figures']['ytd']['net_sales']), $net],
            'আগের মাস — নিট বিক্রি' => [$moved(fn ($m) => $m['pl']['figures']['prior']['net_sales']), '0'],
            'বিল — সংখ্যা' => [$moved(fn ($m) => (string) $m['sales']['kinds']['bills']), '1'],
        ];

        $this->assertGreaterThan(0, bccomp((string) $bill->cost_of_goods, '0', 4), 'দৃশ্যটাই ভুল — বিলে মালের দাম শূন্য।');

        foreach ($claims as $what => [$got, $want]) {
            $this->assertSame(0, bccomp($got, $want, 2), "⛔ একটা বিলে \"{$what}\" নড়ল {$got}, নড়ার কথা {$want}।");
        }
    }

    public function test_a_draft_bill_is_in_no_total(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));
        $before = $this->pack('2026-10');

        Carbon::setTestNow(Carbon::parse('2026-10-15 11:00:00'));
        $draft = $this->sell($this->mms, '900', confirm: false);
        $this->assertSame('draft', (string) $draft->status, 'দৃশ্যটাই ভুল — বিলটা খসড়া নয়।');
        // ⓘ দেমো পণ্যে মূসক নেই — খসড়ায় মূসক বসানো, যাতে "মূসক" লাইনেও খসড়া ধরা পড়ে
        DB::table('sal_invoices')->where('id', $draft->id)->update(['tax' => '45.0000', 'total' => bcadd((string) $draft->total, '45', 4)]);
        $draft = $draft->fresh();
        $this->assertGreaterThan(0, bccomp((string) $draft->tax, '0', 4), 'দৃশ্যটাই ভুল — খসড়ায় মূসক নেই, দাবিটা কিছু মাপছে না।');

        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));
        $after = $this->pack('2026-10');

        foreach ([
            'মোট বিল' => fn ($m) => $m['pl']['figures']['month']['gross_bill'],
            'মূসক' => fn ($m) => $m['pl']['figures']['month']['vat'],
            'বিলের সংখ্যা' => fn ($m) => (string) $m['sales']['kinds']['bills'],
            'মূসক — দিতে হবে' => fn ($m) => $m['vat']['output'],
        ] as $what => $read) {
            $this->assertSame(0, bccomp((string) $read($after), (string) $read($before), 4), "⛔ খসড়া বিল \"{$what}\"-এ গোনা হলো।");
        }
    }

    public function test_the_comparisons_read_the_right_months_even_on_the_31st(): void
    {
        $p = MonthPeriods::of(CarbonImmutable::create(2026, 3, 1));
        $this->assertSame(['2026-02-01', '2026-02-28'], [$p->prior()->from->toDateString(), $p->prior()->to->toDateString()], '⛔ মার্চের আগের মাস ফেব্রুয়ারি নয়।');
        $this->assertSame(['2025-03-01', '2025-03-31'], [$p->lastYear()->from->toDateString(), $p->lastYear()->to->toDateString()], '⛔ গত বছরের একই মাস ভুল।');

        // ⓘ ৩১ অক্টোবর রাতে — ঠিকানায় মাস না থাকলে আগের মাস সেপ্টেম্বর, তার আগের মাস আগস্ট; `subMonth()` হলে অক্টোবরেই থাকত
        Carbon::setTestNow(Carbon::parse('2026-10-31 23:30:00'));
        $default = MonthPeriods::fromQuery(null);
        $this->assertSame('2026-09', $default->key(), '⛔ ৩১ অক্টোবরে "আগের মাস" হলো '.$default->key().' — উপচে এই মাসেই ফিরেছে।');
        $this->assertSame('2026-08-01', $default->prior()->from->toDateString(), '⛔ সেপ্টেম্বরের আগের মাস আগস্ট নয়।');
        $this->assertSame('2026-07-01', $default->yearStart()->toDateString(), '⛔ সেপ্টেম্বরের অর্থবছর জুলাই থেকে নয়।');

        $m = $this->pack(null);
        $this->assertSame('2026-09', $m['period']['key'], '⛔ পাতাটা মাস না দিলে আগের মাস খোলেনি।');
        $this->assertSame(0, bccomp($m['pl']['figures']['prior']['net_sales'], $this->booksNet('2026-08-01', '2026-08-31', StandardChart::NET_SALES), 2),
            '⛔ "আগের মাস"-এর ঘর আগস্টের খাতা পড়েনি।');

        // ⓘ ভবিষ্যতের মাস চাইলে চলতি মাস — খালি ভবিষ্যৎ দেখিয়ে "সব শূন্য" বলা নয়
        $this->assertSame('2026-10', MonthPeriods::fromQuery('2027-02')->key(), '⛔ ভবিষ্যতের মাস খুলে গেল।');
    }

    public function test_one_branch_picked_shows_only_that_branchs_month(): void
    {
        $mms = $this->sell($this->mms, '700');
        $ntk = $this->sell($this->ntk, '300');
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));

        $all = $this->pack('2026-10');
        $this->choose($this->ntk->id);
        $one = $this->pack('2026-10');

        $this->assertSame($this->ntk->name(), $one['period']['branch'], '⛔ তথ্যের লাইনে বাছা শাখার নাম নেই।');
        $this->assertSame(0, bccomp($one['pl']['figures']['month']['net_sales'], $this->booksNet('2026-10-01', '2026-10-31', StandardChart::NET_SALES, $this->ntk->id), 2),
            '⛔ নেত্রকোনা বাছা, অথচ নিট বিক্রি অন্য শাখার খাতাও পড়ল।');
        $this->assertGreaterThan(0, bccomp($all['pl']['figures']['month']['net_sales'], $one['pl']['figures']['month']['net_sales'], 2),
            '⛔ এক শাখা আর সব শাখার নিট বিক্রি একই — দেয়াল কিছু করছে না।');
        $this->assertNotContains($this->mms->name(), array_column($one['sales']['branches'], 'name'), '⛔ নেত্রকোনা বাছা, অথচ শাখার তালিকায় ময়মনসিংহ।');
        $this->assertNull($one['pl']['figures']['budget'], '⛔ এক শাখা বাছা অবস্থায় গোটা কোম্পানির বাজেট তুলনায় বসল।');
        $this->assertGreaterThan(0, bccomp((string) $mms->total, '0', 2));
        $this->assertGreaterThan(0, bccomp((string) $ntk->total, '0', 2));
    }

    public function test_both_languages_read_without_raw_keys_and_the_paper_is_a_pdf(): void
    {
        $this->sell($this->mms, '700');
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));

        foreach (['bn' => 'মাস শেষের হিসাব', 'en' => 'Month Close Pack'] as $locale => $title) {
            $this->owner->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $html = (string) $this->actingAs($this->owner->fresh())->get(route('executive.month_close', ['month' => '2026-10']))->assertOk()->getContent();

            $this->assertStringContainsString($title, $html, "⛔ {$locale}: পাতার নাম নেই।");
            $this->assertDoesNotMatchRegularExpression('/(executive|accounts)::|month_close\.[a-z_]+\./', $html, "⛔ {$locale}: পাতায় কাঁচা চাবি।");
            foreach (['pl', 'branches', 'sellers', 'money', 'fixed', 'receivables', 'ageing', 'payables', 'stock', 'vat', 'payroll', 'checks'] as $section) {
                $this->assertStringContainsString('data-section="'.$section.'"', $html, "⛔ {$locale}: \"{$section}\" অংশটা পাতায় নেই।");
            }
            $this->assertStringContainsString('data-check="sales_books"', $html, '⛔ চেকলিস্টে খাতা-বনাম-বিলের মিল নেই।');
            $this->assertStringContainsString('data-close-link', $html, '⛔ খোলা মাসে মাস বন্ধের পাতার পথ নেই।');
        }

        $pdf = $this->get(route('executive.month_close', ['month' => '2026-10', 'pdf' => 1]))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'), '⛔ PDF নয়।');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent(), '⛔ ফাঁকা বা ভাঙা PDF।');
        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0\s+0\s+595\.\d+\s+841\.\d+/', (string) $pdf->getContent(), '⛔ PDF খাড়া A4 নয়।');
    }

    public function test_the_door_asks_for_the_month_close_key(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));
        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('accounts.report', 'web')));

        $this->assertSame(403, $this->actingAs($clerk->fresh())->get(route('executive.month_close'))->status(),
            '⛔ মাস শেষের চাবি ছাড়া কেউ মাস শেষের হিসাব খুলল।');

        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('accounts.report.month_close', 'web')));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame(200, $this->actingAs($clerk->fresh())->get(route('executive.month_close'))->status(),
            '⛔ চাবি থাকা মানুষ মাস শেষের হিসাব খুলতে পারলেন না।');
    }

    // ─────────────────────────────── সাহায্য ───────────────────────────────

    /** @return array<string, mixed> */
    private function pack(?string $month): array
    {
        app(DataScope::class)->forget();
        $response = $this->actingAs($this->owner->fresh())->get(route('executive.month_close', $month === null ? [] : ['month' => $month]))->assertOk();

        return $response->viewData('m');
    }

    /** খাতা থেকে সরাসরি — আয়-খরচের (বা কয়েকটা খাতের) `credit − debit`, বছর বন্ধ বাদে */
    private function booksNet(string $from, string $to, ?array $codes = null, ?int $branch = null): string
    {
        $ids = null;

        if ($codes !== null) {
            $parents = Account::query()->whereIn('code', $codes)->pluck('id')->all();
            $ids = Account::query()->whereIn('id', $parents)->orWhereIn('parent_id', $parents)->pluck('id')->all();
        }

        return bcadd((string) DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', $this->company->id)
            ->whereIn('accounts.type', [Account::INCOME, Account::EXPENSE])
            ->when($ids !== null, fn ($q) => $q->whereIn('ledger_entries.account_id', $ids))
            ->when($branch !== null, fn ($q) => $q->where('ledger_entries.branch_id', $branch))
            ->whereBetween('ledger_entries.trx_date', [$from, $to])
            ->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())
            ->selectRaw('COALESCE(SUM(ledger_entries.credit - ledger_entries.debit), 0) as n')->value('n'), '0', 4);
    }

    private function sell(Branch $branch, string $rate, bool $confirm = true): SalesInvoice
    {
        CompanyContext::set($this->company->id, $branch->id);
        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('branch_id', $branch->id)->orderBy('id')->value('id');
        $product = Product::query()->whereNotNull('tax_id')->value('id') ?? Product::query()->value('id');

        $invoice = app(SalesInvoiceService::class)->create(
            ['customer_id' => Customer::query()->orderBy('id')->value('id'), 'branch_id' => $branch->id, 'warehouse_id' => $warehouse, 'trx_date' => now()->toDateString()],
            [['product_id' => $product, 'qty' => '1', 'rate' => $rate]],
        );

        if ($confirm) {
            app(SalesInvoiceService::class)->confirm($invoice);
        }

        CompanyContext::set($this->company->id, $this->mms->id);

        return SalesInvoice::query()->withoutGlobalScopes()->findOrFail($invoice->id);
    }

    private function choose(int $branch): void
    {
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $branch])->assertRedirect();
        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
    }
}
