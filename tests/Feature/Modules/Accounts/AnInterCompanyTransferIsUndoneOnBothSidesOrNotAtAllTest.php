<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\InterCompanyTransfer;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsReversalService;
use App\Modules\Accounts\Services\InterCompanyService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * গ১২ — আন্তঃকোম্পানি লেনদেন উল্টায় দুই পাশ একসাথে, নইলে একটাও নয় (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে ADI তার পাশ (দেওয়ার ভাউচার) একা বাতিল করলে TCL-এ টাকা পাওয়া থেকেই যেত; দুই কোম্পানির চলতি হিসাব আর
 * শূন্যে মিলত না।
 */
final class AnInterCompanyTransferIsUndoneOnBothSidesOrNotAtAllTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->beta = Company::query()->where('code', 'FMART')->firstOrFail();

        CompanyContext::forCompany((int) $this->alpha->id,
            fn () => $this->putMoneyIn($this->money($this->alpha), '20000', now()->toDateString()));

        CompanyContext::set((int) $this->alpha->id);
        $this->actingAs($this->owner);
    }

    public function test_one_side_alone_is_refused_from_either_book_and_by_a_reversal_paper(): void
    {
        $transfer = $this->record();

        $this->assertRefused(fn () => app(VoucherService::class)->cancel(Voucher::query()->findOrFail($transfer->out_voucher_id), 'ভুল'),
            '⛔ দেওয়ার পাশ একা বাতিল হলো — অন্য কোম্পানিতে টাকা পাওয়া থেকে গেল।');

        $this->assertRefused(fn () => app(AccountsReversalService::class)->reverseVoucher(Voucher::query()->findOrFail($transfer->out_voucher_id), $this->owner, 'ভুল'),
            '⛔ উল্টো কাগজের পথে দেওয়ার পাশ একা উল্টানো গেল।');

        $this->assertRefused(fn () => CompanyContext::forCompany((int) $this->beta->id,
            fn () => app(VoucherService::class)->cancel(Voucher::query()->findOrFail($transfer->in_voucher_id), 'ভুল')),
            '⛔ পাওয়ার কোম্পানি থেকে তার পাশ একা বাতিল হলো।');

        $this->assertSame(DocumentStatus::CONFIRMED, $transfer->fresh()->status);
    }

    public function test_both_sides_are_undone_together_and_the_two_books_meet_at_zero(): void
    {
        $transfer = $this->record();

        app(InterCompanyService::class)->reverse($this->owner, $transfer, 'ভুল কোম্পানিতে গেছে');

        $this->assertSame(DocumentStatus::CANCELLED, $transfer->fresh()->status);
        $this->assertTrue($this->cancelled($this->alpha, (int) $transfer->out_voucher_id), '⛔ দেওয়ার পাশ উল্টায়নি।');
        $this->assertTrue($this->cancelled($this->beta, (int) $transfer->in_voucher_id), '⛔ পাওয়ার পাশ উল্টায়নি।');

        $this->assertSame(0, bccomp($this->control($this->alpha), '0', 4), '⛔ আমাদের চলতি হিসাব শূন্যে ফেরেনি।');
        $this->assertSame(0, bccomp($this->control($this->beta), '0', 4), '⛔ ওদের চলতি হিসাব শূন্যে ফেরেনি।');
        $this->assertSame((int) $this->alpha->id, (int) CompanyContext::id(), '⛔ উল্টানোর পরে কোম্পানির প্রসঙ্গ ফেরেনি।');
    }

    public function test_a_reason_is_needed_and_a_second_reversal_is_refused(): void
    {
        $transfer = $this->record();

        try {
            app(InterCompanyService::class)->reverse($this->owner, $transfer, '  ');
            $this->fail('⛔ কারণ ছাড়া উল্টানো গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cancel_reason', $e->errors());
        }

        app(InterCompanyService::class)->reverse($this->owner, $transfer, 'ভুল');

        try {
            app(InterCompanyService::class)->reverse($this->owner, $transfer->fresh(), 'আবার');
            $this->fail('⛔ একই লেনদেন দুবার উল্টানো গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_the_page_button_reverses_both_sides(): void
    {
        $transfer = $this->record();

        $this->from(route('accounts.inter_company.index'))
            ->post(route('accounts.inter_company.reverse', $transfer), ['cancel_reason' => 'পর্দা থেকে ভুল'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CANCELLED, $transfer->fresh()->status);
        $this->get(route('accounts.inter_company.index'))->assertOk()->assertSee(__('accounts::field.inter_company_reversed'));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function assertRefused(\Closure $act, string $why): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertStringContainsString('আন্তঃকোম্পানি', implode(' ', $e->errors()['status'] ?? []), 'আটকেছে, কিন্তু অন্য কারণে।');

            return;
        }

        $this->fail($why);
    }

    private function record(): InterCompanyTransfer
    {
        return app(InterCompanyService::class)->record($this->owner, [
            'counter_company_id' => $this->beta->id,
            'trx_date' => now()->toDateString(),
            'amount' => '5000.0000',
            'purpose' => 'ভাড়ার টাকা',
            'from_account_id' => $this->money($this->alpha)->id,
            'to_account_id' => $this->money($this->beta)->id,
        ]);
    }

    private function cancelled(Company $company, int $voucherId): bool
    {
        return CompanyContext::forCompany((int) $company->id, fn () => (bool) Voucher::query()->findOrFail($voucherId)->isCancelled());
    }

    private function control(Company $company): string
    {
        return CompanyContext::forCompany((int) $company->id, function () {
            $control = StandardChart::find(StandardChart::INTER_COMPANY);

            return (string) LedgerEntry::query()->where('account_id', $control?->id)
                ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
        });
    }

    private function money(Company $company): Account
    {
        return CompanyContext::forCompany((int) $company->id, fn () => Account::query()
            ->whereIn('money_kind', Account::MONEY_KINDS)
            ->where('is_group', false)
            ->orderBy('code')
            ->firstOrFail());
    }
}
