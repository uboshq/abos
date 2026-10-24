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
 * ⭐ ব্যাংক স্টেটমেন্টের সারি নিজের ভাউচার খোঁজে লেনদেন নম্বরে (TrxID) আগে, অঙ্কে পরে (cb-ma-4, ২৫ অক্টোবর ২০২৬; নকশার "যা পরের ধাপে")।
 *
 * ⓘ আগে কেবল অঙ্ক-দিক-সাত দিন: একই দিনে একই অঙ্কের দুই পরিশোধ হলে কোনোটাই মিলত না, মানুষকে হাতে জোড়া বসাতে হত — অথচ
 * ব্যাংক সারির রেফারেন্সে ঠিক সেই নম্বরটাই থাকে যা ভাউচারে লেখা হয়েছিল।
 *
 * দাবি:
 *   ১. একই দিনে একই অঙ্কের দুই ভাউচার, ব্যাংকের দুই সারি নিজের নম্বরে — প্রত্যেকটা নিজের ভাউচারে বসে
 *   ২. নম্বর মিলল কিন্তু অঙ্ক নয় — বসে না (কিছু একটা ভুল, মানুষের সামনে থাকে)
 *   ৩. রেফারেন্স ছাড়া সারি আগের মতোই অঙ্কে মেলে
 */
final class TheBankLineFindsItsVoucherByItsNumberTest extends TestCase
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
            'parent_id' => $head->id, 'code' => '1102-08', 'name_en' => 'TrxID Bank', 'name_bn' => 'TrxID ব্যাংক',
            'type' => $head->type, 'nature' => $head->nature, 'is_group' => false, 'money_kind' => Account::BANK, 'is_active' => true,
        ]);
    }

    /** ১ */
    public function test_two_same_amount_vouchers_each_find_their_own_bank_line(): void
    {
        $first = $this->bookLine('TT58201447', '500');
        $second = $this->bookLine('TT58209931', '500');
        // ⓘ ব্যাংকের ক্রম উল্টো — অঙ্ক বা ক্রম নয়, নম্বরই জোড়া বাঁধে
        $this->bankLine('TT58209931', '500.00');
        $this->bankLine('TT58201447', '500.00');

        $this->assertSame(2, $this->match(), '⛔ একই অঙ্কের দুই ভাউচার নিজের নম্বরেও ব্যাংকের সারিতে বসল না');
        $this->assertSame((int) $first->id, $this->matchedTo('TT58201447'), '⛔ ব্যাংকের সারি অন্য নম্বরের ভাউচারে বসল');
        $this->assertSame((int) $second->id, $this->matchedTo('TT58209931'), '⛔ ব্যাংকের সারি অন্য নম্বরের ভাউচারে বসল');
    }

    /** ২ */
    public function test_a_matching_number_with_another_amount_is_left_for_a_person(): void
    {
        $this->bookLine('TT77001234', '700');
        $this->bankLine('TT77001234', '500.00');

        $this->assertSame(0, $this->match(), '⛔ নম্বর মিলল কিন্তু অঙ্ক নয়, তবু জোড়া বসল — ব্যাংক আর বই আলাদা টাকা বলছে');
    }

    /** ৩ */
    public function test_a_line_without_a_reference_still_matches_by_amount(): void
    {
        $voucher = $this->bookLine('TT33005566', '500');
        $this->bankLine(null, '500.00');

        $this->assertSame(1, $this->match(), '⛔ রেফারেন্স ছাড়া সারি আর অঙ্কে মেলে না');
        $this->assertSame((int) $voucher->id, $this->matchedTo(null), '⛔ রেফারেন্স ছাড়া সারি অন্য ভাউচারে বসল');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function match(): int
    {
        return app(BankStatementService::class)->matchAgainstBooks($this->bank, '2026-09-30');
    }

    private function matchedTo(?string $reference): int
    {
        $line = BankStatementLine::query()->where('bank_account_id', $this->bank->id)->where('reference', $reference)->firstOrFail();

        return (int) Voucher::query()->whereHas('lines', fn ($q) => $q->whereKey($line->matched_line_id))->value('id');
    }

    private function bankLine(?string $reference, string $out): void
    {
        app(BankStatementService::class)->add($this->bank, '2026-09-07', 'Transfer', $reference, $out, '0');
    }

    private function bookLine(string $number, string $amount): Voucher
    {
        $charge = Account::query()->where('code', StandardChart::BANK_CHARGES)->postable()->firstOrFail();
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => '2026-09-07', 'narration' => 'ট্রান্সফার', 'instrument_no' => $number],
            [
                ['account_id' => $charge->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => $amount],
            ],
        );

        return app(VoucherService::class)->post($voucher);
    }
}
