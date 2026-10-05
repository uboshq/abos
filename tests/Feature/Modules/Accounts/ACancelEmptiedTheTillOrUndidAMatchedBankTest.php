<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ম৩ — বাতিলে নগদ ঋণাত্মক নয়, আর ব্যাংকে মেলানো ভাউচার বাতিল নয় (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ রসিদের টাকা খরচ হয়ে যাওয়ার পরে রসিদ বাতিল করলে কাউন্টার ঋণাত্মক হত; গ্রহণ হওয়া স্থানান্তর বাতিলেও তাই। আর ব্যাংকে
 * মেলানো ভাউচার ভাউচারের পথে বাতিল হত — আগের মাসের মেলানো চুপচাপ ভুল।
 */
final class ACancelEmptiedTheTillOrUndidAMatchedBankTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CashTill $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->till = app(CashTillService::class)->create(['name_en' => 'M3 Counter', 'holder_id' => $this->owner->id]);
    }

    public function test_a_cancel_that_would_take_the_till_below_zero_is_refused(): void
    {
        $in = $this->moneyIn('1000');
        $this->spend($this->till, '800');

        $this->assertRefused(fn () => app(VoucherService::class)->cancel($in->fresh(), 'ভুল'), 'lines',
            '⛔ টাকা খরচের পরে রসিদ বাতিল হলো — কাউন্টার ঋণাত্মক।');

        $this->assertSame(DocumentStatus::CONFIRMED, $in->fresh()->status);
        $this->assertSame(0, bccomp($this->till->fresh()->balance(), '200', 4));
    }

    /** ⓘ টাকা থাকলে আজকের মতোই বাতিল হয় */
    public function test_a_cancel_with_the_money_still_there_goes_through_as_today(): void
    {
        $in = $this->moneyIn('1000');
        $this->moneyIn('1000');
        $this->spend($this->till, '400');

        app(VoucherService::class)->cancel($in->fresh(), 'ভুল');

        $this->assertSame(DocumentStatus::CANCELLED, $in->fresh()->status);
        $this->assertSame(0, bccomp($this->till->fresh()->balance(), '600', 4), 'বাতিলের পরে কাউন্টারে ২০০০ − ৪০০ − ১০০০ = ৬০০ থাকার কথা।');
    }

    public function test_a_voucher_matched_to_a_bank_line_is_not_cancelled(): void
    {
        $voucher = $this->moneyIn('500');
        $line = $voucher->lines()->orderBy('id')->firstOrFail();

        BankStatementLine::query()->forceCreate([
            'company_id' => CompanyContext::id(),
            'bank_account_id' => $line->account_id,
            'trx_date' => now()->toDateString(),
            'description' => 'M3',
            'debit' => '0',
            'credit' => '500',
            'fingerprint' => 'm3-'.$voucher->id,
            'matched_line_id' => $line->id,
        ]);

        $this->assertRefused(fn () => app(VoucherService::class)->cancel($voucher->fresh(), 'ভুল'), 'cancel_reason',
            '⛔ ব্যাংকে মেলানো ভাউচার বাতিল হলো।');
        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->fresh()->status);
    }

    public function test_a_voucher_ticked_in_a_reconciliation_is_not_cancelled(): void
    {
        $voucher = $this->moneyIn('500');
        $line = $voucher->lines()->orderBy('id')->firstOrFail();

        $recon = BankReconciliation::query()->forceCreate([
            'company_id' => CompanyContext::id(),
            'bank_account_id' => $line->account_id,
            'statement_date' => now()->toDateString(),
            'statement_balance' => '500',
            'status' => 'confirmed',
        ]);
        $line->forceFill(['reconciliation_id' => $recon->id])->save();

        $this->assertRefused(fn () => app(VoucherService::class)->cancel($voucher->fresh(), 'ভুল'), 'cancel_reason',
            '⛔ মেলানোয় টিক দেওয়া ভাউচার বাতিল হলো।');
    }

    public function test_a_received_transfer_is_not_cancelled_once_the_money_is_spent(): void
    {
        $safe = app(CashTillService::class)->create(['name_en' => 'M3 Safe', 'holder_id' => $this->owner->id]);
        $this->moneyIn('1000');

        $transfer = app(MoneyTransferService::class)->confirm(app(MoneyTransferService::class)->initiate([
            'from_till_id' => $this->till->id, 'to_till_id' => $safe->id, 'amount' => '600', 'trx_date' => now()->toDateString(),
        ]));
        $this->spend($safe, '500');

        $this->assertRefused(fn () => app(MoneyTransferService::class)->cancel($transfer->fresh(), 'ভুল'), 'cancel_reason',
            '⛔ গ্রহণের টাকা খরচের পরে স্থানান্তর বাতিল হলো — সিন্দুক ঋণাত্মক।');

        $this->assertTrue($transfer->fresh()->isConfirmed());
        $this->assertSame(0, bccomp($safe->fresh()->balance(), '100', 4));
    }

    /** ⓘ টাকা থাকলে স্থানান্তর আজকের মতোই বাতিল হয় */
    public function test_a_received_transfer_with_the_money_there_is_cancelled_as_today(): void
    {
        $safe = app(CashTillService::class)->create(['name_en' => 'M3 Safe 2', 'holder_id' => $this->owner->id]);
        $this->moneyIn('1000');

        $transfer = app(MoneyTransferService::class)->confirm(app(MoneyTransferService::class)->initiate([
            'from_till_id' => $this->till->id, 'to_till_id' => $safe->id, 'amount' => '600', 'trx_date' => now()->toDateString(),
        ]));

        app(MoneyTransferService::class)->cancel($transfer->fresh(), 'ভুল');

        $this->assertSame(DocumentStatus::CANCELLED, $transfer->fresh()->status);
        $this->assertSame(0, bccomp($this->till->fresh()->balance(), '1000', 4));
        $this->assertSame(0, bccomp($safe->fresh()->balance(), '0', 4));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** কাউন্টারে টাকা ঢোকা — পোস্ট হওয়া জাবেদা, মালিকের মূলধন থেকে */
    private function moneyIn(string $amount): Voucher
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'M3 in'],
            [
                ['account_id' => $this->till->account_id, 'debit' => $amount],
                ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => $amount],
            ],
        );

        return app(VoucherService::class)->post($voucher);
    }

    /** টাকা খরচ — সরাসরি খাতায়, কাউন্টার থেকে */
    private function spend(CashTill $till, string $amount): void
    {
        app(PostingEngine::class)->post(
            sourceType: 'test:spend',
            sourceId: random_int(1, PHP_INT_MAX),
            trxDate: now()->toDateString(),
            lines: [
                ['account_id' => StandardChart::find(StandardChart::ENTERTAINMENT)->id, 'debit' => $amount, 'narration' => 'খরচ'],
                ['account_id' => $till->account_id, 'credit' => $amount, 'narration' => 'খরচ'],
            ],
        );
    }

    private function assertRefused(\Closure $act, string $field, string $why): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), 'আটকেছে, কিন্তু অন্য কারণে: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->fail($why);
    }
}
