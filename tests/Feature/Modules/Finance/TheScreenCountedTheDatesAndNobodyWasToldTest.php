<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\DueNotices;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use App\Modules\MasterData\Services\PersonResolver;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পর্দা তারিখগুলো গুনত, আর কাউকে বলত না।
 *
 * ── ⓘ অর্থের মানচিত্র §১৪ক ও §১৪খ ────────────────────────────────────
 * দুইটা ট্যাব ছিল — জমার "মেয়াদ আসছে" আর হাতধারের "মনে করিয়ে দেওয়া" —
 * আর দুইটাই সঠিক সংখ্যা দেখাত। ⛔ কিন্তু সংখ্যাটা দেখা যেত কেবল পাতাটা
 * কেউ খুললে, আর তারিখ ফসকায় ঠিক ওই দিনগুলোতেই যেদিন কেউ খোলেননি।
 *
 * ── ⚠️ পরীক্ষাটা কী পাহারা দেয় ───────────────────────────────────────
 * তিনটা কথা: খবরটা **যায়**, একই খবর **দুইবার যায় না**, আর যার তারিখ
 * দূরে বা নেই তার খবর **যায় না**। ⓘ শেষ দুইটা প্রথমটার চেয়ে কম জরুরি
 * নয় — বাড়তি খবর মানুষকে ঘণ্টা দেখা বন্ধ করিয়ে দেয়
 * ([[NotificationService]])।
 */
final class TheScreenCountedTheDatesAndNobodyWasToldTest extends TestCase
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
        app(DepositKindInstaller::class)->install();
    }

    /**
     * ⭐ যে জমার মেয়াদ দশ দিনে শেষ, তার খবর ঘণ্টায় পৌঁছায়।
     */
    public function test_a_deposit_about_to_mature_reaches_the_bell(): void
    {
        $deposit = $this->openADeposit(10);

        $sent = $this->fire();

        $this->assertGreaterThan(0, $sent['maturing'], 'মেয়াদের কোনো খবরই যায়নি।');

        $notice = Notification::query()
            ->where('type', DueNotices::MATURING)
            ->latest('id')
            ->first();

        $this->assertNotNull($notice, 'মেয়াদের খবরটা কোথাও লেখা হয়নি।');
        $this->assertStringContainsString($deposit->document_no, (string) $notice->title);

        // ⓘ খবরটা পড়ে যাওয়ার জায়গা থাকতে হবে — নাহলে সেটা নিছক একটা বাক্য
        $this->assertStringContainsString((string) $deposit->id, (string) $notice->url);
    }

    /**
     * ⛔ একই জমার খবর সপ্তাহে একবার — রোজ নয়।
     *
     * ⚠️ ত্রিশ দিনের জানালায় রোজ পাঠালে একটা FDR-এর জন্যই ত্রিশটা খবর,
     * আর তারপর মানুষ ঘণ্টাটা দেখা বন্ধ করে দেন।
     */
    public function test_the_same_deposit_is_not_told_twice_in_a_week(): void
    {
        $this->openADeposit(10);

        $first = $this->fire();
        $second = $this->fire();

        $this->assertGreaterThan(0, $first['maturing']);
        $this->assertSame(0, $second['maturing'], 'একই জমার খবর দ্বিতীয়বার গেছে।');
    }

    /**
     * ⛔ যার মেয়াদ এখনো তিন মাস দূরে, তার খবর যায় না।
     */
    public function test_a_deposit_far_from_maturing_says_nothing(): void
    {
        $this->openADeposit(90);

        $this->assertSame(0, $this->fire()['maturing'], 'দূরের মেয়াদেও খবর গেছে।');
    }

    /**
     * ⭐ তারিখ পেরোনো হাতধারের তাগাদা যায়।
     */
    public function test_a_hand_loan_past_its_date_is_chased(): void
    {
        $this->aHandLoan(now()->subDays(3)->toDateString());

        $sent = $this->fire();

        $this->assertGreaterThan(0, $sent['hand_loans'], 'হাতধারের কোনো তাগাদা যায়নি।');
        $this->assertTrue(
            Notification::query()->where('type', DueNotices::HAND_LOAN_DUE)->exists(),
            'তাগাদাটা কোথাও লেখা হয়নি।',
        );
    }

    /**
     * ⛔ *"যখন পারো দিও"* — তারিখহীন ধারে মনে করিয়ে দেওয়ার কিছু নেই।
     */
    public function test_a_hand_loan_with_no_date_is_never_chased(): void
    {
        $this->aHandLoan(null);

        $this->assertSame(0, $this->fire()['hand_loans'], 'তারিখহীন ধারেও তাগাদা গেছে।');
    }

    /**
     * কমান্ডটাই চালানো হয়, সেবাটা সরাসরি নয় — শিডিউলার যা ডাকে ঠিক তাই।
     *
     * ⚠️ লগইন ছেড়ে দেওয়া হয়: [[NotificationService::send()]] নিজের করা
     * কাজের খবর নিজের কাছে পাঠায় না, আর কনসোলে কেউ লগইন থাকেই না।
     * ⛔ লগইন রেখে দিলে মালিককে বাদ দিয়ে খবর যেত, আর পরীক্ষাটা
     * বাস্তবের চেয়ে অন্য একটা জিনিস মাপত।
     *
     * @return array{maturing: int, hand_loans: int}
     */
    private function fire(): array
    {
        auth()->logout();

        /*
         * ⚠️ গোনা হয় **এই ডাকে নতুন করে বসা সারিগুলো**, মোট নয়।
         *
         * ⛔ মোট গুনলে "দ্বিতীয়বার যায়নি" দাবিটা কখনোই প্রমাণ করা যেত না
         * — প্রথম ডাকের সারিটাই দ্বিতীয়বারও গোনা হত।
         */
        $was = (int) Notification::query()->max('id');

        $this->artisan('abos:money-due')->assertSuccessful();

        $fresh = Notification::query()
            ->where('id', '>', $was)
            ->whereIn('type', [DueNotices::MATURING, DueNotices::HAND_LOAN_DUE])
            ->get();

        return [
            'maturing' => $fresh->where('type', DueNotices::MATURING)->count(),
            'hand_loans' => $fresh->where('type', DueNotices::HAND_LOAN_DUE)->count(),
        ];
    }

    private function openADeposit(int $inDays): Deposit
    {
        $kind = DepositKind::query()->where('code', 'FDR')->firstOrFail();

        return app(DepositService::class)->open([
            'kind_id' => $kind->id,
            'institution' => 'সোনালী ব্যাংক',
            'held_by' => Deposit::BUSINESS,
            'principal' => '100000',
            'return_word' => 'interest',
            'opened_on' => now()->toDateString(),
            'matures_on' => now()->addDays($inDays)->toDateString(),
            'funded_from_account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id,
        ]);
    }

    private function aHandLoan(?string $dueOn): HandLoanAccount
    {
        $data = ['person_new' => 'তাগাদা পরীক্ষা'];
        $personId = Person::query()->value('id') ?? app(PersonResolver::class)->resolve($data);

        $loan = app(HandLoanService::class)->open([
            'person_id' => $personId,
            'principal' => '0',
            'due_on' => $dueOn,
        ]);

        /*
         * ⓘ টাকাটা সত্যিই বেরোতে হয় — নাহলে জের শূন্য, আর শূন্য জেরের
         * ধারে তাগাদা যায় না ([[DueNotices::handLoansDue()]])।
         */
        app(HandLoanService::class)->move($loan, [
            'direction' => HandLoanMovement::OUT,
            'amount' => '5000',
            'trx_date' => now()->toDateString(),
            'money_account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id,
        ]);

        return $loan->fresh();
    }
}
