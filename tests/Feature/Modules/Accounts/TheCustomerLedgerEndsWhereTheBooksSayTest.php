<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Reports\PartyLedgerReports;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * কাস্টমার লেজার — মালিক, ৩ অক্টোবর ২০২৬: *"একাউন্টসে কাস্টমার লেজার দিতে হবে জরুরি"* ([[PartyLedgerReports]])।
 *
 * নতুন ডিলার: ১০ সেপ্টেম্বর বিল ৫০,০০০, ২০ সেপ্টেম্বর আদায় ৩০,০০০ → ১ অক্টোবরের আগে খোলা জের (Dr) ২০,০০০।
 * অক্টোবরে: ১ তারিখ বিল ২০,০০০ → (Dr) ৪০,০০০; ২ তারিখ আদায় ৪৫,০০০ → (Cr) ৫,০০০ (অগ্রিম)।
 * ⭐ শেষ জের = খাতার বকেয়া ([[Customer::outstanding()]]) — একই খাতা, একই উত্তর।
 */
final class TheCustomerLedgerEndsWhereTheBooksSayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-03 11:00:00'));

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->dealer = Customer::query()->create(['code' => 'CL-1', 'name_en' => 'Ledger Dealer', 'name_bn' => 'Ledger Dealer', 'is_active' => true]);
    }

    public function test_opening_lines_running_balance_and_closing_equal_the_books(): void
    {
        $this->happen();

        $result = $this->ledger(PartyLedgerReports::CUSTOMER, ['customer_id' => $this->dealer->id]);
        $rows = $result->rows;

        $this->assertCount(3, $rows, 'খোলা জের + অক্টোবরের দুই সারি।');
        $this->assertSame([0, 0], [bccomp((string) $rows[0]['debit'], '20000', 2), bccomp((string) $rows[0]['credit'], '0', 2)], '⛔ খোলা জের (Dr) ২০,০০০ হওয়ার কথা।');

        $balances = array_map(fn (array $r) => \App\Core\Support\Money::drCr($r['balance']), $rows);
        $this->assertSame(['(Dr) 20,000.00', '(Dr) 40,000.00', '(Cr) 5,000.00'], $balances, '⛔ চলমান জের ভুল।');

        // ⭐ শেষ জের = খাতার বকেয়া
        $summary = $this->summary($result);
        $this->assertSame(0, bccomp($summary['value'], $this->dealer->fresh()->outstanding(), 2),
            "⛔ শেষ জের {$summary['value']}, খাতার বকেয়া {$this->dealer->fresh()->outstanding()}।");
        $this->assertSame('(Cr) 5,000.00', $summary['text']);
        $this->assertSame(0, bccomp((string) end($rows)['balance'], $summary['value'], 2), '⛔ শেষ সারির জের আর শেষ জের আলাদা।');
    }

    public function test_the_page_prints_dr_cr_never_a_bare_minus_and_the_file_says_the_same(): void
    {
        $this->happen();
        $url = route('accounts.report.show', ['slug' => 'customer-ledger', 'customer_id' => $this->dealer->id, 'from' => '2026-10-01', 'to' => '2026-10-03']);

        $page = (string) $this->get($url)->assertOk()->getContent();

        foreach (['(Dr) 20,000.00', '(Dr) 40,000.00', '(Cr) 5,000.00'] as $must) {
            $this->assertStringContainsString($must, $page);
        }
        $this->assertStringNotContainsString('-5,000.00', $page, '⛔ জেরে খালি বিয়োগ চিহ্ন — মালিক চাননি।');

        $response = $this->get($url.'&export=csv')->assertOk();
        $file = (string) ($response->getContent() ?: $response->streamedContent());
        $this->assertStringContainsString('(Cr) 5,000.00', $file, '⛔ ফাইলে জেরের লেখা পর্দার মতো নয়।');
    }

    /** ⓘ অগ্রিম নিয়ে শুরু — খোলা জের ক্রেডিটে, "(Cr) 5,000.00"; ৩ অক্টোবর থেকে খুললে ২ তারিখের আদায় আগের জেরে পড়ে। */
    public function test_an_advance_opens_on_the_credit_side(): void
    {
        $this->happen();

        $rows = app(ReportEngine::class)->run(PartyLedgerReports::CUSTOMER,
            ['from' => '2026-10-03', 'to' => '2026-10-03', 'customer_id' => $this->dealer->id], perPage: 500)->rows;

        $this->assertCount(1, $rows);
        $this->assertSame([0, 0], [bccomp((string) $rows[0]['debit'], '0', 2), bccomp((string) $rows[0]['credit'], '5000', 2)], '⛔ অগ্রিমের খোলা জের ক্রেডিটে বসার কথা।');
        $this->assertSame('(Cr) 5,000.00', \App\Core\Support\Money::drCr($rows[0]['balance']));
    }

    /** ⛔ একই নম্বরের সরবরাহকারীর সারি গ্রাহকের লেজারে নয় — পক্ষের ধরন আর নম্বর, দুইটাই। */
    public function test_a_supplier_with_the_same_number_stays_out(): void
    {
        $this->happen();
        $payable = (int) StandardChart::find(StandardChart::PAYABLE)->id;
        $stock = (int) DB::table('accounts')->where('company_id', $this->company->id)->where('is_group', false)->where('type', 'asset')->orderBy('id')->value('id');
        $this->travelTo(Carbon::parse('2026-10-02 15:00:00'));
        $this->ledgerPost('purchase_bill', [['account_id' => $stock, 'debit' => '900'], ['account_id' => $payable, 'credit' => '900', 'party_type' => 'supplier', 'party_id' => (int) $this->dealer->id]]);
        $this->travelTo(Carbon::parse('2026-10-03 11:00:00'));

        $result = $this->ledger(PartyLedgerReports::CUSTOMER, ['customer_id' => $this->dealer->id]);
        $this->assertCount(3, $result->rows, '⛔ সরবরাহকারীর সারি গ্রাহকের লেজারে ঢুকেছে।');
        $this->assertSame('(Cr) 5,000.00', $this->summary($result)['text']);
    }

    public function test_no_customer_chosen_shows_nothing(): void
    {
        $this->happen();

        $this->assertSame([], $this->ledger(PartyLedgerReports::CUSTOMER, [])->rows);
    }

    public function test_the_header_branch_narrows_the_opening_and_the_lines_alike(): void
    {
        $this->happen();
        $other = $this->otherBranch();
        $this->travelTo(Carbon::parse('2026-10-02 12:00:00'));
        $this->sale('7000', $other);
        $this->travelTo(Carbon::parse('2026-10-03 11:00:00'));

        $home = $this->ledger(PartyLedgerReports::CUSTOMER, ['customer_id' => $this->dealer->id, 'branch_id' => $this->company->defaultBranch()?->id]);
        $this->assertSame('(Cr) 5,000.00', $this->summary($home)['text'], '⛔ অন্য শাখার বিল এই শাখার লেজারে ঢুকেছে।');

        $there = $this->ledger(PartyLedgerReports::CUSTOMER, ['customer_id' => $this->dealer->id, 'branch_id' => $other]);
        $this->assertCount(2, $there->rows, 'ঐ শাখার খোলা জের (শূন্য) + একটা বিল।');
        $this->assertSame('(Dr) 7,000.00', $this->summary($there)['text']);

        $all = $this->ledger(PartyLedgerReports::CUSTOMER, ['customer_id' => $this->dealer->id]);
        $this->assertSame(0, bccomp($this->summary($all)['value'], $this->dealer->fresh()->outstanding(), 2), '⛔ সব শাখায় শেষ জের খাতার বকেয়ার সমান নয়।');
    }

    public function test_the_supplier_ledger_reads_cr_for_what_we_owe(): void
    {
        $supplier = Supplier::query()->create(['code' => 'SL-1', 'name_en' => 'Ledger Supplier', 'name_bn' => 'Ledger Supplier', 'is_active' => true]);
        $payable = (int) StandardChart::find(StandardChart::PAYABLE)->id;
        $stock = (int) DB::table('accounts')->where('company_id', $this->company->id)->where('is_group', false)->where('type', 'asset')->orderBy('id')->value('id');

        $this->ledgerPost('purchase_bill', [['account_id' => $stock, 'debit' => '12000'], ['account_id' => $payable, 'credit' => '12000', 'party_type' => 'supplier', 'party_id' => (int) $supplier->id]]);

        $result = $this->ledger(PartyLedgerReports::SUPPLIER, ['supplier_id' => $supplier->id]);
        $this->assertSame('(Cr) 12,000.00', $this->summary($result)['text'], '⛔ সরবরাহকারীর দেনা (Cr) হওয়ার কথা।');
        $this->assertSame(0, bccomp(bcmul($this->summary($result)['value'], '-1', 4), $supplier->fresh()->payable(), 2), '⛔ শেষ জের খাতার দেনার সমান নয়।');
    }

    public function test_the_door_is_the_report_key_same_person_off_then_on(): void
    {
        $clerk = User::query()->create(['name' => 'CL Clerk', 'email' => 'cl-clerk@abos.test', 'password' => bcrypt('x')]);
        $clerk->companies()->attach($this->company->id);
        $url = route('accounts.report.show', ['slug' => 'customer-ledger']);

        $this->actingAs($clerk)->get($url)->assertForbidden();

        Permission::findOrCreate('accounts.report', 'web');
        $clerk->givePermissionTo('accounts.report');
        $this->actingAs($clerk->fresh())->get($url)->assertOk();
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function happen(): void
    {
        foreach ([['2026-09-10', 'sale', '50000'], ['2026-09-20', 'pay', '30000'], ['2026-10-01', 'sale', '20000'], ['2026-10-02', 'pay', '45000']] as [$day, $what, $amount]) {
            $this->travelTo(Carbon::parse($day.' 12:00:00'));
            $what === 'sale' ? $this->sale($amount) : $this->collect($amount);
        }

        $this->travelTo(Carbon::parse('2026-10-03 11:00:00'));
    }

    private function sale(string $amount, ?int $branchId = null): void
    {
        $income = (int) DB::table('accounts')->where('company_id', $this->company->id)->where('is_group', false)->where('type', 'income')->orderBy('id')->value('id');
        $this->ledgerPost('sales_invoice', [['account_id' => $this->receivable(), 'debit' => $amount, ...$this->party()], ['account_id' => $income, 'credit' => $amount]], $branchId);
    }

    private function collect(string $amount): void
    {
        $cash = (int) DB::table('accounts')->where('company_id', $this->company->id)->where('money_kind', 'cash')->where('is_group', false)->orderBy('id')->value('id');
        $this->ledgerPost('collection', [['account_id' => $cash, 'debit' => $amount], ['account_id' => $this->receivable(), 'credit' => $amount, ...$this->party()]]);
    }

    private function ledgerPost(string $source, array $lines, ?int $branchId = null): void
    {
        app(PostingEngine::class)->post(
            sourceType: $source, sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: $lines,
            branchId: $branchId ?? $this->company->defaultBranch()?->id,
        );
    }

    private function ledger(string $key, array $filters): \App\Core\Engines\Report\ReportResult
    {
        return app(ReportEngine::class)->run($key, ['from' => '2026-10-01', 'to' => '2026-10-03', ...$filters], perPage: 500);
    }

    /** @return array{label: string, value: string, text: string, good: bool} */
    private function summary(\App\Core\Engines\Report\ReportResult $result): array
    {
        return (app(ReportEngine::class)->get(PartyLedgerReports::CUSTOMER)->summary)($result->totals);
    }

    private function otherBranch(): int
    {
        return (int) \App\Models\Branch::query()->create([
            'company_id' => $this->company->id, 'code' => 'CL-B2', 'name_en' => 'Ledger Branch Two', 'is_active' => true,
        ])->id;
    }

    private function receivable(): int
    {
        return (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
    }

    /** @return array{party_type: string, party_id: int} */
    private function party(): array
    {
        return ['party_type' => 'customer', 'party_id' => (int) $this->dealer->id];
    }
}
