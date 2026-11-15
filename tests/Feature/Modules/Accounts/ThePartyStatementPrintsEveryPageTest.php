<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Support\PartyStatement;
use App\Modules\Accounts\Support\PartyStatementPages;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ হিসাব বিবরণী — ব্যাংকের মতো, সব পাতা (মালিক, ১৫ নভেম্বর ২০২৬: "৫ বছরের লেজার প্রিন্ট দিব … বাকি পাতাগুলো তো আসতেছে না";
 * নমুনা অনুমোদিত — [[PartyStatement]], [[PartyStatementPages]], [[PartyStatementController]])।
 *
 *   - সারির জের, শুরুর জের ("থেকে"-র আগের সব), মোট আর শেষ জের নিজেদের সাথে মেলে
 *   - বিবরণ = কাগজের নাম বা নগদ/ব্যাংকের নাম, পাশে কাগজের ন্যারেশন; ধরন মালিকের শব্দে
 *   - PDF-এ সব পাতা; প্রতি পাতার "পরের পৃষ্ঠায় জের" পরের পাতার "পূর্ব পৃষ্ঠার জের"
 *   - দলের পাতার ছাপার বোতাম বিবরণী খোলে (আগে পর্দার ৫০ সারি ছাপত)
 *   - অন্য কোম্পানির পক্ষ ৪০৪; অনুমতি ছাড়া ৪০৩; গ্রাহক দেখার অনুমতিতে চলে
 */
final class ThePartyStatementPrintsEveryPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $shop;

    private int $year;

    private int $receivable;

    private Account $cash;

    private Account $bank;

    private int $next = 990000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->shop = app(CustomerService::class)->create(['name_en' => 'Zst Ma Traders', 'name_bn' => 'Zst Ma Traders', 'credit_limit' => '0']);
        $this->year = (int) DB::table('financial_years')->where('company_id', $this->company->id)->orderBy('id')->value('id');
        $this->receivable = (int) Account::query()->whereNull('money_kind')->where('is_group', false)->orderBy('id')->value('id');
        fwrite(STDERR, "
");
        $this->cash = Account::query()->money()->where('money_kind', Account::CASH)->orderBy('id')->firstOrFail();
        // ⓘ ডেমোতে ব্যাংকের খাত নেই (কেবল দলটা) — নগদের খাতের আদলে একটা ব্যাংকের খাত, ব্যাংকের দলের নিচে
        $this->bank = $this->cash->replicate();
        $this->bank->forceFill(['public_id' => (string) str()->uuid7(), 'code' => 'ZST-BANK', 'name_en' => 'Zst Dutch-Bangla Bank', 'name_bn' => 'Zst ডাচ্-বাংলা ব্যাংক',
            'money_kind' => Account::BANK,
            'parent_id' => Account::query()->where('money_kind', Account::BANK)->where('is_group', true)->value('id') ?? $this->cash->parent_id])->save();
    }

    public function test_the_rows_add_up_and_the_opening_counts_everything_before_from(): void
    {
        $this->sale('2026-01-10', '1000', 'ZST-SI-1');
        $this->receipt('2026-02-10', '400', $this->cash, 'ZST-RV-1');
        $this->sale('2026-03-05', '250', 'ZST-SI-2');

        $s = app(PartyStatement::class)->build('customer', (int) $this->shop->id, '2026-02-01', '2026-12-31');
        $this->assertSame('1000.00', $s['opening'], '⛔ শুরুর জের "থেকে"-র আগের সব সারি গোনেনি');
        $this->assertCount(2, $s['rows'], '⛔ সময়ের ভেতরের সারি দুটো');
        $this->assertSame(['600.00', '850.00'], array_column($s['rows'], 'balance'), '⛔ সারির জের শুরুর জের থেকে চলেনি');
        $this->assertSame(['250.00', '400.00', '850.00'], [$s['debit'], $s['credit'], $s['closing']], '⛔ মোট বা শেষ জের ভুল');
        $this->assertSame([1, 2], array_column($s['rows'], 'sl'), '⛔ ক্রমিক নং একটানা নয়');

        $all = app(PartyStatement::class)->build('customer', (int) $this->shop->id, null, '2026-12-31');
        $this->assertSame('0', $all['opening']);
        $this->assertCount(3, $all['rows']);
        $this->assertSame('850.00', $all['closing'], '⛔ শুরু থেকে আর মাঝ থেকে — শেষ জের আলাদা');
    }

    public function test_particulars_name_the_money_side_and_the_narration_with_the_owners_types(): void
    {
        $this->sale('2026-01-10', '1000', 'ZST-SI-1', 'ঈদের অর্ডার');
        $this->sale('2026-01-11', '300', 'ZST-SI-2');
        $this->receipt('2026-02-10', '400', $this->cash, 'ZST-RV-1');
        $this->receipt('2026-02-12', '100', $this->bank, 'ZST-RV-2');

        $rows = app(PartyStatement::class)->build('customer', (int) $this->shop->id, null, '2026-12-31')['rows'];
        $this->assertSame(__('accounts::statement.doc.sales_invoice').' — ঈদের অর্ডার', $rows[0]['particulars'],
            '⛔ বিলের বিবরণে কাগজের নাম আর ন্যারেশন নেই');
        $this->assertSame(__('accounts::statement.doc.sales_invoice'), $rows[1]['particulars'], '⛔ ন্যারেশন না থাকলে শুধু নাম');
        $this->assertSame($this->cash->name(), $rows[2]['particulars'], '⛔ জমার বিবরণে নগদের খাতের নাম নেই');
        $this->assertSame($this->bank->name(), $rows[3]['particulars'], '⛔ জমার বিবরণে ব্যাংকের নাম নেই');
        $this->assertSame([__('accounts::statement.kind.sales'), __('accounts::statement.kind.sales'), __('accounts::statement.kind.receive'),
            __('accounts::statement.kind.receive')], array_column($rows, 'kind'), '⛔ ধরন মালিকের শব্দে নয়');
    }

    public function test_the_pdf_carries_every_page_and_each_carried_balance_is_the_next_brought_one(): void
    {
        for ($i = 1; $i <= 90; $i++) {
            $i % 3 === 0
                ? $this->receipt('2026-01-01', (string) (100 + $i), $this->cash, 'ZST-RV-'.$i)
                : $this->sale('2026-01-01', (string) (1000 + $i), 'ZST-SI-'.$i, $i % 5 === 0 ? 'দোকান উদ্বোধনের মাল, বিক্রয় প্রতিনিধির মাধ্যমে' : null);
        }

        $response = $this->get(route('accounts.statement', ['type' => 'customer', 'id' => $this->shop->id]));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        // ⭐ শিরোনাম দলের ধরন দেখে — গ্রাহকের "কাস্টমার লেজার" (মালিক, ১৫ নভেম্বর ২০২৬); ফাইলের নামেও সেই নাম
        $this->assertStringContainsString('customer-ledger', (string) $response->headers->get('Content-Disposition'),
            '⛔ গ্রাহকের কাগজের নাম "কাস্টমার লেজার" নয়');
        $this->assertGreaterThanOrEqual(3, (int) $response->headers->get('X-Statement-Pages'), '⛔ ৯০ সারির বিবরণী তিন পাতার কম — সব সারি ছাপা হয়নি');

        $statement = app(PartyStatement::class)->build('customer', (int) $this->shop->id, null, now()->toDateString());
        $pages = app(PartyStatementPages::class)->split($statement['rows'], true);
        $this->assertSame(90, array_sum(array_map('count', $pages)), '⛔ পাতা ভাগে সারি হারাল');
        $this->assertGreaterThan(1, count($pages));

        $html = View::make('accounts::print.party-statement', [
            'statement' => $statement, 'pages' => $pages, 'company' => $this->company, 'locale' => 'bn', 'signGap' => 0.0,
            'docTitle' => __('accounts::statement.title.customer'), 'docTitleEn' => __('accounts::statement.title_en.customer'),
            'party' => ['name' => 'Zst Ma Traders', 'code' => '', 'facts' => []], 'from' => null, 'to' => now()->toDateString(),
            'period' => 'x', 'branch' => 'x', 'printedAt' => 'x', 'printedBy' => 'x', 'signatures' => true, 'colour' => false,
        ])->render();

        preg_match_all('/data-carry="(brought|carried)">.*?<td class="num">([^<]+)<\/td>/s', $html, $m, PREG_SET_ORDER);
        $carried = array_values(array_map(fn ($x) => $x[2], array_filter($m, fn ($x) => $x[1] === 'carried')));
        $brought = array_values(array_map(fn ($x) => $x[2], array_filter($m, fn ($x) => $x[1] === 'brought')));
        $this->assertCount(count($pages) - 1, $carried, '⛔ প্রতি পাতার শেষে "পরের পৃষ্ঠায় জের" নেই');
        $this->assertSame($carried, $brought, '⛔ "পরের পৃষ্ঠায় জের" আর পরের পাতার "পূর্ব পৃষ্ঠার জের" আলাদা');
        $this->assertStringContainsString('data-recon', $html, '⛔ শেষ পাতায় মেলানোর হিসাব নেই');
    }

    public function test_the_party_page_print_opens_the_statement_not_the_fifty_rows_on_screen(): void
    {
        $html = (string) $this->get(route('customer.show', $this->shop))->assertOk()->getContent();
        $this->assertStringContainsString(e(route('accounts.statement', ['type' => 'customer', 'id' => $this->shop->id])), $html,
            '⛔ দলের পাতার ছাপার বোতাম বিবরণী খোলে না — আগের মতো পর্দার ৫০ সারি ছাপবে');
    }

    public function test_only_the_right_people_open_it_and_only_in_their_own_company(): void
    {
        $this->sale('2026-01-10', '1000', 'ZST-SI-1');
        $url = route('accounts.statement', ['type' => 'customer', 'id' => $this->shop->id]);

        $this->actingAs($this->person([]))->get($url)->assertForbidden();
        $this->actingAs($this->person(['customer.view']))->get($url)->assertOk();
        $this->actingAs($this->person(['accounts.report']))->get($url)->assertOk();

        $other = Company::query()->where('id', '<>', $this->company->id)->orderBy('id')->firstOrFail();
        $theirs = (int) DB::table('customers')->where('company_id', $other->id)->value('id');
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail())
            ->get(route('accounts.statement', ['type' => 'customer', 'id' => $theirs ?: 999999]))->assertNotFound();
        $this->get(route('accounts.statement', ['type' => 'nobody', 'id' => 1]))->assertNotFound();
    }

    public function test_any_account_prints_its_own_account_ledger_naming_the_other_side(): void
    {
        $this->receipt('2026-02-10', '400', $this->cash, 'ZST-RV-1');
        $this->receipt('2026-02-12', '150', $this->cash, 'ZST-RV-2');

        // ⭐ যেকোনো হিসাবের খাত — "অ্যাকাউন্ট লেজার" (মালিক, ১৫ নভেম্বর ২০২৬)
        $s = app(PartyStatement::class)->buildAccount($this->cash, '2026-02-01', '2026-12-31');
        $mine = array_values(array_filter($s['rows'], fn ($r) => str_starts_with($r['no'], 'ZST-RV-')));
        $this->assertCount(2, $mine, '⛔ নগদের খাতের লেজারে দুটো জমা আসেনি');
        $this->assertSame('Dr', $s['normal'], '⛔ নগদ সম্পদ — স্বাভাবিক জের ডেবিট');
        $this->assertStringContainsString('Zst Ma Traders', $mine[0]['particulars'],
            '⛔ অ্যাকাউন্ট লেজারে অপর পাশ (যার কাছ থেকে টাকা এল) লেখা নেই');

        $response = $this->get(route('accounts.statement', ['type' => 'account', 'id' => $this->cash->id]));
        $response->assertOk();
        $this->assertStringContainsString('account-ledger', (string) $response->headers->get('Content-Disposition'), '⛔ শিরোনাম "অ্যাকাউন্ট লেজার" নয়');

        $this->actingAs($this->person(['customer.view']))->get(route('accounts.statement', ['type' => 'account', 'id' => $this->cash->id]))
            ->assertForbidden();
    }

    public function test_an_edited_bill_shows_once_and_a_supplier_ledger_leaves_out_goods_not_yet_billed(): void
    {
        // ⓘ সম্পাদনা: আগের সারি, তার উল্টো সারি, তারপর নতুন সারি — বিবরণীতে কেবল শেষটা (পর্দার খাতার একই নিয়ম)
        $id = DB::table('sal_invoices')->insertGetId([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => 'ZST-SI-ED',
            'customer_id' => $this->shop->id, 'trx_date' => '2026-01-10', 'status' => 'confirmed', 'subtotal' => '600', 'total' => '600',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entry('sales_invoice', $id, 'ZST-SI-ED', '2026-01-10', $this->receivable, '500', '0', true);
        $this->entry('sales_invoice:reversal', $id, 'ZST-SI-ED', '2026-01-10', $this->receivable, '0', '500', true);
        $this->entry('sales_invoice', $id, 'ZST-SI-ED', '2026-01-10', $this->receivable, '600', '0', true);

        $s = app(PartyStatement::class)->build('customer', (int) $this->shop->id, null, '2026-12-31');
        $this->assertCount(1, $s['rows'], '⛔ সম্পাদিত বিলের আগের সারি আর উল্টো সারিও বিবরণীতে উঠল');
        $this->assertSame('600.00', $s['closing']);

        // ⓘ সরবরাহকারী: বিল-না-আসা মালের (GRNI) সারি দেনা নয় — পাতার দেনার হুবহু ভিত্তি
        $supplier = app(\App\Modules\Supplier\Services\SupplierService::class)->create(['name_en' => 'Zst Rahman Enterprise', 'name_bn' => 'Zst Rahman Enterprise']);
        $grni = (int) \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::GOODS_RECEIVED_NOT_INVOICED)->id;
        foreach ([[$this->receivable, '0', '900'], [$grni, '0', '400']] as $i => [$account, $dr, $cr]) {
            DB::table('ledger_entries')->insert([
                'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'financial_year_id' => $this->year,
                'account_id' => $account, 'party_type' => 'supplier', 'party_id' => $supplier->id, 'trx_date' => '2026-02-0'.($i + 1),
                'debit' => $dr, 'credit' => $cr, 'source_type' => 'journal_voucher', 'source_id' => 880000 + $i, 'document_no' => 'ZST-JV-'.$i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $sup = app(PartyStatement::class)->build('supplier', (int) $supplier->id, null, '2026-12-31');
        $this->assertSame(['ZST-JV-0'], array_column($sup['rows'], 'no'), '⛔ সরবরাহকারীর লেজারে বিল-না-আসা মালের সারি উঠল');
        $this->assertSame('-900.00', $sup['closing']);
        $this->assertSame('Cr', $sup['normal'], '⛔ সরবরাহকারীর স্বাভাবিক জের ক্রেডিট');
    }

    public function test_the_ledger_reports_print_the_same_ledger(): void
    {
        $this->sale('2026-01-10', '1000', 'ZST-SI-1');
        $html = (string) $this->get(route('accounts.report.show', ['slug' => 'customer-ledger', 'customer_id' => $this->shop->id,
            'from' => '2026-01-01', 'to' => '2026-01-31']))->assertOk()->getContent();
        $this->assertStringContainsString(e(route('accounts.statement', ['type' => 'customer', 'id' => $this->shop->id,
            'from' => '2026-01-01', 'to' => '2026-01-31'])), $html, '⛔ হিসাবের রিপোর্টের কাস্টমার লেজারের ছাপা লেজার খোলে না');

        $gl = (string) $this->get(route('accounts.report.show', ['slug' => 'ledger', 'account_id' => $this->cash->id,
            'from' => '2026-01-01', 'to' => '2026-01-31']))->assertOk()->getContent();
        $this->assertStringContainsString(e(route('accounts.statement', ['type' => 'account', 'id' => $this->cash->id,
            'from' => '2026-01-01', 'to' => '2026-01-31'])), $gl, '⛔ সাধারণ খতিয়ানের ছাপা অ্যাকাউন্ট লেজার খোলে না');
    }

    private function sale(string $date, string $amount, string $no, ?string $narration = null): void
    {
        $id = DB::table('sal_invoices')->insertGetId([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no,
            'customer_id' => $this->shop->id, 'trx_date' => $date, 'status' => 'confirmed', 'subtotal' => $amount, 'total' => $amount,
            'narration' => $narration, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entry('sales_invoice', $id, $no, $date, $this->receivable, $amount, '0', true);
    }

    private function receipt(string $date, string $amount, Account $into, string $no): void
    {
        $id = $this->next++;
        $this->entry('receipt_voucher', $id, $no, $date, $this->receivable, '0', $amount, true);
        $this->entry('receipt_voucher', $id, $no, $date, (int) $into->id, $amount, '0', false);
    }

    private function entry(string $source, int $sourceId, string $no, string $date, int $account, string $debit, string $credit, bool $party): void
    {
        DB::table('ledger_entries')->insert([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'financial_year_id' => $this->year,
            'account_id' => $account, 'party_type' => $party ? 'customer' : null, 'party_id' => $party ? $this->shop->id : null,
            'trx_date' => $date, 'debit' => $debit, 'credit' => $credit, 'source_type' => $source, 'source_id' => $sourceId,
            'document_no' => $no, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();

        if ($keys !== []) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
                array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
