<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ভাড়াটা যেত হাতে লেখা একটা নামে, হাতে লেখা একটা জায়গার জন্য।
 *
 * ── ⓘ মালিকের প্রশ্ন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"কার সাথে * কীসের জন্য etar list kothay pabo?"* — কোথাও ছিল না।
 * ⛔ দুইটাই মুক্ত-লেখা ঘর, তাই একজন বাড়িওয়ালা তিন বানানে তিনজন হয়ে
 * যেতেন, আর গুদামের ভাড়াটা কোনো গুদামের সাথে মেলানো যেত না।
 *
 * ── ⭐ আর এই পরীক্ষার আসল কথা: দুইটা দিক ───────────────────────────
 * ১. চুক্তি খুললে জায়গাটা দেখা যায়, আর ওখানেই নামা যায়।
 * ২. জায়গাটার দিক থেকে চুক্তিটা খুঁজে পাওয়া যায় (`?subject=…`)।
 * ⚠️ দ্বিতীয়টাই দামি: যে ভাড়া কোনো জায়গার সাথে মেলে না, সেটাই ডিপোকে
 * ছেড়ে আসা গুদামের ভাড়া দিতে থাকায়।
 */
final class TheRentWentToATypedNameForATypedPlaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⭐ ফর্মে দুইটা তালিকাই আছে — বাড়িওয়ালা আর জায়গা।
     */
    public function test_the_form_offers_both_lists(): void
    {
        $person = $this->landlord();

        $this->get(route('finance.rental.create'))
            ->assertOk()
            ->assertSee('name="party"', escape: false)
            ->assertSee('name="subject_pick"', escape: false)
            ->assertSee($person->name());
    }

    /**
     * ⭐ তালিকা থেকে বাছলে জোড়াটা খাতায় বসে, আর নামটাও আপনা থেকে।
     */
    public function test_picking_from_the_list_writes_the_link_and_the_name(): void
    {
        $person = $this->landlord();
        $branch = Company::query()->where('code', 'TDEPOT')->firstOrFail()->defaultBranch();

        $this->post(route('finance.rental.store'), $this->terms([
            'party' => 'person:'.$person->id,
            'subject_pick' => 'branch:'.$branch->id,
        ]))->assertSessionHasNoErrors();

        $contract = RentalContract::query()->latest('id')->firstOrFail();

        $this->assertSame('person', $contract->party_type);
        $this->assertSame($person->id, (int) $contract->party_id);

        $this->assertSame('branch', $contract->subject_type);
        $this->assertSame($branch->id, (int) $contract->subject_id);

        // ⭐ নামের ঘরটা খালি রেখেও চুক্তিটা নামহীন হয় না
        $this->assertNotSame('', (string) $contract->counterparty);
    }

    /**
     * ⓘ তালিকায় নেই এমন বাড়িওয়ালা — নামটা লিখলেই চলে, আগের মতোই।
     */
    public function test_a_typed_name_still_works(): void
    {
        $this->post(route('finance.rental.store'), $this->terms([
            'counterparty' => 'আবুল কাশেম',
        ]))->assertSessionHasNoErrors();

        $contract = RentalContract::query()->latest('id')->firstOrFail();

        $this->assertSame('আবুল কাশেম', $contract->counterparty);
        $this->assertNull($contract->party_type);
        $this->assertNull($contract->subject_type);
    }

    /**
     * ⛔ নাম নেই, তালিকা থেকেও বাছা নেই — চুক্তিটা নামহীন হতে পারে না।
     */
    public function test_a_contract_without_any_name_is_refused(): void
    {
        $this->post(route('finance.rental.store'), $this->terms())
            ->assertSessionHasErrors('counterparty');
    }

    /**
     * ⭐ প্রথম দিক — চুক্তির পাতা জায়গাটা দেখায়।
     */
    public function test_the_contract_shows_its_place(): void
    {
        $branch = Company::query()->where('code', 'TDEPOT')->firstOrFail()->defaultBranch();

        $contract = $this->contractFor($branch->id);

        $this->get(route('finance.rental.show', $contract))
            ->assertOk()
            ->assertSee(__('finance::field.rental_subject_branch'));
    }

    /**
     * ⭐ উল্টো দিক — জায়গাটার দিক থেকে চুক্তিটা পাওয়া যায়।
     *
     * ⚠️ আর অন্য জায়গার চুক্তি ওখানে আসে না, নাহলে সংখ্যাটা মিথ্যা হত।
     */
    public function test_the_place_shows_its_contract(): void
    {
        $branch = Company::query()->where('code', 'TDEPOT')->firstOrFail()->defaultBranch();

        $mine = $this->contractFor($branch->id);
        $other = $this->contractFor($branch->id + 77);

        $page = $this->get(route('finance.rental.index', ['subject' => 'branch:'.$branch->id]))
            ->assertOk();

        /* ⓘ নথি নম্বরের কলাম এই তালিকায় নেই — সারি চেনা যায়
           বাড়িওয়ালার নামে, তাই দাবিটা নাম ধরেই। */
        $page->assertSee($mine->counterparty);
        $page->assertDontSee($other->counterparty);

        // ⓘ ছাঁকনিটা চুপচাপ বসে না — কোন জায়গা, সেটা পর্দায় লেখা থাকে
        $page->assertSee(__('finance::field.rental_subject_branch'));
        $page->assertSee(route('finance.rental.index'), escape: false);
    }

    /**
     * ⭐ "কার সাথে" ট্যাব — এক সারিতে একজন বাড়িওয়ালা।
     *
     * ⚠️ হাতে লেখা নামে খোলা চুক্তি এখানে গোনা হয় না — যাঁর নাম
     * তালিকায় নেই, তাঁর সারিও নেই।
     */
    public function test_the_with_whom_tab_lists_the_landlords(): void
    {
        $person = $this->landlord();

        $this->post(route('finance.rental.store'), $this->terms([
            'party' => 'person:'.$person->id,
        ]))->assertSessionHasNoErrors();

        // ⓘ দ্বিতীয়টা হাতে লেখা নামে, তাই সারিতে আসার কথা নয়
        $this->post(route('finance.rental.store'), $this->terms([
            'counterparty' => 'নাম লেখা বাড়িওয়ালা',
        ]))->assertSessionHasNoErrors();

        $page = $this->get(route('finance.rental.index', ['tab' => 'people']))->assertOk();

        $rows = $page->viewData('people');

        $this->assertCount(1, $rows);
        $this->assertSame('person', $rows[0]['party_type']);
        $this->assertSame($person->id, $rows[0]['party_id']);
        $this->assertSame(1, $rows[0]['contracts']);
        $this->assertSame(1, $rows[0]['running']);
        $this->assertSame('30000.0000', $rows[0]['rent']);

        $this->assertSame(count($rows), $page->viewData('counts')['people']);
    }

    private function landlord(): Person
    {
        return Person::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'LL-1',
            'name_en' => 'Abul Kashem',
            'name_bn' => 'আবুল কাশেম',
            'is_active' => true,
        ]);
    }

    private function contractFor(int $branchId): RentalContract
    {
        $this->post(route('finance.rental.store'), $this->terms([
            'counterparty' => 'বাড়িওয়ালা '.$branchId,
            'subject_pick' => 'branch:'.$branchId,
        ]))->assertSessionHasNoErrors();

        return RentalContract::query()->latest('id')->firstOrFail();
    }

    /**
     * চুক্তির শর্তগুলো — মালিকের গোডাউনের সংখ্যাতেই।
     *
     * @param  array<string, mixed>  $with
     * @return array<string, mixed>
     */
    private function terms(array $with = []): array
    {
        return array_merge([
            'deposit_amount' => '1200000',
            'monthly_rent' => '30000',
            'monthly_adjustment' => '10000',
            'starts_on' => now()->toDateString(),
            'term_months' => 24,
        ], $with);
    }
}
