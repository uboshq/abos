<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositAccrual;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Services\DepositAccrualService;
use App\Modules\Finance\Services\DepositKindInstaller;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ আমানতের মাসিক অর্জিত মুনাফা (অর্থ-মডিউলের পরিকল্পনা ৪.২, ৬ অক্টোবর ২০২৬, [[DepositAccrualService]]) — ব্যাংক ঋণের সুদ
 * জমার ([[InterestIsAccruedEveryMonthTest]]) জোড়া।
 *
 * ⛔ মাসের শেষ দিনে Dr ১১৬৫ / Cr ৪৩১০, অঙ্ক "জমা সুদ" রিপোর্টের হুবহু; পরের মাসে প্রথম দিনে উল্টায়; এক মাস একবারই; শেষ না হওয়া
 * মাস নয়; মালিকের নামের জমা নয়; সই "না" হলে মাস খোলে, শেষ সই দুবার এলেও একবার।
 */
final class DepositProfitIsAccruedEveryMonthTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Deposit $fdr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();

        // ⓘ ৭৩,০০০ টাকা, ১০%, ১ জুলাই — আগস্টের শেষে ৬১ দিন (১,২২০), সেপ্টেম্বরের শেষে ৯১ দিন (১,৮২০)
        $this->fdr = $this->deposit('FDR-ACC', '73000', '10', Deposit::BUSINESS);
        // ⛔ মালিকের নামে — মুনাফা ব্যবসার আয় নয়
        $this->deposit('FDR-OWN', '10000', '36.5', Deposit::OWNER);
    }

    public function test_a_finished_month_books_what_the_report_says_once(): void
    {
        $done = app(DepositAccrualService::class)->run(Carbon::parse('2026-08-01'));

        $this->assertSame(['accrued' => 1, 'reversed' => 0, 'held' => 0], $done);
        $accrual = DepositAccrual::query()->sole();
        $this->assertSame((int) $this->fdr->id, (int) $accrual->deposit_id, '⛔ মালিকের জমায় মুনাফা বসল');
        $this->assertSame('1220.00', bcadd((string) $accrual->amount, '0', 2));
        $this->assertSame('2026-08-31', $accrual->voucher->trx_date->toDateString());
        $this->assertSame(DocumentStatus::CONFIRMED, $accrual->voucher->status);
        $this->assertTrue($accrual->voucher->is_adjusting, '⛔ জমার মুনাফার বকেয়ায় সমন্বয় দাগ নেই।'); // ⭐ মাসশেষের সমন্বয় দাগ (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)
        $this->assertMoney('1220', $this->balance(StandardChart::ACCRUED_INTEREST), 'অর্জিত মুনাফা (১১৬৫)');
        $this->assertMoney('-1220', $this->balance(StandardChart::INTEREST_INCOME), 'সুদ আয় (৪৩১০, ক্রেডিট)');

        // ⓘ আবার — কিছুই নয়
        $this->assertSame(['accrued' => 0, 'reversed' => 0, 'held' => 0], app(DepositAccrualService::class)->run(Carbon::parse('2026-08-01')));
        $this->assertSame(1, DepositAccrual::query()->count());
    }

    public function test_the_next_month_reverses_the_last_one_on_its_first_day(): void
    {
        app(DepositAccrualService::class)->run(Carbon::parse('2026-08-01'));
        $done = app(DepositAccrualService::class)->run(Carbon::parse('2026-09-01'));

        $this->assertSame(1, $done['reversed']);
        $august = DepositAccrual::query()->where('for_month', '2026-08-01')->sole();
        $this->assertSame('2026-09-01', $august->reversalVoucher->trx_date->toDateString(), 'উল্টো দাখিলা পরের মাসের প্রথম দিনে');
        $this->assertTrue($august->reversalVoucher->is_adjusting, '⛔ মুনাফার বকেয়ার উল্টোয় সমন্বয় দাগ নেই।'); // ⭐ মাসশেষের সমন্বয় দাগ (ভাউচারের পরিকল্পনা ৩ঘ, ৭ অক্টোবর ২০২৬)

        // ⓘ ১১৬৫-এ কেবল সেপ্টেম্বরের শেষের মোট; আয় দুই মাসে মোট ১,৮২০ — একবারই
        $this->assertMoney('1820', $this->balance(StandardChart::ACCRUED_INTEREST), 'অর্জিত মুনাফা সেপ্টেম্বরের শেষে');
        $this->assertMoney('-1820', $this->balance(StandardChart::INTEREST_INCOME), '⛔ আয় দুবার গোনা হল');
    }

    public function test_a_month_not_over_is_refused(): void
    {
        try {
            app(DepositAccrualService::class)->run(now());
            $this->fail('⛔ শেষ না হওয়া মাস বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('month', $e->errors());
        }

        $this->assertSame(0, DepositAccrual::query()->count());
    }

    public function test_a_refused_signature_frees_the_month_and_the_last_signature_posts_once(): void
    {
        $draft = $this->draftAccrual('2026-08-01', '50');
        $this->assertTrue(DepositAccrualService::isAccrual($draft), 'সইয়ের শ্রোতা জমা-মুনাফার ভাউচার চেনে না');

        app(DepositAccrualService::class)->dropRefused($draft, 'no');
        $this->assertSame(DocumentStatus::CANCELLED, $draft->fresh()->status);
        $this->assertSame(0, DepositAccrual::query()->count(), 'ফেরানো মাসের সারি রয়ে গেল — মাসটা আর চালানো যেত না');
        $this->assertSame(1, app(DepositAccrualService::class)->run(Carbon::parse('2026-08-01'))['accrued']);

        $held = $this->draftAccrual('2026-09-01', '25');
        app(DepositAccrualService::class)->finishSigned($held);
        app(DepositAccrualService::class)->finishSigned($held->fresh());
        $this->assertSame(DocumentStatus::CONFIRMED, $held->fresh()->status);
        $this->assertMoney('1245', $this->balance(StandardChart::ACCRUED_INTEREST), '⛔ শেষ সই দুবার এলে দুবার বসল');

        // ⓘ জমার সাধারণ ভাউচার জমা-মুনাফা নয় — শ্রোতা সেটা জমার নিজের পথে পাঠায়
        $plain = $this->journal('7');
        $this->assertFalse(DepositAccrualService::isAccrual($plain));
    }

    public function test_the_page_button_runs_it(): void
    {
        $this->get(route('finance.deposit.report.show', ['slug' => 'accrued']))->assertOk()->assertSee('data-deposit-accrual', false);
        $this->post(route('finance.deposit.accrue'), ['month' => '2026-08'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, DepositAccrual::query()->count());
        $this->post(route('finance.deposit.accrue'), ['month' => now()->format('Y-m')])->assertSessionHasErrors('month');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function deposit(string $no, string $principal, string $rate, string $heldBy): Deposit
    {
        $deposit = Deposit::query()->create([
            'company_id' => $this->company->id, 'branch_id' => Branch::query()->value('id'),
            'document_no' => $no, 'kind_id' => DepositKind::query()->where('code', 'FDR')->value('id'),
            'institution' => 'সোনালী ব্যাংক', 'held_by' => $heldBy, 'principal' => $principal,
            'profit_rate' => $rate, 'tax_rate' => '10', 'return_word' => 'interest',
            'opened_on' => '2026-07-01', 'matures_on' => '2027-07-01',
            'account_id' => StandardChart::find(StandardChart::DEPOSITS_AND_INVESTMENTS)->id, 'status' => Deposit::ACTIVE,
        ]);

        DepositMovement::query()->create([
            'company_id' => $this->company->id, 'deposit_id' => $deposit->id, 'kind' => DepositMovement::OPENED,
            'amount' => $principal, 'moved_on' => '2026-07-01',
        ]);

        return $deposit;
    }

    private function journal(string $amount, string $on = '2026-08-31'): Voucher
    {
        return app(VoucherService::class)->create(['type' => Voucher::JOURNAL, 'trx_date' => $on, 'narration' => 'held',
            'against_type' => Deposit::drillSourceType(), 'against_id' => $this->fdr->id], [
                ['account_id' => StandardChart::find(StandardChart::ACCRUED_INTEREST)->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => StandardChart::find(StandardChart::INTEREST_INCOME)->id, 'debit' => '0', 'credit' => $amount],
            ]);
    }

    /** সইয়ের অপেক্ষার একটা মাসের জমা — খসড়া ভাউচার আর তার সারি */
    private function draftAccrual(string $month, string $amount): Voucher
    {
        $voucher = $this->journal($amount, Carbon::parse($month)->endOfMonth()->toDateString());
        $this->assertTrue($voucher->isDraft());

        DepositAccrual::query()->create([
            'company_id' => $this->company->id, 'deposit_id' => $this->fdr->id, 'for_month' => $month,
            'base' => '73000', 'rate' => '10', 'amount' => $amount, 'voucher_id' => $voucher->id,
        ]);

        return $voucher;
    }

    /** খাতের জের — ডেবিট − ক্রেডিট */
    private function balance(string $code): string
    {
        return (string) LedgerEntry::query()->where('company_id', $this->company->id)->where('account_id', StandardChart::find($code)->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n');
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 2), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }
}
