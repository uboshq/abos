<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ ঋণের মূল টাকা নিজের ধরনের খাতে — নেওয়া ঋণে পোস্টযোগ্য দায়, দেওয়া হাতধারে পোস্টযোগ্য সম্পদ; দল বা টাকার খাত কখনো নয়
 * (fe, ২৪ অক্টোবর ২০২৬)।
 *
 * <p>আগে সার্ভার কোম্পানির যেকোনো খাতের id নিত (Rule::exists) — ব্যাংক, খরচ, দলও। লাইভে ঋণের ফর্মের তালিকা ফাঁকা ছিল বলে
 * কেউ ঋণ বানাননি, তাই খাতায় কিছু বসেনি; তালিকা ঠিক হওয়ার সাথে দরজাটাও বন্ধ ([[LoanService::create()]], ফর্ম, ফোন বা সরাসরি
 * ডাক — সবাই এখান দিয়ে)। ⓘ পুরনো ক্যাশ-ক্রেডিট ২১১১-এ — সেটাও পোস্টযোগ্য দায়, তাই চলে।
 */
final class ALoanSitsOnItsOwnKindOfAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_a_taken_loan_refuses_a_bank_an_expense_and_a_group(): void
    {
        $bank = Account::query()->money()->postable()->orderBy('id')->first()
            ?? StandardChart::find(StandardChart::CASH_IN_HAND);
        $this->assertNotNull($bank, 'প্রস্তুতিটাই ভুল — ডেমোতে টাকার কোনো খাত নেই');
        $expense = Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->firstOrFail();
        $group = StandardChart::find(StandardChart::LONG_TERM_LIABILITIES);

        foreach (['টাকার খাত' => $bank, 'খরচের খাত' => $expense, 'দায়ের দল' => $group] as $what => $account) {
            $this->assertNotNull($this->refusal(fn () => $this->taken((int) $account->id)),
                "⛔ নেওয়া ঋণের মূল টাকা {$what}-এ বসে গেল ({$account->code})");
        }

        $this->assertSame(0, Loan::query()->count(), '⛔ ফেরানো ঋণও খাতায় নথি হিসেবে বসে গেল');
    }

    public function test_a_taken_loan_sits_on_a_postable_liability_and_the_old_cash_credit_on_2111_still_works(): void
    {
        $this->assertSame(Loan::TAKEN, $this->taken((int) StandardChart::find('2210')->id)->direction, 'প্রস্তুতিটাই ভুল');
        $this->assertNull($this->refusal(fn () => $this->taken((int) StandardChart::find(StandardChart::PAYABLE)->id)),
            '⛔ পুরনো ক্যাশ-ক্রেডিটের ২১১১ (পোস্টযোগ্য দায়) আর নেওয়া গেল না');
    }

    public function test_a_hand_loan_given_sits_on_an_asset_not_a_liability(): void
    {
        $this->assertNotNull($this->refusal(fn () => $this->given((int) StandardChart::find(StandardChart::PAYABLE)->id)),
            '⛔ দেওয়া হাতধার দায়ের খাতে বসে গেল');
        // ⓘ ব্যাংক বা নগদও সম্পদ — তাই কেবল "টাকার খাত নয়" নিয়মই এখানে থামায়
        $money = Account::query()->money()->postable()->orderBy('id')->first();
        $this->assertNotNull($money, 'প্রস্তুতিটাই ভুল — ডেমোতে পোস্টযোগ্য টাকার খাত নেই');
        $this->assertNotNull($this->refusal(fn () => $this->given((int) $money->id)),
            '⛔ দেওয়া হাতধার নগদ বা ব্যাংকের খাতে বসে গেল ('.$money->code.')');
        $this->assertNull($this->refusal(fn () => $this->given((int) StandardChart::find(StandardChart::RECEIVABLE)->id)),
            '⛔ দেওয়া হাতধার পাওনার (সম্পদ) খাতেও বসল না');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function taken(int $principal): Loan
    {
        return app(LoanService::class)->create(data: [
            'lender' => 'City Bank', 'kind' => Loan::CC, 'sanctioned' => '500000', 'interest_rate' => '13.5', 'start_date' => '2026-07-01',
            'principal_account_id' => $principal,
            'interest_account_id' => Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->value('id'),
        ]);
    }

    private function given(int $principal): Loan
    {
        return app(LoanService::class)->create(data: [
            'lender' => 'Karim (dealer)', 'kind' => Loan::HAND, 'direction' => Loan::GIVEN, 'sanctioned' => '30000',
            'interest_rate' => '0', 'start_date' => '2026-07-01', 'principal_account_id' => $principal,
            'interest_account_id' => Account::query()->where('type', Account::INCOME)->postable()->orderBy('code')->value('id'),
        ]);
    }

    /** থামলে principal_account_id-এর কারণ, না থামলে null */
    private function refusal(callable $act): ?string
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('principal_account_id', $e->errors(), 'প্রস্তুতিটাই ভুল — অন্য কারণে থামল: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return (string) $e->errors()['principal_account_id'][0];
        }

        return null;
    }
}
