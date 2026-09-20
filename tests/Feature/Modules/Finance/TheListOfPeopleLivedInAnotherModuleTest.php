<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মানুষের তালিকাটা থাকত অন্য মডিউলে।
 *
 * ── ⓘ মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * *"ki kotha cilo master e thakbe na eta, eta হাতধার ekta tab e কার সাথে
 * list thakbe"* — দরজাটা যেখানে কাজ হয় সেখানেই থাকা উচিত। ⛔ নাম যোগ
 * করতে মাস্টার ডাটায় যেতে হলে কেউ যেত না, আর ধারটা নামহীন থাকত।
 *
 * ── ⛔ তবু দ্বিতীয় কোনো টেবিল নয় ─────────────────────────────────────
 * ⚠️ সারিগুলো একটাই তালিকা (`mdm_people`) থেকে আসে। আলাদা টেবিল হলে
 * "Al Amin", "Al-Amin" আর "আল আমিন" তিনজন হয়ে যেতেন, আর একজনের পাওনা
 * তিন ভাগে ছিঁড়ত।
 */
final class TheListOfPeopleLivedInAnotherModuleTest extends TestCase
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

    /**
     * ⭐ ট্যাবটা আছে, আর তাতে মানুষগুলোর পাওনা-দেনা লেখা।
     */
    public function test_the_tab_shows_each_person_with_both_sides(): void
    {
        $this->loan('করিম', HandLoanMovement::OUT, '1000');
        $this->loan('রহিম', HandLoanMovement::IN, '500');

        $page = $this->get(route('finance.hand_loan.index', ['tab' => 'people']))->assertOk();

        $rows = collect($page->viewData('people'))
            ->mapWithKeys(fn (array $r) => [$r['person']->name() => $r]);

        $this->assertSame('1000.0000', $rows['করিম']['to_us']);
        $this->assertSame('0', $rows['করিম']['by_us']);

        $this->assertSame('500.0000', $rows['রহিম']['by_us']);
        $this->assertSame(1, $rows['রহিম']['open']);
    }

    /**
     * ⭐ নাম যোগ করা যায় এই ট্যাব থেকেই — আর সেটা একই মানুষের তালিকায় বসে।
     */
    public function test_a_name_can_be_added_right_here(): void
    {
        $this->post(route('finance.hand_loan.person.store'), [
            'name_bn' => 'আবদুল হাই',
            'mobile' => '01711000000',
        ])->assertSessionHasNoErrors();

        /* ⓘ লেখা নামটা `name_en`-এ বসে ([[PersonResolver]]) — ঘরটা
           যে ভাষাতেই লেখা হোক, একটাই ঘরে। */
        $person = Person::query()->where('name_en', 'আবদুল হাই')->firstOrFail();

        $this->assertSame('01711000000', $person->mobile);

        // ⭐ আর সাথে সাথেই তালিকায় দেখা যায়, যদিও কোনো হিসাব খোলা হয়নি
        $this->get(route('finance.hand_loan.index', ['tab' => 'people']))
            ->assertOk()
            ->assertSee('আবদুল হাই');
    }

    /**
     * ⛔ নাম ছাড়া সারি নয় — নামহীন মানুষের হিসাব কারও কাজে লাগে না।
     */
    public function test_a_nameless_row_is_refused(): void
    {
        $this->post(route('finance.hand_loan.person.store'), ['mobile' => '01711000000'])
            ->assertSessionHasErrors('name_bn');
    }

    /**
     * ⭐ ট্যাবের পাশের সংখ্যাটা সারির সংখ্যার সমান।
     */
    public function test_the_count_matches_the_rows(): void
    {
        $this->loan('করিম', HandLoanMovement::OUT, '1000');

        $page = $this->get(route('finance.hand_loan.index', ['tab' => 'people']))->assertOk();

        $this->assertSame(
            count($page->viewData('people')),
            $page->viewData('counts')['people'],
            'ট্যাবের সংখ্যা আর তালিকার সারি আলাদা কথা বলছে।',
        );
    }

    private function loan(string $name, string $direction, string $amount): void
    {
        $service = app(HandLoanService::class);

        $account = $service->open(['person_id' => $this->person($name)->id]);

        $service->move($account, [
            'direction' => $direction,
            'amount' => $amount,
            'money_account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id,
            'trx_date' => now()->toDateString(),
        ]);
    }

    private function person(string $name): Person
    {
        return Person::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'P-'.substr(md5($name), 0, 6),
            'name_en' => $name,
            'name_bn' => $name,
            'is_active' => true,
        ]);
    }
}
