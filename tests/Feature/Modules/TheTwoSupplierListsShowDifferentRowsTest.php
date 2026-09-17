<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ দুইটা পাতা, আর দুইটায় দুই রকম সারি — পর্দা খুলে দেখা।
 *
 * ── ⚠️ কেন আরেকটা পাহারা, একটা থাকতেই ──────────────────────────────
 * [[EverySupplierIsInExactlyOneListTest]] **ভাগের নিয়মটা** মাপে — দুইটা
 * scope ঠিক কাজ করে কি না, আর যোগফল মেলে কি না।
 *
 * ⛔ কিন্তু সে পাতাটা কোনোদিন খোলে না। ⓘ অর্থাৎ কেউ যদি কন্ট্রোলারে
 * ভুল scope বসাত — সেবাদাতার পাতায় `onlySuppliers()`, বা দুইটা পাতাতেই
 * একই — **ঐ পাহারা সবুজই থাকত**, কারণ scope দুইটা তখনো নিখুঁত।
 *
 * ⚠️ আজকের বারবার ফেরা আকৃতিটাই: পাহারা যা দেখে না, তা সে বলতেও পারে
 * না। ⭐ তাই এখানে সত্যিকারের অনুরোধ পাঠিয়ে **পাতার লেখাটা** পড়া হয়।
 */
final class TheTwoSupplierListsShowDifferentRowsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    private function typeId(string $code): int
    {
        return PartyType::query()->where('code', $code)->value('id');
    }

    /**
     * ⭐ সরবরাহকারীর পাতায় সেবাদাতা নেই, সেবাদাতার পাতায় সরবরাহকারী নেই।
     */
    public function test_each_page_shows_only_its_own_kind(): void
    {
        $vendor = app(SupplierService::class)->create([
            'name_en' => 'Asol Sorborahokari Ltd',
            'party_type_id' => $this->typeId(Supplier::VENDOR_CODE),
            'credit_limit' => 0, 'credit_days' => 0,
        ]);

        $courier = app(SupplierService::class)->create([
            'name_en' => 'Druto Courier Service',
            'party_type_id' => $this->typeId('COURIER'),
            'credit_limit' => 0, 'credit_days' => 0,
        ]);

        $suppliers = $this->actingAs($this->user)->get(route('supplier.index'))->assertOk();

        $suppliers->assertSee($vendor->name_en);
        $suppliers->assertDontSee($courier->name_en);

        $services = $this->actingAs($this->user)->get(route('supplier.service.index'))->assertOk();

        $services->assertSee($courier->name_en);
        $services->assertDontSee($vendor->name_en);
    }

    /**
     * ⛔ দুইটা পাতার শিরোনাম ও "নতুন" বোতাম আলাদা।
     *
     * ⚠️ একই পর্দা দুইবার ব্যবহার হয়, তাই স্থির লেখা থেকে গেলে সেবাদাতার
     * পাতাতেও "সরবরাহকারী" লেখা থাকত — ⓘ আর মালিক ঠিক এটাই ধরেছিলেন।
     */
    public function test_the_service_page_calls_things_by_their_own_name(): void
    {
        $services = $this->actingAs($this->user)->get(route('supplier.service.index'))->assertOk();

        $services->assertSee(__('supplier::menu.service_providers'));
        $services->assertSee(__('supplier::action.new_service'));

        /* ⓘ নতুনের লিংকটা প্রসঙ্গ বহন করে, নাহলে ফর্মে গিয়ে নামটা
           আবার "নতুন সরবরাহকারী" হয়ে যেত। */
        $services->assertSee(route('supplier.create', ['kind' => 'service']), escape: false);
    }

    /**
     * ⭐ সেবাদাতার পথে খোলা ফর্মটা নিজেকে সেবাদাতা বলে, আর ধরন খালি রাখে।
     *
     * ⚠️ ওখানে চারটা ধরন (কুরিয়ার · পরিবহন · হাম্মালি · সার্ভিস), আর
     * কোনটা তা কেবল মানুষই জানেন। ⛔ একটা আন্দাজ বসিয়ে দিলে ভুলটা
     * নীরবে সংরক্ষিত হত।
     */
    public function test_the_service_form_names_itself_and_guesses_nothing(): void
    {
        $page = $this->actingAs($this->user)
            ->get(route('supplier.create', ['kind' => 'service']))
            ->assertOk();

        $page->assertSee(__('supplier::action.new_service'));

        /*
         * ⚠️ দাবিটা **নির্দিষ্ট**, ঢালাও নয়।
         *
         * ⛔ প্রথম খসড়ায় লেখা ছিল `assertDontSee('selected')` — গোটা
         * পাতায় শব্দটা খোঁজা। ⓘ ওটা লাল হয়েছিল ঠিকই, কিন্তু ভুল কারণে:
         * পাতায় আরও ড্রপডাউন আছে (শাখা, পরিশোধের শর্ত), আর তাদের কারো
         * একটা বাছাই থাকলেই শব্দটা পাওয়া যায়।
         *
         * ⭐ এখন প্রশ্নটা ঠিক যা জানতে চাই তাই: **সরবরাহকারীর ধরনটা
         * আগে থেকে বসানো নেই তো?**
         */
        $vendorOption = 'value="'.$this->typeId(Supplier::VENDOR_CODE).'" selected';

        $this->assertStringNotContainsString($vendorOption, $page->getContent(),
            'সেবাদাতার ফর্মে ধরনটা আন্দাজ করে বসিয়ে দেওয়া হয়েছে।');
    }

    /**
     * ⛔ আর সরবরাহকারীর পথে ধরনটা আগে থেকেই বসানো থাকে।
     *
     * ⓘ মালিকের নির্দেশ: *"নতুন সরবরাহকারী → ধরন → সরবরাহকারী by default
     * বসে থাকবে"*। ⚠️ মাপা হয় **আইডি ধরে**, লেখা ধরে নয় — লেখা অনুবাদে
     * বদলায়, আইডি নয়।
     */
    public function test_the_supplier_form_preselects_the_vendor_type(): void
    {
        $page = $this->actingAs($this->user)->get(route('supplier.create'))->assertOk();

        $page->assertSee(__('supplier::action.new'));

        $expected = 'value="'.$this->typeId(Supplier::VENDOR_CODE).'" selected';

        $this->assertStringContainsString($expected, $page->getContent(),
            'ধরনের ঘরে সরবরাহকারী আগে থেকে বসানো নেই।');
    }
}
