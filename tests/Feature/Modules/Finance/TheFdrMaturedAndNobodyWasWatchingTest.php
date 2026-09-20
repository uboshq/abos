<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FDR-এর মেয়াদ ফুরিয়ে গেল, আর কেউ তাকিয়ে ছিল না।
 *
 * ── ⓘ অর্থের মানচিত্র §১৪ক, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────
 * দুইটা লাইন, দুইটাই এক পাতায়:
 *   · **মেয়াদপূর্তির আগাম খবর** — ⚠️ তারিখটা ফসকালে ব্যাংক টাকাটা আপনা
 *     থেকে নতুন মেয়াদে আটকে দেয়, প্রায়ই কম হারে। এক মাস আগে জানা গেলে
 *     সিদ্ধান্ত নেওয়া যায়।
 *   · **বন্ধকী জমা বনাম ঋণ** — কলামটা (`pledged_to_loan_id`) ছিল, পর্দা
 *     ছিল না। ⓘ বন্ধক দেওয়া জমা ভাঙা যায় না, তাই "কতটা খালি" প্রশ্নের
 *     উত্তর এখান থেকেই শুরু।
 */
final class TheFdrMaturedAndNobodyWasWatchingTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        // ℹ ধরনগুলো সিডারে নেই, ডিপ্লয়ের ইনস্টলারে — তাই এখানেই বসাতে হয়
        app(DepositKindInstaller::class)->install();
        $this->cash = app(CashTillService::class)->ensurePrimaryTill()->account;
    }

    /**
     * ⭐ মেয়াদের ট্যাব — জানালাটা ৩০ দিনের, আর দূরেরগুলো বাইরে থাকে।
     */
    public function test_the_maturing_tab_looks_only_as_far_as_asked(): void
    {
        $soon = $this->deposit('FD-SOON', now()->addDays(10)->toDateString());
        $later = $this->deposit('FD-LATER', now()->addDays(45)->toDateString());
        $far = $this->deposit('FD-FAR', now()->addDays(200)->toDateString());

        $page = $this->get(route('finance.deposit.index', ['issuer' => 'bank', 'tab' => 'maturing']))
            ->assertOk();

        $this->assertSame([$soon->id], $this->ids($page),
            'ত্রিশ দিনের জানালায় কেবল কাছেরটাই থাকার কথা।');

        $this->assertSame(1, $page->viewData('counts')['maturing']);

        // ⓘ ষাট দিনের জানালায় দুইটা, তিনশো দিনেরটা তবু বাইরে
        $wider = $this->get(route('finance.deposit.index',
            ['issuer' => 'bank', 'tab' => 'maturing', 'within' => 60]))->assertOk();

        $this->assertEqualsCanonicalizing([$soon->id, $later->id], $this->ids($wider));
        $this->assertNotContains($far->id, $this->ids($wider));
    }

    /**
     * ⛔ বন্ধ হয়ে যাওয়া জমার মেয়াদ নিয়ে আর কিছু করার নেই।
     */
    public function test_a_closed_deposit_is_not_chased(): void
    {
        $deposit = $this->deposit('FD-DONE', now()->addDays(5)->toDateString());

        $deposit->forceFill(['status' => Deposit::CLOSED, 'closed_on' => now()->toDateString()])->save();

        $this->assertSame(0, $this->get(route('finance.deposit.index',
            ['issuer' => 'bank', 'tab' => 'maturing']))->viewData('counts')['maturing']);
    }

    /**
     * ⭐ বন্ধকের ট্যাব — কোনটা কোন ঋণের জামানতে, আর কোনটা খালি।
     */
    public function test_the_pledged_tab_says_which_loan_holds_it(): void
    {
        /*
         * ⓘ ঋণটা সেবার পথেই — সারি হাতে বানালে খাতার দাখিলাগুলো বাদ পড়ত,
         * আর তখন পরীক্ষা সত্যিকারের অবস্থাটা মাপত না।
         */
        $loan = app(\App\Modules\Accounts\Services\LoanService::class)->create(
            data: [
                'lender' => 'Islami Bank',
                'kind' => Loan::TERM,
                'sanctioned' => '500000',
                'interest_rate' => '12',
                'tenure_months' => 12,
                'interest_method' => 'flat',
                'start_date' => now()->toDateString(),
                'principal_account_id' => $this->account(StandardChart::PAYABLE)->id,
                'interest_account_id' => $this->account(StandardChart::INTEREST_EXPENSE)->id,
            ],
            intoAccountId: $this->cash->id,
        );

        $pledged = $this->deposit('FD-PLEDGED', now()->addDays(300)->toDateString(), $loan->id);
        $free = $this->deposit('FD-FREE', now()->addDays(300)->toDateString());

        $page = $this->get(route('finance.deposit.index', ['issuer' => 'bank', 'tab' => 'pledged']))
            ->assertOk();

        $this->assertSame([$pledged->id], $this->ids($page), 'খালি জমাটাও বন্ধকের ট্যাবে এসেছে।');
        $this->assertSame(1, $page->viewData('counts')['pledged']);

        /* ⓘ তালিকায় ঋণের নম্বরটা দেখা যায়, আর খালিটার পাশে "খালি"।
           ⚠️ নম্বরটা সেবার দেওয়া নম্বরই ধরা হয়, হাতে লেখা নয় — নম্বরের
           ছক বদলালে পরীক্ষা মিথ্যা লাল হত। */
        $page->assertSee($loan->fresh()->document_no);

        // ⭐ আর নম্বরটা ঋণের পাতাতেই নামে (মালিকের "সব জায়গায় লিংক")
        $page->assertSee(route('accounts.loan.show', ['loan' => $loan->id]), escape: false);

        $this->get(route('finance.deposit.index', ['issuer' => 'bank']))
            ->assertOk()
            ->assertSee(__('finance::field.dep_free'));

        $this->assertNotNull($free->fresh());
    }

    /** ছকের একটা পোস্টযোগ্য খাত — কোড ধরে। */
    private function account(string $code): Account
    {
        return Account::query()->postable()->where('code', $code)->firstOrFail();
    }

    /** @return list<int> */
    private function ids(\Illuminate\Testing\TestResponse $page): array
    {
        return collect($page->viewData('deposits')->items())
            ->map(fn (Deposit $d) => (int) $d->id)
            ->all();
    }

    private function deposit(string $reference, string $matures, ?int $pledgedTo = null): Deposit
    {
        return app(DepositService::class)->open([
            'kind_id' => DepositKind::query()->where('code', 'FDR')->firstOrFail()->id,
            'institution' => 'সোনালী ব্যাংক',
            'reference_no' => $reference,
            'held_by' => Deposit::BUSINESS,
            'principal' => '100000',
            'return_word' => 'interest',
            /* ⚠️ খোলার তারিখ আজ, ছয় মাস আগে নয়: দাখিলা চলতি অর্থবছরের
               বাইরে পড়লে সেবাটা সঠিকভাবেই আটকে দেয়, আর এই পরীক্ষার
               প্রশ্নটা মেয়াদের জানালা নিয়ে, খোলার তারিখ নিয়ে নয়। */
            'opened_on' => now()->toDateString(),
            'matures_on' => $matures,
            'funded_from_account_id' => $this->cash->id,
            'pledged_to_loan_id' => $pledgedTo,
        ]);
    }
}
