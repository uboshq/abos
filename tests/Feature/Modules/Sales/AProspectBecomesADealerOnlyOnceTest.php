<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Services\LeadService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * লিড গ্রাহক হয় ঠিক একবার, আর কেবল গ্রাহকের নিজের দরজা দিয়ে — NEXUS §৭।
 *
 * ── ⭐ যা দাবি করা হচ্ছে ─────────────────────────────────────────────────
 *   ⓵ রূপান্তরে **একজন** গ্রাহক, আর দ্বিতীয়বার চাপলে দ্বিতীয়জন নয়
 *   ⓶ গ্রাহক তৈরি হয় [[CustomerService]] দিয়ে — প্রমাণ: ঐ সার্ভিসের ফোন-
 *      পাহারা এখানেও থামায় (সরাসরি সারি বসালে থামত না)
 *   ⓷ একজন বিক্রয়কর্মী অন্যের লিড দেখেন না — ৪০৪
 *   ⓸ অন্য কোম্পানির লিড — ৪০৪
 *   ⓹ চাবি: **একই মানুষ**, চাবি ছাড়া ৪০৩, চাবিসহ খোলে
 *
 * ⓘ দরজার দাবিগুলো HTTP দিয়ে, কারণ দেয়ালটা কন্ট্রোলারের খোঁজায় বসে।
 */
final class AProspectBecomesADealerOnlyOnceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @param  list<string>  $keys */
    private function member(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        foreach ($keys as $key) {
            $this->grant($user, $key);
        }

        return $user->fresh();
    }

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function leadOf(User $owner, string $phone = '01711000111'): Lead
    {
        return app(LeadService::class)->create([
            'name' => 'Karim Store',
            'phone' => $phone,
            'source' => 'field_visit',
        ], $owner);
    }

    private function convert(User $actor, Lead $lead)
    {
        return $this->actingAs($actor)->post(route('sales.lead.convert', $lead->id), [
            'name_en' => 'Karim Store',
        ]);
    }

    // ── ⓵ ⓶ রূপান্তর ────────────────────────────────────────────────────

    /**
     * ⭐ একবার রূপান্তর — একজন গ্রাহক, লিডে তার সংযোগ, লিডের ফোনসহ।
     */
    public function test_converting_makes_exactly_one_customer_and_links_it_back(): void
    {
        $manager = $this->member(['sales.lead.view', 'sales.lead.manage', 'customer.create']);
        $lead = $this->leadOf($manager);

        $before = Customer::query()->count();

        $this->convert($manager, $lead)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($before + 1, Customer::query()->count(), 'ঠিক একজন গ্রাহক তৈরি হয়নি।');

        $lead->refresh();
        $customer = Customer::query()->findOrFail($lead->customer_id);

        $this->assertSame(Lead::CONVERTED, $lead->status);
        $this->assertSame('01711000111', $customer->phone, 'লিডের ফোন গ্রাহকে যায়নি।');
        $this->assertSame($manager->id, $lead->converted_by);
        $this->assertNotNull($lead->converted_at);

        // ⓘ কোডটা সিরিজ থেকে — সার্ভিসের কাজ; হাতে বসানো সারিতে কোড থাকত না
        $this->assertNotEmpty($customer->code);
    }

    /**
     * ⛔ দ্বিতীয়বার চাপলে দ্বিতীয় গ্রাহক নয় — থামে, আর গোনা একই থাকে।
     */
    public function test_converting_twice_is_refused_and_makes_no_second_customer(): void
    {
        $manager = $this->member(['sales.lead.view', 'sales.lead.manage', 'customer.create']);
        $lead = $this->leadOf($manager);

        $this->convert($manager, $lead)->assertSessionHasNoErrors();
        $after = Customer::query()->count();
        $first = $lead->fresh()->customer_id;

        $this->convert($manager->fresh(), $lead)->assertSessionHasErrors('lead');

        $this->assertSame($after, Customer::query()->count(), '⛔ দ্বিতীয় রূপান্তরে আরেকজন গ্রাহক তৈরি হয়েছে।');
        $this->assertSame($first, $lead->fresh()->customer_id, 'লিডের গ্রাহক বদলে গেছে।');
    }

    /**
     * ⭐ বিপজ্জনক ইনপুট: একই ফোনে আগে থেকেই গ্রাহক।
     *
     * ⓘ ফোন-পাহারা থাকে কেবল [[CustomerService::create()]]-এ। রূপান্তর
     * সরাসরি সারি বসালে এখানে দ্বিতীয় গ্রাহক বসে যেত — তাই এই থামাটাই
     * প্রমাণ যে পথটা সার্ভিস দিয়ে যায়।
     */
    public function test_conversion_goes_through_the_customer_service_and_its_phone_guard(): void
    {
        $manager = $this->member(['sales.lead.view', 'sales.lead.manage', 'customer.create']);

        $existing = Customer::query()->orderBy('id')->firstOrFail();
        $existing->forceFill(['phone' => '01799888777'])->save();

        $lead = $this->leadOf($manager, '01799888777');
        $before = Customer::query()->count();

        $this->convert($manager, $lead)->assertSessionHasErrors('phone');

        $this->assertSame($before, Customer::query()->count(), '⛔ একই ফোনে দ্বিতীয় গ্রাহক বসেছে — সার্ভিসের পাহারা পাশ কাটানো হয়েছে।');
        $this->assertNull($lead->fresh()->customer_id);
        $this->assertNotSame(Lead::CONVERTED, $lead->fresh()->status);
    }

    /** ⛔ হাতে "গ্রাহক হয়েছেন" বসানো যায় না — নাহলে গ্রাহক ছাড়াই লিড বন্ধ হত। */
    public function test_converted_cannot_be_set_by_hand(): void
    {
        $seller = $this->member(['sales.lead.view']);
        $lead = $this->leadOf($seller);

        $this->actingAs($seller)->put(route('sales.lead.update', $lead->id), [
            'name' => 'Karim Store',
            'source' => 'field_visit',
            'status' => Lead::CONVERTED,
        ])->assertSessionHasErrors('status');

        $this->assertSame(Lead::NEW, $lead->fresh()->status);
    }

    // ── ⓷ বিক্রয়কর্মীর দেয়াল ───────────────────────────────────────────

    /**
     * ⛔ অন্যের লিড — পাতা, সম্পাদনা, তালিকা, সব জায়গায় অদৃশ্য।
     *
     * ⭐ তারপর **একই মানুষকে** সবার-দেখার চাবি দিলে খোলে — অর্থাৎ ৪০৪-টা
     * দেয়ালের কারণে, অন্য কিছুর নয়।
     */
    public function test_a_salesperson_cannot_see_another_salespersons_lead(): void
    {
        $karim = $this->member(['sales.lead.view']);
        $rahim = $this->member(['sales.lead.view']);

        $theirs = $this->leadOf($rahim);

        $this->actingAs($karim)->get(route('sales.lead.show', $theirs->id))->assertNotFound();
        $this->actingAs($karim)->get(route('sales.lead.edit', $theirs->id))->assertNotFound();
        $this->actingAs($karim)->put(route('sales.lead.update', $theirs->id), [
            'name' => 'Taken over',
            'source' => 'other',
        ])->assertNotFound();

        $this->assertSame('Karim Store', $theirs->fresh()->name, '⛔ অন্যের লিড বদলে গেছে।');

        $this->actingAs($karim)->get(route('sales.lead.index'))
            ->assertOk()
            ->assertDontSee($theirs->document_no);

        // ⭐ একই মানুষ, এবার সবার-দেখার চাবিসহ
        $this->grant($karim, 'sales.lead.manage');

        $this->actingAs($karim->fresh())->get(route('sales.lead.show', $theirs->id))->assertOk();
    }

    /** ⛔ চাবি ছাড়া অন্যের নামে লিড বসানো যায় না — মালিক নিজেই হন। */
    public function test_a_salesperson_cannot_file_a_lead_under_someone_else(): void
    {
        $karim = $this->member(['sales.lead.view']);
        $rahim = $this->member(['sales.lead.view']);

        $this->actingAs($karim)->post(route('sales.lead.store'), [
            'name' => 'Pushed Store',
            'source' => 'referral',
            'owner_user_id' => $rahim->id,
        ])->assertRedirect();

        $lead = Lead::query()->where('name', 'Pushed Store')->firstOrFail();

        $this->assertSame($karim->id, $lead->owner_user_id, '⛔ অন্যের নামে লিড বসে গেছে।');
    }

    // ── ⓸ কোম্পানির দেয়াল ───────────────────────────────────────────────

    /**
     * ⛔ অন্য কোম্পানির লিড — সবার-দেখার চাবিধারীর কাছেও ৪০৪।
     *
     * ⭐ মালিক দুই কোম্পানিতেই আছেন: ট্রেড ডিপোতে বসে ৪০৪, ফ্যামিলি
     * মার্টে গেলে খোলে — একই মানুষ, কেবল কোম্পানি বদলায়।
     */
    public function test_another_companys_lead_is_not_found(): void
    {
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $foreign = CompanyContext::forCompany($beta->id, fn () => app(LeadService::class)->create([
            'name' => 'Foreign Store',
            'source' => 'other',
        ], $this->owner));

        $this->owner->switchCompany($this->company->id);

        $this->actingAs($this->owner->fresh())->get(route('sales.lead.show', $foreign->id))->assertNotFound();

        $this->owner->switchCompany($beta->id);

        $this->actingAs($this->owner->fresh())->get(route('sales.lead.show', $foreign->id))->assertOk();
    }

    // ── ⓹ চাবি — একই মানুষ দুইবার ─────────────────────────────────────

    public function test_the_lead_screen_needs_its_key(): void
    {
        $user = $this->member([]);

        $this->actingAs($user)->get(route('sales.lead.index'))->assertForbidden();

        $this->grant($user, 'sales.lead.view');

        $this->actingAs($user->fresh())->get(route('sales.lead.index'))->assertOk();
    }

    /**
     * ⛔ রূপান্তরের চাবি — লিড দেখার চাবি দিয়ে গ্রাহক বানানো যায় না।
     *
     * ⓘ একই মানুষ, একই লিড: আগে ৪০৩ আর গ্রাহক শূন্য; চাবি পেলে গ্রাহক।
     */
    public function test_converting_needs_the_manage_key(): void
    {
        $user = $this->member(['sales.lead.view', 'customer.create']);
        $lead = $this->leadOf($user);
        $before = Customer::query()->count();

        $this->convert($user, $lead)->assertForbidden();
        $this->assertSame($before, Customer::query()->count());

        $this->grant($user, 'sales.lead.manage');

        $this->convert($user->fresh(), $lead)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before + 1, Customer::query()->count());
    }

    /** ⛔ লিডের চাবি গ্রাহক তালিকার দরজা খোলে না — `customer.create`-ও লাগে। */
    public function test_converting_also_needs_the_customer_key(): void
    {
        $user = $this->member(['sales.lead.view', 'sales.lead.manage']);
        $lead = $this->leadOf($user);
        $before = Customer::query()->count();

        $this->convert($user, $lead)->assertForbidden();
        $this->assertSame($before, Customer::query()->count());

        $this->grant($user, 'customer.create');

        $this->convert($user->fresh(), $lead)->assertSessionHasNoErrors();
        $this->assertSame($before + 1, Customer::query()->count());
    }
}
