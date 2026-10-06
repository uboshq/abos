<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ ব্যাংক জামানত ভাঙিয়ে ঋণ শোধ করল — অর্থ-মডিউলের পরিকল্পনা ৪.৫ (৬ অক্টোবর ২০২৬, সমন্বয়কের সিদ্ধান্ত প্র৫, [[DepositService::encashForLoan()]])।
 *
 * ⛔ ঋণের বাকি ঠিক যতটা ঋণে গেল ততটা কমে (ঋণের খাতা নিজে দেখে), জরিমানা আর কর নিজের খাতে, বাকিটা মুনাফা; বাড়তি থাকলে
 * আমাদের হিসাবে। বিপজ্জনক ইনপুট: বাকির বেশি ঋণে, বাঁধা নয় এমন জমা, মালিকের জমা, বন্ধ ঋণ।
 */
final class TheBankTookTheDepositForTheLoanTest extends TestCase
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
        $this->putMoneyIn($this->cash(), '2000000', '2026-07-01');
    }

    public function test_the_loan_falls_by_what_was_applied_and_each_cut_has_its_own_line(): void
    {
        $loan = $this->facility('300000');
        // ⓘ একই দায়ের খাতে আরেকটা ঋণ — শোধটা কোন ঋণের, সেটা ভাউচারের নাম-বাঁধনই বলে ([[BankFacilityService::ledgerRowsOf()]])
        $other = $this->facility('10000');
        $fdr = $this->fdr('200000', $loan);

        $movement = app(DepositService::class)->encashForLoan($fdr, [
            'applied' => '205000', 'source_tax' => '300', 'penalty' => '500', 'moved_on' => now()->toDateString(),
        ]);

        $this->assertSame(0, bccomp('95000', $this->owed($loan), 4), '⛔ ঋণের বাকি ঋণে যাওয়া টাকার সমান কমেনি');
        $this->assertSame(0, bccomp('10000', $this->owed($other), 4), '⛔ একই খাতের অন্য ঋণের বাকি বদলাল');
        $this->assertSame(Deposit::CLOSED, $fdr->fresh()->status);
        $this->assertFalse($fdr->fresh()->isLocked());

        $lines = $this->linesOf($movement);
        $this->assertSame(['205000', '0'], $lines['2210'], 'ঋণের দায় কমল');
        $this->assertSame(['300', '0'], $lines[StandardChart::ADVANCE_INCOME_TAX]);
        $this->assertSame(['500', '0'], $lines[StandardChart::EARLY_BREAK_PENALTY], 'জরিমানা খরচে');
        $this->assertSame(['0', '200000'], $lines[StandardChart::DEPOSITS_AND_INVESTMENTS]);
        $this->assertSame(['0', '5800'], $lines[StandardChart::INTEREST_INCOME], 'মুনাফা = ২,০৫,০০০ + ৮০০ − ২,০০,০০০');
    }

    /** ⓘ জমা ঋণের চেয়ে বড় — ব্যাংক ঋণ পুরো শোধ করে বাড়তিটা আমাদের দেয় */
    public function test_a_surplus_comes_back_to_our_account(): void
    {
        $loan = $this->facility('50000');
        $fdr = $this->fdr('80000', $loan);

        $movement = app(DepositService::class)->encashForLoan($fdr, [
            'applied' => '50000', 'remainder' => '31000', 'money_account_id' => $this->cash()->id, 'moved_on' => now()->toDateString(),
        ]);

        $this->assertSame(0, bccomp('0', $this->owed($loan), 4), 'ঋণ শোধ');
        $lines = $this->linesOf($movement, withCash: true);
        $this->assertSame(['31000', '0'], $lines[$this->cash()->code], 'বাড়তি আমাদের হিসাবে');
        $this->assertSame(['0', '1000'], $lines[StandardChart::INTEREST_INCOME]);
    }

    public function test_more_than_owed_or_a_deposit_not_behind_a_running_loan_is_refused_and_books_nothing(): void
    {
        $loan = $this->facility('300000');
        $closedLoan = $this->facility('100000', DocumentStatus::CLOSED);
        $cases = [
            [$this->fdr('400000', $loan), '350000', 'বাকির বেশি ঋণে'],
            [$this->fdr('50000', null), '10000', 'বাঁধা নয় এমন জমা'],
            [$this->fdr('50000', $loan, Deposit::OWNER), '10000', 'মালিকের জমা'],
        ];
        // ⓘ বাঁধার পরে ঋণ বন্ধ — তখন আর ঋণের পথে নয়
        $late = $this->fdr('60000', $loan);
        $late->forceFill(['pledged_to_facility_id' => $closedLoan->id])->save();
        $cases[] = [$late, '10000', 'বন্ধ ঋণে বাঁধা'];

        foreach ($cases as [$deposit, $applied, $why]) {
            $before = LedgerEntry::query()->count();

            try {
                app(DepositService::class)->encashForLoan($deposit, ['applied' => $applied, 'moved_on' => now()->toDateString()]);
                $this->fail("⛔ {$why} মেনে নিল।");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('applied', $e->errors(), $why);
            }

            $this->assertSame($before, LedgerEntry::query()->count(), "⛔ {$why} — থামার পরেও খাতায় কিছু বসল");
            $this->assertSame(Deposit::ACTIVE, $deposit->fresh()->status, $why);
        }

        $this->assertSame(0, bccomp('300000', $this->owed($loan), 4), 'ঋণ যেমন ছিল');
    }

    public function test_the_page_offers_it_only_for_a_deposit_held_by_a_running_loan(): void
    {
        $loan = $this->facility('300000');
        $held = $this->fdr('100000', $loan);
        $free = $this->fdr('100000', null);

        $this->get(route('finance.deposit.show', ['issuer' => DepositKind::BANK, 'deposit' => $held]))->assertOk()->assertSee('data-lien-encash', false);
        $this->get(route('finance.deposit.show', ['issuer' => DepositKind::BANK, 'deposit' => $free]))->assertOk()->assertDontSee('data-lien-encash', false);

        $this->post(route('finance.deposit.lien', ['issuer' => DepositKind::BANK, 'deposit' => $held]), [
            'applied' => '100500', 'moved_on' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(Deposit::CLOSED, $held->fresh()->status);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('id')->firstOrFail();
    }

    private function facility(string $owed, string $status = DocumentStatus::CONFIRMED): BankFacility
    {
        return BankFacility::query()->create([
            'company_id' => CompanyContext::id(), 'document_no' => 'TL-'.random_int(1000, 9999), 'kind' => BankFacility::TERM,
            'bank' => 'Pubali Bank', 'sanctioned_on' => '2026-07-01', 'limit_amount' => '1000000', 'interest_rate' => '11',
            'opening_drawn' => $owed, 'liability_account_id' => StandardChart::find('2210')->id,
            'status' => $status, 'closed_on' => $status === DocumentStatus::CLOSED ? now()->toDateString() : null,
        ]);
    }

    private function fdr(string $amount, ?BankFacility $loan, string $heldBy = Deposit::BUSINESS): Deposit
    {
        $deposit = app(DepositService::class)->open([
            'kind_id' => DepositKind::query()->where('code', 'FDR')->value('id'),
            'institution' => 'Pubali Bank', 'held_by' => $heldBy, 'principal' => $amount, 'profit_rate' => '8',
            'return_word' => 'interest', 'opened_on' => '2026-07-02', 'matures_on' => now()->addYear()->toDateString(),
            'funded_from_account_id' => $this->cash()->id,
        ]);

        // ⓘ বাঁধা — মালিকের জমাও জোর করে, যাতে পাহারাটা নিজে দেখা যায় (সার্ভিস মালিকের জমায় বন্ধক বসায় না)
        $deposit->forceFill(['pledged_to_facility_id' => $loan?->id])->save();

        return $deposit->fresh();
    }

    private function owed(BankFacility $loan): string
    {
        return app(BankFacilityService::class)->owedOn($loan->fresh(), now()->toDateString());
    }

    /** @return array<string, array{0: string, 1: string}> খাতের কোড → [ডেবিট, ক্রেডিট] */
    private function linesOf(DepositMovement $movement, bool $withCash = false): array
    {
        $out = [];

        foreach (LedgerEntry::query()->where('ledger_entries.company_id', $this->company->id)->where('source_id', $movement->voucher_id)
            ->where('source_type', 'journal_voucher')->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->select('ledger_entries.debit', 'ledger_entries.credit', 'accounts.code')->get() as $row) {
            if (! $withCash && $row->code === $this->cash()->code) {
                continue;
            }

            $trim = fn ($v) => rtrim(rtrim(bcadd((string) $v, '0', 4), '0'), '.') ?: '0';
            $out[$row->code] = [$trim($row->debit), $trim($row->credit)];
        }

        return $out;
    }
}
