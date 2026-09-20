<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হাতধারের তালিকাটা তার নিজের ফর্মের নিচে চাপা ছিল।
 *
 * ── ⛔ মালিকের প্রশ্ন, ১৯ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"এগুলোর লিস্ট, পিপল লিস্ট কোথায়?"* ⓘ তালিকাটা ছিল — লম্বা ফর্মের
 * নিচে। আর ফর্মের নিচের অংশ (ফিতা · কাগজ · Save) একটা সরু কলামে
 * চাপা, বোতামের লেখা চার লাইনে।
 *
 * ── ⭐ এখন, মূলধনের পাতার ছাঁচে (মালিকের নমুনা) ───────────────────────
 * টুলবার (শিরোনাম · বর্ণনা · + নতুন হাতধার) → যোগফল → ট্যাব "সব ·
 * তারা দেবে · আমরা দেব" সংখ্যাসহ → তালিকা। ফর্ম নিজের পাতায়।
 */
final class TheHandLoanListWasBuriedUnderItsFormTest extends TestCase
{
    use RefreshDatabase;

    private int $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        $this->till = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
    }

    /**
     * ⭐ তালিকার পাতায় তালিকা — ফর্ম নয়; "নতুন" নিজের পাতায় নেয়।
     */
    public function test_the_list_page_is_a_list_and_new_goes_to_its_own_page(): void
    {
        $this->loan('Karim Lender', HandLoanMovement::OUT, '1000');

        $page = $this->get(route('finance.hand_loan.index'))->assertOk();

        $page->assertSee('Karim Lender');
        $page->assertSee(route('finance.hand_loan.create'), escape: false);

        /*
         * ⚠️ ঠিকানা ধরে "ফর্ম নেই" প্রমাণ করা যায় না: তালিকা আর সংরক্ষণের
         * ঠিকানা **হুবহু এক** (একটা GET, একটা POST), আর ট্যাবের লিংকেও
         * ওটাই বসে। ⓘ তাই ফর্মের নিজের ঘর ধরে দেখা।
         */
        $page->assertDontSee('name="principal"', escape: false);
        $page->assertDontSee('name="person_id"', escape: false);

        $this->get(route('finance.hand_loan.create'))
            ->assertOk()
            ->assertSee(route('finance.hand_loan.store'), escape: false);
    }

    /**
     * ⭐ ট্যাব — তারা দেবে · আমরা দেব, আর পাশের সংখ্যা তালিকার সাথে মেলে।
     */
    public function test_the_tabs_split_who_owes_whom(): void
    {
        $this->loan('Karim Lender', HandLoanMovement::OUT, '1000');   // আমরা দিয়েছি → তিনি দেবেন
        $this->loan('Rahim Creditor', HandLoanMovement::IN, '500');   // আমরা নিয়েছি → আমাদের দিতে হবে

        $they = $this->get(route('finance.hand_loan.index', ['tab' => 'they']))->assertOk();
        $this->assertSame(['Karim Lender'], $this->names($they));
        $this->assertSame(['all' => 2, 'they' => 1, 'we' => 1], $they->viewData('counts'));

        $we = $this->get(route('finance.hand_loan.index', ['tab' => 'we']))->assertOk();
        $this->assertSame(['Rahim Creditor'], $this->names($we),
            'আমরা যাঁর কাছে ধারি, তিনি "আমরা দেব" ট্যাবে নেই।');
    }

    /**
     * ⭐ খোঁজা — নামে; উপরের যোগফল পুরো প্রতিষ্ঠানের থাকে।
     */
    public function test_search_narrows_the_list_but_not_the_totals(): void
    {
        $this->loan('Karim Lender', HandLoanMovement::OUT, '1000');
        $this->loan('Rahim Creditor', HandLoanMovement::IN, '500');

        $page = $this->get(route('finance.hand_loan.index', ['q' => 'karim']))->assertOk();

        $this->assertSame(['Karim Lender'], $this->names($page));
        $this->assertSame(0, bccomp((string) $page->viewData('standing')['we_owe'], '500', 4),
            'খোঁজায় উপরের যোগফলও ছাঁকা হয়ে গেছে।');
    }

    /**
     * ⭐ নিজের পাতার ফর্ম — সংরক্ষণ আগের মতোই কাজ করে।
     */
    public function test_the_form_on_its_own_page_still_saves(): void
    {
        $person = $this->person('New Friend');

        $this->post(route('finance.hand_loan.store'), ['person_id' => $person->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('fin_hand_loan_accounts', ['person_id' => $person->id]);
    }

    private function loan(string $name, string $direction, string $amount): HandLoanAccount
    {
        $service = app(HandLoanService::class);
        $account = $service->open(['person_id' => $this->person($name)->id]);

        $service->move($account, [
            'direction' => $direction,
            'amount' => $amount,
            'money_account_id' => $this->till,
            'trx_date' => now()->toDateString(),
        ]);

        return $account;
    }

    private function person(string $name): Person
    {
        return Person::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'P-'.substr(md5($name), 0, 6),
            'name_en' => $name,
            'is_active' => true,
        ]);
    }

    /** @return list<string> */
    private function names(\Illuminate\Testing\TestResponse $page): array
    {
        return collect($page->viewData('rows'))
            ->map(fn (array $row) => (string) $row['account']->person?->name_en)
            ->values()
            ->all();
    }
}
