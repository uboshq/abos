<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\BankStatementService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ম৭ — ব্যাংকের স্বয়ং-মেলানো (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ বইয়ের এক সারি দুই ব্যাংক সারির সাথে মিলত — ব্যাংক বলত দুইবার ৫০০ গেছে, বই একবার, অথচ দুইটাই "মিলে গেল"। আর বাতিল
 * বা খসড়া ভাউচারের সারিও মিলত — যে টাকা খাতায় নেই তার সাথে।
 */
final class OneBookLineMatchedTwoBankLinesTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        $head = Account::query()->where('code', StandardChart::BANK)->firstOrFail();

        $this->bank = Account::query()->create([
            'parent_id' => $head->id, 'code' => '1102-07', 'name_en' => 'M7 Bank', 'name_bn' => 'ম৭ ব্যাংক',
            'type' => $head->type, 'nature' => $head->nature, 'is_group' => false, 'money_kind' => Account::BANK, 'is_active' => true,
        ]);
    }

    public function test_one_book_line_answers_for_one_bank_line_only(): void
    {
        $this->bankLine('2026-09-07', 'ATM 1');
        $this->bankLine('2026-09-08', 'ATM 2');
        $this->bookLine('2026-09-07', post: true);

        $this->assertSame(1, $this->match(), '⛔ বইয়ের এক সারি দুই ব্যাংক সারির সাথে মিলল।');
        $this->assertSame(0, $this->match(), '⛔ আবার চালাতে একই বইয়ের সারি দ্বিতীয় ব্যাংক সারিতে বসল।');

        $this->assertCount(1, app(BankStatementService::class)->unmatchedFor($this->bank, '2026-09-30'),
            'দ্বিতীয় ব্যাংক সারিটা না-মেলা তালিকায় থাকার কথা — বইয়ে ঐ টাকা নেই।');
    }

    public function test_a_cancelled_voucher_is_not_matched(): void
    {
        $this->bankLine('2026-09-07', 'ATM');
        $voucher = $this->bookLine('2026-09-07', post: true);
        app(VoucherService::class)->cancel($voucher->fresh(), 'ভুল');

        $this->assertSame(0, $this->match(), '⛔ বাতিল ভাউচারের সারি ব্যাংকের সাথে মিলল।');
    }

    public function test_a_draft_voucher_is_not_matched(): void
    {
        $this->bankLine('2026-09-07', 'ATM');
        $this->bookLine('2026-09-07', post: false);

        $this->assertSame(0, $this->match(), '⛔ খাতায় না-ওঠা খসড়ার সারি ব্যাংকের সাথে মিলল।');
    }

    /** ⓘ আজকের পথ অটুট — পাকা ভাউচারের একমাত্র সারি মেলে */
    public function test_a_posted_voucher_still_matches(): void
    {
        $this->bankLine('2026-09-07', 'ATM');
        $line = $this->bookLine('2026-09-07', post: true)->lines()->where('account_id', $this->bank->id)->firstOrFail();

        $this->assertSame(1, $this->match());
        $this->assertSame((int) $line->id, (int) BankStatementLine::query()->value('matched_line_id'));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function match(): int
    {
        return app(BankStatementService::class)->matchAgainstBooks($this->bank, '2026-09-30');
    }

    private function bankLine(string $date, string $what): void
    {
        app(BankStatementService::class)->add($this->bank, $date, $what, null, '500.00', '0');
    }

    private function bookLine(string $date, bool $post): Voucher
    {
        $charge = Account::query()->where('code', StandardChart::BANK_CHARGES)->postable()->firstOrFail();
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date, 'narration' => 'তোলা', 'instrument_no' => 'WDR-'.random_int(1000, 9999)],
            [
                ['account_id' => $charge->id, 'debit' => '500', 'credit' => '0'],
                ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '500'],
            ],
        );

        return $post ? app(VoucherService::class)->post($voucher) : $voucher;
    }
}
