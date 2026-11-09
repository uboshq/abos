<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\LedgerBalances;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Services\FundPosition;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ অর্থের ড্যাশবোর্ডের মাথার তহবিলের ঘর খাতা আর সামনের দেনা-পাওনা পড়ে (মালিকের অনুমোদিত নমুনা, ১০ নভেম্বর ২০২৬)। [[FundPosition]]
 *
 * ⓘ ডেমোর আগের জের যা-ই থাকুক, দাবিটা পার্থক্যে: আগে মাপা, কাগজ বসানো, আবার মাপা — বাড়ার অঙ্ক হাতে গোনা।
 */
final class TheFundBoxReadsTheBooksAndTheComingDuesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // ⓘ ডেমোর অর্থবছর ১ জুলাই ২০২৬ – ৩০ জুন ২০২৭
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00'));
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_total_its_last_month_end_and_the_daily_spend_follow_the_books(): void
    {
        $fund = app(FundPosition::class);
        $before = $fund->on();

        $till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $this->journal('2026-10-10', [[$till->id, '50000', null], [StandardChart::find(StandardChart::OWNER_CAPITAL)->id, null, '50000']]);
        $this->journal('2026-11-05', [[$till->id, '120000', null], [StandardChart::find(StandardChart::SALES)->id, null, '120000']]);
        // ⓘ ৯,০০০ বেরোল — ৯০ দিনের গড়ে দিনে ঠিক ১০০
        $this->journal('2026-11-06', [[StandardChart::find(StandardChart::RENT)->id, '9000', null], [$till->id, null, '9000']]);

        $after = $fund->on();

        $this->assertSame('161000.0000', bcsub($after['total'], $before['total'], 4), '⛔ মোট তহবিল খাতার মতো বাড়েনি।');
        $this->assertSame('50000.0000', bcsub($after['previous_total'], $before['previous_total'], 4),
            '⛔ "গত মাসের চেয়ে"-র তুলনা ৩১ অক্টোবরের জের নয় — নভেম্বরের টাকাও গোনা হলো, বা অক্টোবরেরটা বাদ গেল।');
        $this->assertSame('161000.0000', bcsub($after['parts'][0]['amount'], $before['parts'][0]['amount'], 4), '⛔ হাতে নগদের ভাগে টাকাটা নেই।');
        $this->assertSame('100.0000', bcsub($after['daily_out'], $before['daily_out'], 4), '⛔ দিনে গড় খরচ = শেষ ৯০ দিনে বেরোনো ÷ ৯০ নয়।');
        $this->assertSame((int) bcdiv($after['total'], $after['daily_out'], 0), $after['runway_days'], '⛔ "খরচ চলবে" = তহবিল ÷ দিনে গড় খরচ নয়।');
        $this->assertSame(bcsub($after['total'], $after['previous_total'], 4), $after['change']);
    }

    public function test_the_tightest_day_and_the_seven_day_cover_come_from_the_dated_dues(): void
    {
        // ⓘ ডেমোর সব কাগজ জানালার বাইরে — তারপর একটা বিল আর একটা চালান হাতে বসানো
        $far = now()->addDays(400)->toDateString();
        PurchaseBill::query()->withoutGlobalScopes()->update(['due_on' => $far]);
        SalesInvoice::query()->withoutGlobalScopes()->update(['due_on' => $far]);
        LoanInstalment::query()->withoutGlobalScopes()->update(['due_date' => $far]);

        $fund = app(FundPosition::class);
        $empty = $fund->on();
        $this->assertSame('0', bcadd($empty['out30'], '0', 0), '⛔ জানালার বাইরের দেনা ৩০ দিনে গোনা হলো।');
        $this->assertNull($empty['cover7'], '⛔ ৭ দিনে দেনা নেই, তবু "ঢাকা" গুণ দেখায়।');

        $bill = PurchaseBill::query()->posted()->withPaid()->with('supplier')->get()->first(fn ($b) => bccomp($b->dueAmount(), '0', 4) > 0);
        $invoice = SalesInvoice::query()->posted()->withCollected()->get()->first(fn ($i) => bccomp($i->dueAmount(), '0', 4) > 0);
        $this->assertNotNull($bill, 'ডেমোতে বাকি থাকা ক্রয়-বিল নেই');
        $this->assertNotNull($invoice, 'ডেমোতে বাকি থাকা বিক্রয়-বিল নেই');

        PurchaseBill::query()->withoutGlobalScopes()->whereKey($bill->id)->update(['due_on' => now()->addDays(10)->toDateString()]);
        SalesInvoice::query()->withoutGlobalScopes()->whereKey($invoice->id)->update(['due_on' => now()->addDays(20)->toDateString()]);

        $f = $fund->on();
        $this->assertSame(bcadd($bill->dueAmount(), '0', 4), bcadd($f['out30'], '0', 4), '⛔ ৩০ দিনে যাবে = ১০ দিন পরের বিলটা নয়।');
        $this->assertSame(bcadd($invoice->dueAmount(), '0', 4), bcadd($f['in30'], '0', 4), '⛔ ৩০ দিনে আসবে = ২০ দিন পরের চালানটা নয়।');
        $this->assertSame('2026-11-30', $f['lowest']['date'], '⛔ সবচেয়ে টান পড়ার দিন বিলের দিন (৩০ নভেম্বর) নয়।');
        $this->assertSame(bcsub($f['total'], $bill->dueAmount(), 4), $f['lowest']['closing'], '⛔ টানের দিনের জের = তহবিল − বিল নয়।');
        $this->assertSame($bill->supplier?->name(), $f['lowest']['who'], '⛔ টানের দিনের বড় দেনার নাম নেই।');
        $this->assertNull($f['cover7'], '⛔ ১০ দিন পরের বিল ৭ দিনের দেনায় গোনা হলো।');

        // ⓘ মেয়াদ পেরোনো বিল আজকের দিনে, ৭ দিনেও
        PurchaseBill::query()->withoutGlobalScopes()->whereKey($bill->id)->update(['due_on' => now()->subDays(3)->toDateString()]);
        $late = $fund->on();
        $this->assertSame(bcadd($bill->dueAmount(), '0', 4), bcadd($late['due7'], '0', 4), '⛔ মেয়াদ পেরোনো বিল ৭ দিনের দেনায় নেই।');
        $this->assertSame(bcdiv($late['total'], $late['due7'], 1), $late['cover7'], '⛔ ৭ দিনের দেনা ঢাকা = তহবিল ÷ ৭ দিনের দেনা নয়।');
        $this->assertSame('2026-11-20', $late['lowest']['date'], '⛔ মেয়াদ পেরোনো বিল আজকের দিনে বসেনি।');
    }

    public function test_free_money_holds_back_open_issued_cheques_and_the_od_shows_what_is_left(): void
    {
        $fund = app(FundPosition::class);
        $before = $fund->on();

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $supplierId = PurchaseBill::query()->value('supplier_id');
        Cheque::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id,
            'document_no' => 'CHQ-FUND-1', 'direction' => Cheque::ISSUED, 'cheque_date' => now()->toDateString(),
            'received_on' => now()->toDateString(), 'cheque_no' => '7001', 'bank_name' => 'DBBL',
            'amount' => '25000', 'party_type' => 'supplier', 'party_id' => $supplierId, 'status' => Cheque::PENDING,
        ]);
        // ⓘ ভাঙানো চেক আর ব্যাংক থেকে কেটেছে — আটকে রাখার কিছু নেই
        Cheque::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id,
            'document_no' => 'CHQ-FUND-2', 'direction' => Cheque::ISSUED, 'cheque_date' => now()->toDateString(),
            'received_on' => now()->toDateString(), 'cheque_no' => '7002', 'bank_name' => 'DBBL',
            'amount' => '40000', 'party_type' => 'supplier', 'party_id' => $supplierId, 'status' => Cheque::CLEARED,
        ]);
        BankFacility::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'document_no' => 'BF-FUND-1',
            'kind' => BankFacility::CC, 'bank' => 'DBBL', 'limit_amount' => '500000', 'interest_rate' => '9',
            'sanctioned_on' => now()->subMonths(2)->toDateString(), 'status' => DocumentStatus::CONFIRMED,
        ]);

        $after = $fund->on();

        $this->assertSame('25000.0000', bcsub($after['held'], $before['held'], 4), '⛔ খোলা দেওয়া চেক মুক্ত টাকা থেকে আটকায়নি, বা ভাঙানোটাও আটকাল।');
        $this->assertSame(bcsub($after['total'], $after['held'], 4), $after['free']);
        $this->assertSame('500000.0000', bcsub($after['od_limit'], $before['od_limit'], 4), '⛔ চালু CC-র সীমা গোনা হয়নি।');
        $this->assertSame('500000.0000', bcsub($after['od_left'], $before['od_left'], 4), '⛔ না-তোলা CC-র পুরো সীমা খালি দেখায় না।');
    }

    public function test_the_dashboard_draws_the_box_first(): void
    {
        $html = (string) $this->get(route('module.dashboard', ['module' => 'finance']))->assertOk()->getContent();

        $this->assertStringContainsString('data-fund-total', $html, '⛔ অর্থের ড্যাশবোর্ডে তহবিলের ঘর নেই।');
        $this->assertLessThan(strpos($html, 'data-working-capital'), strpos($html, 'data-fund'), '⛔ তহবিলের ঘর মাথায় নয়।');
        $this->assertSame(1, substr_count($html, 'data-fund-total'), '⛔ তহবিলের ঘর দুইবার আঁকা হলো (মাথায় আর নিচে)।');
        foreach (['free', 'lowest', 'cover', 'od'] as $tile) {
            $this->assertStringContainsString('data-fund-tile="'.$tile.'"', $html, "⛔ '{$tile}' ঘরটা নেই।");
        }
    }

    /** @param  list<array{0: int, 1: ?string, 2: ?string}>  $lines */
    private function journal(string $date, array $lines): void
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date, 'narration' => 'তহবিলের ঘরের দাবি'],
            array_map(fn (array $l) => array_filter(['account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2]], fn ($v) => $v !== null), $lines),
        );
        app(VoucherService::class)->post($voucher);
        // ⓘ জের-ভান্ডার এক অনুরোধের জন্য — আসল পর্দায় পরের দেখা নতুন অনুরোধ
        app(LedgerBalances::class)->forget();
    }
}
