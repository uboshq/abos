<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * কথার উপর দেওয়া টাকাও টাকা।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ABOS-এ ঋণ ছিল, কিন্তু কেবল ব্যাংকের ঋণ — কাগজপত্র, কিস্তি, সুদ। আর
 * ডিপোর বাস্তবতায় টাকার একটা বড় অংশ চলে হাতধারে: মালিক আত্মীয়ের কাছ
 * থেকে নেন, আবার ডিলারকে কাগজ ছাড়াই দেন।
 *
 * ওই টাকাটা খাতায় না থাকলে বছর শেষে পুঁজির হিসাবটা ঠিক ওই পরিমাণ ভুল
 * হয় — আর "পুঁজির উপর ফেরত" রিপোর্টটার পুরো ভিত্তিই ওই হিসাব।
 *
 * ── সবচেয়ে বিপজ্জনক অংশটা: দিক ────────────────────────────────────
 * ব্যাংক ঋণ সবসময় নেওয়া, তাই টেবিলের ঘরটার নাম ছিল
 * `liability_account_id` — আর নামটা ঠিকই ছিল। হাতধার দেওয়াও যায়, আর
 * দেওয়া ধার দায় নয়, পাওনা। নামটা তখন মিথ্যা বলা শুরু করত, তাই
 * `principal_account_id` করা হয়েছে।
 */
class MoneyLentOnAWordIsStillMoneyTest extends TestCase
{
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
    }

    private function money(): Account
    {
        return Account::query()->firstOrCreate(
            ['code' => '1101-HAND'],
            [
                'company_id' => $this->company->id,
                'parent_id' => StandardChart::find(StandardChart::CASH_IN_HAND)->id,
                'name_en' => 'Main till',
                'name_bn' => 'প্রধান ক্যাশ',
                'type' => Account::ASSET,
                'nature' => Account::DEBIT,
                'is_cash' => true,
            ],
        );
    }

    /** দায়ের একটা খাত — নেওয়া হাতধারের জন্য। */
    private function payable(): Account
    {
        return Account::query()->where('code', '2210')->firstOrFail();
    }

    /** পাওনার একটা খাত — দেওয়া হাতধারের জন্য। */
    private function receivable(): Account
    {
        return StandardChart::find(StandardChart::RECEIVABLE);
    }

    private function interest(): Account
    {
        return Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->firstOrFail();
    }

    private function handLoan(string $direction, Account $principal, string $amount, ?string $dueOn = null): Loan
    {
        return app(LoanService::class)->create(
            data: [
                'lender' => $direction === Loan::GIVEN ? 'Karim (dealer)' : 'Uncle Rahim',
                'kind' => Loan::HAND,
                'direction' => $direction,
                'sanctioned' => $amount,
                'interest_rate' => '0',
                'start_date' => '2026-08-01',
                'due_on' => $dueOn,
                'principal_account_id' => $principal->id,
                'interest_account_id' => $this->interest()->id,
            ],
            intoAccountId: $this->money()->id,
        );
    }

    /** হাতধারে কোনো কিস্তির সূচি বানানো হয় না। */
    public function test_a_hand_loan_has_no_instalment_schedule(): void
    {
        $loan = $this->handLoan(Loan::TAKEN, $this->payable(), '50000');

        $this->assertTrue($loan->isHandLoan());
        $this->assertSame(0, $loan->instalments()->count(),
            'হাতধারে কিস্তির সূচি বানানো হয়েছে — ওখানে কিস্তি বলে কিছু নেই।');
    }

    /* ── নেওয়া হাতধার ───────────────────────────────────────────── */

    public function test_money_borrowed_on_a_word_becomes_a_liability(): void
    {
        $loan = $this->handLoan(Loan::TAKEN, $this->payable(), '50000');

        // নগদ বেড়েছে
        $cash = LedgerEntry::query()->where('account_id', $this->money()->id)->sum('debit');
        $this->assertSame('50000.0000', (string) $cash);

        // দায় জন্মেছে
        $this->assertSame('50000.0000', $loan->outstanding());
    }

    public function test_paying_a_borrowed_hand_loan_back_clears_it(): void
    {
        $loan = $this->handLoan(Loan::TAKEN, $this->payable(), '50000');

        app(LoanService::class)->repay($loan, '50000', $this->money()->id, '2026-08-20');

        $this->assertSame('0.0000', $loan->refresh()->outstanding());
        $this->assertTrue($loan->isSettled());
    }

    /* ── দেওয়া হাতধার — এখানেই চিহ্ন ভুল হওয়ার আসল ঝুঁকি ────────── */

    /**
     * দেওয়া ধার পাওনা, দায় নয়।
     *
     * দাখিলা উল্টো না বসালে যাঁকে ধার দিলাম তাঁকেই আমাদের পাওনাদার
     * দেখাত, আর মোট দায় ঠিক দ্বিগুণ পরিমাণ বেশি দেখাত।
     */
    public function test_money_lent_out_leaves_the_till_and_becomes_a_receivable(): void
    {
        $loan = $this->handLoan(Loan::GIVEN, $this->receivable(), '30000');

        $this->assertTrue($loan->isGiven());

        // নগদ কমেছে — ক্রেডিট, ডেবিট নয়
        $out = LedgerEntry::query()->where('account_id', $this->money()->id)->sum('credit');
        $this->assertSame('30000.0000', (string) $out);

        $in = LedgerEntry::query()->where('account_id', $this->money()->id)->sum('debit');
        $this->assertSame('0.0000', (string) $in,
            'ধার দেওয়ার পরেও নগদ বেড়েছে — দাখিলাটা উল্টো দিকে বসেনি।');
    }

    /**
     * দেওয়া ধারে বকেয়া ধনাত্মক।
     *
     * `outstanding()` দায়ের জন্য চিহ্ন উল্টে দেয়। দেওয়া ধারে ওই
     * উল্টানোটা আবার করলে প্রতিটা পাওনা মাইনাস চিহ্ন নিয়ে বসত।
     */
    public function test_what_is_owed_to_us_reads_as_a_positive_number(): void
    {
        $loan = $this->handLoan(Loan::GIVEN, $this->receivable(), '30000');

        $this->assertSame('30000.0000', $loan->outstanding());
    }

    public function test_money_coming_back_clears_a_loan_we_gave(): void
    {
        $loan = $this->handLoan(Loan::GIVEN, $this->receivable(), '30000');

        app(LoanService::class)->repay($loan, '30000', $this->money()->id, '2026-08-25');

        $this->assertSame('0.0000', $loan->refresh()->outstanding());

        // টাকাটা ফিরে এসেছে, অর্থাৎ নগদ আবার বেড়েছে
        $back = LedgerEntry::query()->where('account_id', $this->money()->id)->sum('debit');
        $this->assertSame('30000.0000', (string) $back);
    }

    /* ── কথা দেওয়া তারিখ ─────────────────────────────────────────── */

    public function test_a_loan_past_its_promised_date_is_overdue(): void
    {
        $loan = $this->handLoan(Loan::GIVEN, $this->receivable(), '30000', '2026-08-10');

        $this->assertTrue($loan->isOverdue(Carbon::parse('2026-08-20')));
        $this->assertFalse($loan->isOverdue(Carbon::parse('2026-08-05')));
    }

    /** ফেরত এসে গেলে আর দেরি নেই, তারিখ যতই পুরনো হোক। */
    public function test_a_settled_loan_is_never_overdue(): void
    {
        $loan = $this->handLoan(Loan::GIVEN, $this->receivable(), '30000', '2026-08-10');

        app(LoanService::class)->repay($loan, '30000', $this->money()->id, '2026-08-12');

        $this->assertFalse($loan->refresh()->isOverdue(Carbon::parse('2026-09-30')));
    }

    /**
     * তারিখ না থাকলে দেরিও নেই।
     *
     * কেউ ফেরতের তারিখ বলেননি মানে কথা ভাঙেননি। তারিখহীন ধারকে দেরি
     * ধরলে তালিকাটা প্রথম দিন থেকেই লাল হয়ে থাকত, আর লাল রংটার
     * কোনো মানে থাকত না।
     */
    public function test_a_loan_with_no_promised_date_is_never_overdue(): void
    {
        $loan = $this->handLoan(Loan::TAKEN, $this->payable(), '50000');

        $this->assertFalse($loan->isOverdue(Carbon::parse('2030-01-01')));
    }

    /** পুরনো ব্যাংক ঋণগুলো ডিফল্টে "নেওয়া" — ইতিহাস অটুট। */
    public function test_an_ordinary_loan_is_still_taken_by_default(): void
    {
        $loan = app(LoanService::class)->create(
            data: [
                'lender' => 'Sonali Bank',
                'kind' => Loan::CC,
                'sanctioned' => '100000',
                'interest_rate' => '12',
                'start_date' => '2026-08-01',
                'principal_account_id' => $this->payable()->id,
                'interest_account_id' => $this->interest()->id,
            ],
        );

        $this->assertFalse($loan->isGiven());
        $this->assertSame(Loan::TAKEN, $loan->direction);
    }
}
