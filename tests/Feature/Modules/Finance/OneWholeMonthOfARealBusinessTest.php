<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\Finance\Services\WithdrawalService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একটা সত্যিকারের ব্যবসার একটা গোটা মাস।
 *
 * ── মালিকের কথা, ৫ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
 * *"আমি একটা ব্যবসার capital নিলাম ২৫ লাখ। একটা দোকান ভাড়া নিলাম, তার
 * এডভান্স দিলাম পাঁচ লাখ। লোকজন নিয়োগ করলাম, কোম্পানি থেকে পণ্য কিনে
 * আনলাম, গোডাউনে ঢুকালাম, বিক্রি করলাম। মাস শেষ হলো — ঘরভাড়া দিলাম,
 * বিল দিলাম, আনুষঙ্গিক জিনিসপত্র কিনলাম, লোডিং-আনলোডিং করলাম। মাস শেষে
 * প্রফিটটা উঠায় নিব, তারপর লাগলে আবার ইনভেস্ট করব, লোন নেবো। এই
 * প্রসেসগুলো কমপ্লিট কইরা চেক করবা হয়েছে কিনা।"*
 *
 * ── ⚠️ কেন এই পরীক্ষাটা বাকি সবগুলোর থেকে আলাদা ─────────────────────
 * এই রিপোর প্রতিটা পরীক্ষা **একটা করে জিনিস** মাপে, আর প্রতিটাই সবুজ
 * থাকতে পারে যখন **জোড়াগুলো** ভাঙা। মূলধন ঠিক বসে, ভাড়া ঠিক বসে,
 * উত্তোলন ঠিক বসে — অথচ মাস শেষে স্থিতিপত্র নাও মিলতে পারে।
 *
 * ⭐ তাই এখানে একটাই প্রশ্ন: **গোটা মাসটা চালানোর পর দুই পাশ মেলে
 * কি না**, আর মালিকের নিজের সংখ্যাগুলো ঠিক জায়গায় দাঁড়ায় কি না।
 *
 * ⓘ যা এখনো ধরা হয়নি: ক্রয়-বিক্রয়ের কাগজগুলো। ওগুলোর নিজস্ব পরীক্ষা
 * আছে, আর এখানে টানলে এই ফাইলটা সেগুলোর নকল হত। এখানে টাকার চক্রটাই
 * মাপা — মূলধন থেকে উত্তোলন পর্যন্ত।
 */
class OneWholeMonthOfARealBusinessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        /*
         * ⚠️ নগদের ঘরটা `১১০১` **নয়** — ওটা একটা দল, আর দলে টাকা বসলে
         * সে কোনো যোগফলে আসে না (৫ সেপ্টেম্বর ২০২৬-এ ঠিক এই বাগটা
         * চালানের কোডে পাওয়া গেছে)। টাকা বসে দলের সন্তান, অর্থাৎ
         * ক্যাশ কাউন্টারে।
         */
        $this->till = app(CashTillService::class)->ensurePrimaryTill()->account;
    }

    /**
     * গোটা মাসটা — আর শেষে দুই পাশ মেলে।
     */
    public function test_a_whole_month_from_capital_to_drawings_still_balances(): void
    {
        $rent = StandardChart::find(StandardChart::RENT);
        $loading = StandardChart::find(StandardChart::LOADING);
        $unloading = StandardChart::find(StandardChart::UNLOADING);

        $cashAtStart = $this->till->balanceOn();

        /* ── ১ · মূলধন ২৫ লাখ ──────────────────────────────────────── */
        $capital = app(CapitalService::class)->record([
            'contributor_name' => 'মালিক',
            'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => '2026-09-01',
            'amount' => '2500000',
        ]);

        app(CapitalService::class)->post($capital, $this->till);

        $this->assertSame(
            bcadd($cashAtStart, '2500000', 4),
            $this->till->fresh()->balanceOn(),
            'মূলধন ঢালার পর হাতে টাকা বাড়েনি।',
        );

        /* ── ২ · দোকানের অ্যাডভান্স ৫ লাখ ─────────────────────────── */
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'দোকানের মালিক',
            'subject' => 'দোকান',
            'deposit_amount' => '500000',
            'monthly_rent' => '30000',
            'monthly_adjustment' => '10000',
            'starts_on' => '2026-09-01',
            'term_months' => 24,
            'money_account_id' => $this->till->id,
        ]);

        /*
         * ⭐ অ্যাডভান্সটা **খরচ নয়, সম্পদ** — আর এটাই এই ধাপের আসল
         * পরীক্ষা। খরচ ধরলে প্রথম মাসেই পাঁচ লাখের লোকসান দেখাত, আর
         * দোকান ছাড়ার দিন হঠাৎ পাঁচ লাখের আয়।
         */
        $deposit = Account::query()->find($contract->account_id);
        $this->assertSame(StandardChart::SECURITY_DEPOSIT, $deposit->code);
        $this->assertSame('500000.0000', $contract->depositLeft());

        /* ── ৩ · মাসের ভাড়া: ২০ হাজার নগদে, ১০ হাজার জামানত থেকে ─── */
        app(RentalContractService::class)->adjustMonth($contract, [
            'for_month' => '2026-09-01',
            'money_account_id' => $this->till->id,
        ]);

        $this->assertSame('490000.0000', $contract->fresh()->depositLeft(),
            'ভাড়ার সমন্বয়ের পর জামানত ঠিক ১০ হাজার কমেনি।');

        /* ── ৪ · আনুষঙ্গিক খরচ — লোডিং ও আনলোডিং ─────────────────── */
        foreach ([[$loading, '4000'], [$unloading, '3500']] as [$head, $amount]) {
            $voucher = app(VoucherService::class)->create(
                [
                    'type' => Voucher::PAYMENT,
                    'trx_date' => '2026-09-20',
                    'narration' => 'মাসের কাজ',
                ],
                [
                    ['account_id' => $head->id, 'debit' => $amount, 'credit' => '0'],
                    ['account_id' => $this->till->id, 'debit' => '0', 'credit' => $amount],
                ],
            );

            app(VoucherService::class)->post($voucher);
        }

        /* ── ৫ · মাস শেষে উত্তোলন ─────────────────────────────────── */
        $withdrawal = app(WithdrawalService::class)->request([
            'contributor_name' => 'মালিক',
            'amount' => '50000',
            'trx_date' => '2026-09-30',
        ]);

        app(WithdrawalService::class)->post($withdrawal, $this->till);

        /* ── ৬ · হাতের টাকা, ধাপে ধাপে গোনা ───────────────────────── */
        $expected = bcadd($cashAtStart, '2500000', 4);   // মূলধন
        $expected = bcsub($expected, '500000', 4);        // অ্যাডভান্স
        $expected = bcsub($expected, '20000', 4);         // ভাড়ার নগদ অংশ
        $expected = bcsub($expected, '7500', 4);          // লোডিং + আনলোডিং
        $expected = bcsub($expected, '50000', 4);         // উত্তোলন

        $this->assertSame(
            $expected,
            $this->till->fresh()->balanceOn(),
            'মাস শেষে হাতের টাকা মেলেনি — কোনো একটা ধাপ খতিয়ানে যায়নি।',
        );

        /* ── ৭ · আর সবচেয়ে বড় প্রশ্ন: দুই পাশ মেলে কি না ─────────── */
        $this->assertBooksBalance('2026-09-30');
    }

    /**
     * ⛔ ভাড়ার খরচটা সত্যিই খরচে বসে, জামানতে নয়।
     *
     * ⚠️ ভুল হলে দুই দিকেই মিথ্যা: মাসের খরচ কম দেখাত (মুনাফা বেশি),
     * আর জামানত কমত না (ফেরত পাওয়ার টাকা বেশি)। ⓘ স্থিতিপত্র তবু
     * মিলত — দুইটা ভুল একে অন্যকে ঢেকে দিত। তাই আলাদা করে মাপা।
     */
    public function test_the_rent_lands_in_expense_not_in_the_deposit(): void
    {
        $rent = StandardChart::find(StandardChart::RENT);
        $before = $rent->balanceOn();

        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'দোকানের মালিক',
            'deposit_amount' => '500000',
            'monthly_rent' => '30000',
            'monthly_adjustment' => '10000',
            'starts_on' => '2026-09-01',
            'term_months' => 24,
            'money_account_id' => $this->till->id,
        ]);

        app(RentalContractService::class)->adjustMonth($contract, [
            'for_month' => '2026-09-01',
            'money_account_id' => $this->till->id,
        ]);

        $this->assertSame(
            bcadd($before, '30000', 4),
            $rent->fresh()->balanceOn(),
            'পুরো ৩০ হাজার ভাড়ার খরচে বসেনি — জামানত থেকে কাটা অংশটাও খরচ।',
        );
    }

    /**
     * খাতা মেলে কি না — ডেবিট ও ক্রেডিটের যোগফল ধরে।
     *
     * ── কেন `BalanceSheetService` নয় ────────────────────────────────
     * ⓘ ওটার নিজের পরীক্ষা আছে ([[ABalanceSheetThatDidNotBalanceTest]])।
     * এখানে প্রশ্নটা আরও গোড়ার: **খতিয়ানটাই মেলে কি না**। রিপোর্ট ধরে
     * মাপলে রিপোর্টের কোনো ভুল এই পরীক্ষাটাকেও ভুল পথে নিত।
     */
    private function assertBooksBalance(string $asOf): void
    {
        $row = \Illuminate\Support\Facades\DB::table('ledger_entries')
            ->where('company_id', $this->company->id)
            ->whereDate('trx_date', '<=', $asOf)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $this->assertSame(
            (string) $row->d,
            (string) $row->c,
            'খতিয়ানে ডেবিট আর ক্রেডিট সমান নয় — মাসের কোনো একটা কাজ আধা বসেছে।',
        );

        /* ⓘ শূন্য সংগ্রহে চালানো তুলনা সবসময় সত্য — তাই কিছু বসেছে কি না তাও দেখা হয়। */
        $this->assertGreaterThan(0, (float) $row->d, 'খতিয়ানে একটাও সারি নেই — মাসটা কি সত্যিই চলেছে?');
    }
}
