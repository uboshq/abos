<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Dashboard\FinanceBoard;
use App\Modules\Finance\Services\FinanceCharts;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ অর্থের ড্যাশবোর্ড, সারি 2–7 — মালিকের চূড়ান্ত নমুনা 4h63xhNiy4m9KaJkZZQaEL (10 নভেম্বর 2026 রাত: "100% স্যাম্পলের সাথে মিল রেখে")।
 * [[FinanceBoard]] · [[FinanceCharts]]
 *
 * দাবি: নগদ প্রবাহ বিবরণী খাতার রিপোর্টের ভাগেই (বিক্রি পরিচালনে, মালিকের টাকা অর্থায়নে, শুরু থেকে আজ মেলে); চেকের পাইপলাইন চেকের
 * তারিখের সপ্তাহে, ভাঙানো চেক বাদ; 13 সপ্তাহের পূর্বাভাস আজকের তহবিল থেকে; পাতায় কেবল নমুনার সাত সারি, ক্রমে; রাডারের মোট নম্বর
 * তিনটার কম মাপে নয়।
 */
final class TheFinanceBoardReadsTheBooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    /** ⭐ 1,20,000 নগদ বিক্রি পরিচালনে, 50,000 মালিকের টাকা অর্থায়নে; শুরুর তহবিল + তিন ভাগ = আজকের তহবিল */
    public function test_the_cash_flow_statement_puts_each_taka_in_its_ias7_section_and_bridges_the_month(): void
    {
        $before = app(FinanceBoard::class)->cashFlow();
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $this->journal('2026-11-05', [[$till->id, '120000', null], [StandardChart::find(StandardChart::SALES)->id, null, '120000']]);
        $this->journal('2026-11-06', [[$till->id, '50000', null], [StandardChart::find(StandardChart::OWNER_CAPITAL)->id, null, '50000']]);

        $after = app(FinanceBoard::class)->cashFlow();
        $net = fn (array $cf, string $key) => collect($cf['sections'])->firstWhere('key', $key)['net'];

        $this->assertSame(['operating', 'investing', 'financing'], array_column($after['sections'], 'key'), '⛔ তিন ভাগ IAS 7-এর ক্রমে নেই।');
        $this->assertSame('120000.0000', bcsub($net($after, 'operating'), $net($before, 'operating'), 4), '⛔ বিক্রির টাকা পরিচালনে ওঠেনি।');
        $this->assertSame('50000.0000', bcsub($net($after, 'financing'), $net($before, 'financing'), 4), '⛔ মালিকের টাকা অর্থায়নে ওঠেনি।');

        $sum = array_reduce($after['sections'], fn (string $c, array $s) => bcadd($c, $s['net'], 4), $after['opening']);
        $this->assertSame(0, bccomp($sum, $after['closing'], 4), '⛔ শুরুর তহবিল + তিন ভাগ ≠ আজকের তহবিল — সেতু মেলে না।');
        $this->assertStringNotContainsString(' — ', (string) ($after['sections'][0]['lines'][0]['name'] ?? ''), '⛔ খাতের নামের সাথে কোডও এল (নমুনায় কেবল নাম)।');
    }

    /** ⭐ পাওয়া চেক 9 দিন পরের তারিখে → সপ্তাহ 2-এ পাওয়ার দিকে; দেওয়া চেক আজ → সপ্তাহ 1-এ; ভাঙানো চেক কোথাও নয় */
    public function test_the_cheque_pipeline_sits_each_cheque_in_its_week_and_leaves_cleared_ones_out(): void
    {
        $before = app(FinanceBoard::class)->cheques();
        $bank = Account::query()->create([
            'company_id' => CompanyContext::id(), 'code' => '1102-FBRD', 'name_en' => 'Board bank', 'name_bn' => 'বোর্ডের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK,
        ]);

        $this->cheque(Cheque::RECEIVED, '30000', '2026-11-29', $bank);
        $this->cheque(Cheque::ISSUED, '12000', '2026-11-20', $bank);
        $cleared = $this->cheque(Cheque::RECEIVED, '99000', '2026-11-22', $bank);
        $cleared->forceFill(['status' => Cheque::CLEARED])->save();

        $after = app(FinanceBoard::class)->cheques();

        $this->assertSame('30000.0000', bcsub($after['weeks'][1]['in'], $before['weeks'][1]['in'], 4), '⛔ 9 দিন পরের পাওয়া চেক সপ্তাহ 2-এ নেই।');
        $this->assertSame('12000.0000', bcsub($after['weeks'][0]['out'], $before['weeks'][0]['out'], 4), '⛔ আজকের দেওয়া চেক সপ্তাহ 1-এ নেই।');
        $this->assertSame('30000.0000', bcsub($after['in'], $before['in'], 4), '⛔ ভাঙানো চেকও পাইপলাইনে গোনা হলো।');
    }

    /** ⭐ পূর্বাভাস আজকের তহবিল থেকে শুরু, 13 সপ্তাহ, সপ্তাহ-শেষের তহবিল = আগের + নিট; নিরাপদ সীমা = দিনে গড় বেরোনো × 14 */
    public function test_the_thirteen_week_forecast_starts_from_todays_fund_and_carries_each_week(): void
    {
        // ⓘ টাকা বেরোনো চাই — নাহলে দৈনিক বেরোনো শূন্য, আর "দুই সপ্তাহ" আর "এক সপ্তাহ"-র সীমা দুটোই শূন্য (FB3 বেঁচে ছিল, Mac run of 050355e0c)
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $this->journal(Carbon::today()->subDays(3)->toDateString(), [[$till->id, '50000', null], [StandardChart::find(StandardChart::OWNER_CAPITAL)->id, null, '50000']]);
        $this->journal(Carbon::today()->subDays(2)->toDateString(), [[StandardChart::find(StandardChart::RENT)->id, '30000', null], [$till->id, null, '30000']]);

        $f = app(FinanceBoard::class)->forecast();

        $this->assertCount(14, $f['points'], '⛔ আজ + 13 সপ্তাহ নয়।');
        $this->assertCount(13, $f['weeks']);
        foreach ($f['weeks'] as $i => $w) {
            $this->assertSame(0, bccomp(bcadd($f['points'][$i], $w['net'], 4), $w['closing'], 4), "⛔ সপ্তাহ ".($i + 1)."-এর শেষের তহবিল = আগের + নিট নয়।");
            $this->assertSame(Carbon::today()->addDays($i * 7)->toDateString(), $w['from'], '⛔ সপ্তাহ আজ থেকে 7 দিনের টুকরো নয়।');
        }
        $fund = app(\App\Modules\Finance\Services\FundPosition::class)->on();
        $this->assertSame(1, bccomp($fund['daily_out'], '0', 4), 'প্রস্তুতিটাই ভুল — দৈনিক বেরোনো শূন্য।');
        $this->assertSame(0, bccomp(bcmul($fund['daily_out'], '14', 4), $f['floor'], 4), '⛔ নিরাপদ সীমা দুই সপ্তাহের গড় বেরোনো নয়।');
    }

    /** ⭐ পাতায় কেবল নমুনার সারিগুলো, ক্রমে — পুরনো কার্ড, চার্ট, তালিকা নেই */
    public function test_the_page_is_the_samples_seven_rows_in_order_and_nothing_else(): void
    {
        $html = (string) $this->get(route('module.dashboard', ['module' => 'finance']))->assertOk()->getContent();
        $marks = ['data-fin-forecast', 'data-fin-cash-flow', 'data-fin-board-position', 'data-fin-margins', 'data-fin-calendar',
            'data-fin-wc', 'data-fin-loans', 'data-fin-banks', 'data-fin-cheques', 'data-fin-fdr', 'data-fin-health', 'data-fin-fund-trend'];
        $at = [];

        foreach ($marks as $m) {
            $at[$m] = strpos($html, $m);
            $this->assertNotFalse($at[$m], '⛔ পাতায় '.$m.' নেই।');
        }

        $sorted = $at;
        asort($sorted);
        $this->assertSame($marks, array_keys($sorted), '⛔ সারিগুলো নমুনার ক্রমে নেই।');

        foreach (['data-stat-grid', 'data-dashboard-pair', 'data-listing', 'data-finance-charts'] as $old) {
            $this->assertStringNotContainsString($old, $html, '⛔ পুরনো অংশ ('.$old.') এখনো পাতায় — নমুনায় নেই।');
        }
    }

    /** ⭐ রাডারের মোট নম্বর তিনটার কম মাপে নয় — একটা মাপ থেকে "100/100" ভুল আশ্বাস দিত */
    public function test_the_health_score_needs_three_measures(): void
    {
        $h = app(FinanceCharts::class)->health();
        $have = count(array_filter($h['now'], fn ($v) => $v !== null));

        $this->assertCount(6, $h['now']);
        $this->assertSame($have >= 3, $h['score'] !== null, '⛔ মোট নম্বর '.$have.'টা মাপে — তিনটার নিয়ম ভাঙল।');

        foreach ($h['now'] as $v) {
            $this->assertTrue($v === null || ($v >= 0 && $v <= 100), '⛔ মাপ 0–100-এর বাইরে।');
        }
    }

    private function cheque(string $direction, string $amount, string $date, Account $bank): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => $direction, 'cheque_date' => $date, 'received_on' => '2026-11-20',
            'cheque_no' => (string) random_int(100000, 999999), 'amount' => $amount, 'party_type' => 'supplier',
            'party_id' => Supplier::query()->firstOrFail()->id, 'bank_account_id' => $bank->id,
        ]);
    }

    /** @param  list<array{0: int, 1: ?string, 2: ?string}>  $lines */
    private function journal(string $date, array $lines): void
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date, 'narration' => 'অর্থের বোর্ডের দাবি'],
            array_map(fn (array $l) => array_filter(['account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2]], fn ($v) => $v !== null), $lines),
        );
        app(VoucherService::class)->post($voucher);
        app(\App\Core\Services\LedgerBalances::class)->forget();
    }
}
