<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ ব্যাংক যা কেটে রাখে, নিজের নিজের খাতে — অর্থ-মডিউলের পরিকল্পনা ৪ (৬ অক্টোবর ২০২৬, সমন্বয়কের সিদ্ধান্ত প্র২)।
 *
 * ⛔ উৎসে কর → অগ্রিম আয়কর (১১৩৫, সম্পদ); আবগারি শুল্ক → ব্যাংক চার্জ (৫২১০); আগে ভাঙানোর জরিমানা → নিজের খরচ (৫৩২৫);
 * সুদ আয় মোট অর্জিত (হাতে পাওয়া + কাটা − আসল); আগে সব ঘাটতি একসাথে ৫৩১০-এ পড়ত। বিপজ্জনক ইনপুট: কাটার পরেও ঘাটতি, মুনাফা
 * তোলায় জরিমানা, মালিকের জমায় কর, ঋণাত্মক কাটা।
 */
final class TheBankCutWentToItsOwnLineTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
        $this->putMoneyIn($this->cash(), '1000000', '2026-07-01');
    }

    public function test_encashing_puts_tax_duty_and_penalty_each_on_its_own_line(): void
    {
        $fdr = $this->open('FDR', '100000');

        $movement = app(DepositService::class)->close($fdr, $this->money('104000', source_tax: '500', excise_duty: '200', penalty: '300'));

        $this->assertLines([
            StandardChart::BANK_CHARGES => ['200', '0'],
            StandardChart::ADVANCE_INCOME_TAX => ['500', '0'],
            StandardChart::DEPOSITS_AND_INVESTMENTS => ['0', '100000'],
            StandardChart::INTEREST_INCOME => ['0', '5000'],
            StandardChart::EARLY_BREAK_PENALTY => ['300', '0'],
        ], $this->linesOf($movement, except: $this->cash()->code), 'কর, শুল্ক, জরিমানা নিজের খাতে; আয় = ১,০৪,০০০ + ১,০০০ − ১,০০,০০০');
        $this->assertSame(0, $this->sumOn($movement, StandardChart::INTEREST_EXPENSE), '⛔ কাটা আবার সুদ খরচে');
    }

    /** ⓘ কাটার পরেও আসল পুরো ফেরেনি — বাকি ঘাটতি আগের মতো সুদ খরচে */
    public function test_a_shortfall_beyond_the_cuts_is_still_a_cost(): void
    {
        $fdr = $this->open('FDR', '100000');

        $movement = app(DepositService::class)->close($fdr, $this->money('99000', penalty: '500'));

        $lines = $this->linesOf($movement, except: $this->cash()->code);
        $this->assertSame(['500', '0'], $lines[StandardChart::EARLY_BREAK_PENALTY]);
        $this->assertSame(['500', '0'], $lines[StandardChart::INTEREST_EXPENSE], 'বাকি ঘাটতি = ১,০০,০০০ − ৯৯,০০০ − ৫০০');
        $this->assertArrayNotHasKey(StandardChart::INTEREST_INCOME, $lines);
    }

    public function test_a_profit_payout_books_the_gross_income_and_the_tax_as_an_asset(): void
    {
        $mis = $this->open('MIS', '100000');

        $movement = app(DepositService::class)->payout($mis, $this->money('900', source_tax: '100', excise_duty: '0'));

        $this->assertLines([
            StandardChart::ADVANCE_INCOME_TAX => ['100', '0'],
            StandardChart::INTEREST_INCOME => ['0', '1000'],
        ], $this->linesOf($movement, except: $this->cash()->code));

        try {
            app(DepositService::class)->payout($mis, $this->money('900', penalty: '50'));
            $this->fail('⛔ মুনাফা তোলায় জরিমানা বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('penalty', $e->errors());
        }
    }

    public function test_an_owner_deposit_or_a_negative_cut_is_refused_and_books_nothing(): void
    {
        $own = $this->open('FDR', '50000', Deposit::OWNER);
        $before = LedgerEntry::query()->count();

        foreach ([[$own, $this->money('52000', source_tax: '200'), 'source_tax', 'মালিকের জমায় কর'],
            [$this->open('FDR', '30000'), $this->money('31000', excise_duty: '-5'), 'excise_duty', 'ঋণাত্মক শুল্ক']] as [$deposit, $data, $field, $why]) {
            $before = LedgerEntry::query()->count();

            try {
                app(DepositService::class)->close($deposit, $data);
                $this->fail("⛔ {$why} মেনে নিল।");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors(), $why);
            }

            $this->assertSame($before, LedgerEntry::query()->count(), "⛔ {$why} — থামার পরেও খাতায় কিছু বসল");
            $this->assertSame(Deposit::ACTIVE, $deposit->fresh()->status);
        }
    }

    public function test_the_forms_ask_for_the_cuts_only_on_a_business_deposit(): void
    {
        $fdr = $this->open('FDR', '20000');
        $own = $this->open('FDR', '20000', Deposit::OWNER);

        $this->get(route('finance.deposit.show', ['issuer' => DepositKind::BANK, 'deposit' => $fdr]))->assertOk()
            ->assertSee('name="source_tax"', false)->assertSee('name="penalty"', false);
        $this->get(route('finance.deposit.show', ['issuer' => DepositKind::BANK, 'deposit' => $own]))->assertOk()
            ->assertDontSee('name="source_tax"', false);

        $this->post(route('finance.deposit.close', ['issuer' => DepositKind::BANK, 'deposit' => $fdr]), [
            'amount' => '20500', 'source_tax' => '50', 'moved_on' => now()->toDateString(), 'money_account_id' => $this->cash()->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(Deposit::CLOSED, $fdr->fresh()->status);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('id')->firstOrFail();
    }

    private function open(string $kind, string $amount, string $heldBy = Deposit::BUSINESS): Deposit
    {
        return app(DepositService::class)->open([
            'kind_id' => DepositKind::query()->where('code', $kind)->value('id'),
            'institution' => 'সোনালী ব্যাংক', 'held_by' => $heldBy, 'principal' => $amount, 'profit_rate' => '8',
            'return_word' => 'interest', 'opened_on' => '2026-07-02',
            'matures_on' => now()->addDays(65)->toDateString(), 'funded_from_account_id' => $this->cash()->id,
            'payout_account_id' => $this->cash()->id,
        ]);
    }

    /** @return array<string, string> */
    private function money(string $received, ?string $source_tax = null, ?string $excise_duty = null, ?string $penalty = null): array
    {
        return array_filter([
            'amount' => $received, 'moved_on' => now()->toDateString(), 'money_account_id' => $this->cash()->id,
            'source_tax' => $source_tax, 'excise_duty' => $excise_duty, 'penalty' => $penalty,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, array{0: string, 1: string}> খাতের কোড → [ডেবিট, ক্রেডিট], চলাচলের ভাউচারে */
    private function linesOf(DepositMovement $movement, string $except): array
    {
        $out = [];

        foreach (LedgerEntry::query()->where('ledger_entries.company_id', $this->company->id)->where('source_id', $movement->voucher_id)
            ->whereIn('source_type', ['receipt_voucher', 'payment_voucher'])
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->select('ledger_entries.debit', 'ledger_entries.credit', 'accounts.code')->get() as $row) {
            if ($row->code === $except) {
                continue;
            }

            $out[$row->code] = [rtrim(rtrim(bcadd((string) $row->debit, '0', 4), '0'), '.') ?: '0', rtrim(rtrim(bcadd((string) $row->credit, '0', 4), '0'), '.') ?: '0'];
        }

        ksort($out);

        return $out;
    }

    /** @param  array<string, array{0: string, 1: string}>  $expected */
    private function assertLines(array $expected, array $actual, string $message = ''): void
    {
        ksort($expected);
        $this->assertSame($expected, $actual, $message);
    }

    private function sumOn(DepositMovement $movement, string $code): int
    {
        return LedgerEntry::query()->where('source_id', $movement->voucher_id)->whereIn('source_type', ['receipt_voucher', 'payment_voucher'])
            ->whereIn('account_id', Account::query()->where('code', $code)->select('id'))->count();
    }
}
