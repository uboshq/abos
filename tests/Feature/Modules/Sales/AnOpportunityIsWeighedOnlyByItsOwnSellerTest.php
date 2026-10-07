<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\MasterData\Models\OpportunityStage;
use App\Modules\Sales\Services\LeadService;
use App\Modules\Sales\Services\OpportunityService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * সুযোগ আর পাইপলাইন — যার সুযোগ, অঙ্কটা কেবল তাঁর চোখে; NEXUS §৭।
 *
 * ── ⭐ যা দাবি করা হচ্ছে ─────────────────────────────────────────────────
 *   ⓵ পাইপলাইনের মোট ও ওজন-করা অঙ্ক bcmath-এ হুবহু, আর অন্যের সুযোগ
 *      যোগফলে লুকিয়েও ঢোকে না
 *   ⓶ বিক্রয়কর্মী অন্যের সুযোগ দেখেন না (৪০৪), একই মানুষ সবার-চাবিতে দেখেন
 *   ⓷ বিপজ্জনক ইনপুট: অন্যের লিড, অন্য কোম্পানির পণ্য, গ্রাহক-ও-লিড দুইটাই
 *   ⓸ অন্য কোম্পানির সুযোগ — ৪০৪
 *   ⓹ চাবি: **একই মানুষ**, চাবি ছাড়া ৪০৩, চাবিসহ খোলে
 *   ⓺ কোটেশনের সংযোগ কেবল জেতা সুযোগে, একবার
 *   ⓻ প্রতিটা পর্দা একটা আসল সারি নিয়ে খোলে
 *   ⓼ ডেমো বিক্রয়কর্মী সবার-দেখার চাবি পান না (ঢালাও `sales.%` ফাঁদ)
 */
final class AnOpportunityIsWeighedOnlyByItsOwnSellerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        app(OpportunityService::class)->ensureStages();
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

    private function stage(string $code): OpportunityStage
    {
        return OpportunityStage::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @param  list<array{0: string, 1: string}>  $lines  [qty, value]
     */
    private function opportunityOf(User $seller, string $stage, array $lines, ?int $probability = null,
        string $title = 'Karim Store — mustard oil'): Opportunity
    {
        return app(OpportunityService::class)->create([
            'title' => $title,
            'customer_id' => $this->customer->id,
            'stage_id' => $this->stage($stage)->id,
            'probability' => $probability,
        ], array_map(fn ($l) => [
            'product_id' => $this->product->id, 'qty' => $l[0], 'value' => $l[1],
        ], $lines), $seller);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return [
            'title' => 'New shop order',
            'customer_id' => $this->customer->id,
            'stage_id' => $this->stage('PROP')->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'value' => '1500.50']],
            ...$overrides,
        ];
    }

    // ── ⓵ পাইপলাইন ──────────────────────────────────────────────────────

    /**
     * ⭐ মোট = সারির যোগ, ওজন = মোট × সম্ভাবনা — হুবহু চার দশমিকে।
     *
     * ⓘ ১২৩৪.৫৬৭৮ × ৩৩% = ৪০৭.৪০৭৩৭৪ → ৪০৭.৪০৭৩ (ডাটাবেজ চার ঘরে কাটে)।
     */
    public function test_the_pipeline_totals_are_exact_and_weighted(): void
    {
        $seller = $this->member(['sales.opportunity.view']);

        $a = $this->opportunityOf($seller, 'PROP', [['1', '1000.0000'], ['2', '234.5678']], 33);
        $this->opportunityOf($seller, 'PROP', [['5', '500']]);   // ধাপের ৪০%
        $this->opportunityOf($seller, 'NEGO', [['1', '100']]);   // ধাপের ৭০%

        $this->assertSame('1234.5678', (string) $a->estimated_value, 'আনুমানিক অঙ্ক সারির যোগফল নয়।');

        $board = collect(app(OpportunityService::class)->pipeline($seller))
            ->keyBy(fn ($c) => $c['stage']->code);

        $this->assertSame(2, $board['PROP']['count']);
        $this->assertSame('1734.5678', $board['PROP']['total']);
        // ৪০৭.৪০৭৩৭৪ + ২০০ = ৬০৭.৪০৭৩৭৪ → চার ঘরে
        $this->assertSame(0, bccomp('607.4073', $board['PROP']['weighted'], 4),
            'ওজন-করা অঙ্ক ভুল: '.$board['PROP']['weighted']);
        $this->assertSame('70.0000', $board['NEGO']['weighted']);

        // চলমান ধাপের মাথার সংখ্যা: ৬০৭.৪০৭৩ + ৭০
        $this->assertSame(0, bccomp('677.4073', app(OpportunityService::class)->openWeighted($board->values()->all()), 4));
    }

    /**
     * ⛔ অন্যের সুযোগ যোগফলে লুকিয়েও নয় — দেয়াল যোগের আগে।
     *
     * ⭐ একই মানুষকে সবার-চাবি দিলে যোগফলে ঢোকে — অর্থাৎ বাদ পড়াটা দেয়ালের জন্য।
     */
    public function test_another_sellers_value_never_reaches_my_pipeline(): void
    {
        $karim = $this->member(['sales.opportunity.view']);
        $rahim = $this->member(['sales.opportunity.view']);

        $this->opportunityOf($karim, 'PROP', [['1', '100']]);
        $this->opportunityOf($rahim, 'PROP', [['1', '900000']], null, 'Rahim secret deal');

        $mine = collect(app(OpportunityService::class)->pipeline($karim))->keyBy(fn ($c) => $c['stage']->code);
        $this->assertSame('100.0000', $mine['PROP']['total'], '⛔ অন্যের অঙ্ক যোগফলে ঢুকেছে।');

        $this->actingAs($karim)->get(route('sales.opportunity.pipeline'))
            ->assertOk()
            ->assertSee('Karim Store — mustard oil')
            ->assertDontSee('Rahim secret deal');

        $this->grant($karim, 'sales.opportunity.manage');

        $all = collect(app(OpportunityService::class)->pipeline($karim->fresh()))->keyBy(fn ($c) => $c['stage']->code);
        $this->assertSame('900100.0000', $all['PROP']['total']);
    }

    /** ⓘ ধাপ বদলালে আর সংখ্যাটা ছোঁয়া না হলে সম্ভাবনা নতুন ধাপেরটা। */
    public function test_moving_stage_takes_the_new_stages_probability_unless_typed(): void
    {
        $seller = $this->member(['sales.opportunity.view']);
        $opportunity = $this->opportunityOf($seller, 'PROS', [['1', '100']]);
        $this->assertSame(10, $opportunity->probability);

        $this->actingAs($seller)->put(route('sales.opportunity.update', $opportunity->id), $this->form([
            'stage_id' => $this->stage('NEGO')->id,
            'probability' => '10',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(70, $opportunity->fresh()->probability);

        $this->actingAs($seller)->put(route('sales.opportunity.update', $opportunity->id), $this->form([
            'stage_id' => $this->stage('NEGO')->id,
            'probability' => '85',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(85, $opportunity->fresh()->probability);
        $this->assertSame('1500.5000', (string) $opportunity->fresh()->estimated_value);
    }

    // ── ⓶ বিক্রয়কর্মীর দেয়াল ───────────────────────────────────────────

    /**
     * ⛔ অন্যের সুযোগ — পাতা, সম্পাদনা, বদলানো, তালিকা, সব জায়গায় অদৃশ্য।
     */
    public function test_a_seller_cannot_see_another_sellers_opportunity(): void
    {
        $karim = $this->member(['sales.opportunity.view']);
        $rahim = $this->member(['sales.opportunity.view']);

        $theirs = $this->opportunityOf($rahim, 'PROP', [['1', '100']]);

        $this->actingAs($karim)->get(route('sales.opportunity.show', $theirs->id))->assertNotFound();
        $this->actingAs($karim)->get(route('sales.opportunity.edit', $theirs->id))->assertNotFound();
        $this->actingAs($karim)->put(route('sales.opportunity.update', $theirs->id), $this->form([
            'title' => 'Taken over',
        ]))->assertNotFound();

        $this->assertSame('Karim Store — mustard oil', $theirs->fresh()->title, '⛔ অন্যের সুযোগ বদলে গেছে।');

        $this->actingAs($karim)->get(route('sales.opportunity.index'))
            ->assertOk()
            ->assertDontSee($theirs->document_no);

        // ⭐ একই মানুষ, এবার সবার-দেখার চাবিসহ
        $this->grant($karim, 'sales.opportunity.manage');

        $this->actingAs($karim->fresh())->get(route('sales.opportunity.show', $theirs->id))->assertOk();
    }

    /** ⛔ চাবি ছাড়া অন্যের নামে সুযোগ বসানো যায় না। */
    public function test_a_seller_cannot_file_an_opportunity_under_someone_else(): void
    {
        $karim = $this->member(['sales.opportunity.view']);
        $rahim = $this->member(['sales.opportunity.view']);

        $this->actingAs($karim)->post(route('sales.opportunity.store'), $this->form([
            'title' => 'Pushed deal',
            'salesperson_user_id' => $rahim->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame($karim->id, Opportunity::query()->where('title', 'Pushed deal')->firstOrFail()->salesperson_user_id,
            '⛔ অন্যের নামে সুযোগ বসে গেছে।');
    }

    // ── ⓷ বিপজ্জনক ইনপুট ────────────────────────────────────────────────

    /**
     * ⛔ অন্যের লিডের id পাঠিয়ে তার নামে সুযোগ — থামে, সারি বসে না।
     *
     * ⭐ একই মানুষ সবার-লিড চাবি পেলে একই অনুরোধ চলে — থামাটা দেয়ালের জন্য।
     */
    public function test_a_seller_cannot_attach_an_opportunity_to_another_sellers_lead(): void
    {
        $karim = $this->member(['sales.opportunity.view', 'sales.lead.view']);
        $rahim = $this->member(['sales.lead.view']);

        $theirLead = app(LeadService::class)->create(['name' => 'Rahim Lead', 'source' => 'other'], $rahim);
        $before = Opportunity::query()->count();

        $this->actingAs($karim)->post(route('sales.opportunity.store'), $this->form([
            'customer_id' => null,
            'lead_id' => $theirLead->id,
        ]))->assertSessionHasErrors('lead_id');

        $this->assertSame($before, Opportunity::query()->count(), '⛔ অন্যের লিডে সুযোগ বসে গেছে।');

        $this->grant($karim, 'sales.lead.manage');

        $this->actingAs($karim->fresh())->post(route('sales.opportunity.store'), $this->form([
            'customer_id' => null,
            'lead_id' => $theirLead->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame($before + 1, Opportunity::query()->count());
    }

    /** ⛔ অন্য কোম্পানির পণ্য — যাচাই থামায়, আর সার্ভিসও থামায়। */
    public function test_another_companys_product_is_refused(): void
    {
        $seller = $this->member(['sales.opportunity.view']);
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $foreign = CompanyContext::forCompany($beta->id, function () use ($beta) {
            $copy = $this->product->replicate(['public_id']);
            $copy->company_id = $beta->id;
            $copy->code = 'FOREIGN-OPP-1';
            $copy->save();

            return $copy;
        });

        $before = Opportunity::query()->count();

        $this->actingAs($seller)->post(route('sales.opportunity.store'), $this->form([
            'lines' => [['product_id' => $foreign->id, 'qty' => '1', 'value' => '100']],
        ]))->assertSessionHasErrors('lines.0.product_id');

        // ⓘ সার্ভিসের নিজের দেয়াল — কন্ট্রোলার পাশ কাটালেও
        try {
            app(OpportunityService::class)->create([
                'title' => 'Bypass', 'customer_id' => $this->customer->id, 'stage_id' => $this->stage('PROP')->id,
            ], [['product_id' => $foreign->id, 'qty' => '1', 'value' => '100']], $seller);
            $this->fail('⛔ অন্য কোম্পানির পণ্যে সুযোগ বসে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }

        $this->assertSame($before, Opportunity::query()->count());
    }

    /** ⛔ গ্রাহক আর লিড দুইটাই — অথবা কোনোটাই নয় — থামে। */
    public function test_exactly_one_party_is_required(): void
    {
        $seller = $this->member(['sales.opportunity.view', 'sales.lead.view']);
        $lead = app(LeadService::class)->create(['name' => 'Both', 'source' => 'other'], $seller);
        $before = Opportunity::query()->count();

        $this->actingAs($seller)->post(route('sales.opportunity.store'), $this->form([
            'lead_id' => $lead->id,
        ]))->assertSessionHasErrors('customer_id');

        $this->actingAs($seller)->post(route('sales.opportunity.store'), $this->form([
            'customer_id' => null,
        ]))->assertSessionHasErrors('customer_id');

        $this->assertSame($before, Opportunity::query()->count());
    }

    /** ⛔ ঋণাত্মক অঙ্ক পাইপলাইন কমিয়ে মিথ্যা বলাত — সার্ভিস থামায়। */
    public function test_a_negative_value_is_refused_by_the_service(): void
    {
        $seller = $this->member(['sales.opportunity.view']);

        $this->expectException(ValidationException::class);

        app(OpportunityService::class)->create([
            'title' => 'Negative', 'customer_id' => $this->customer->id, 'stage_id' => $this->stage('PROP')->id,
        ], [['product_id' => $this->product->id, 'qty' => '1', 'value' => '-500']], $seller);
    }

    // ── ⓸ কোম্পানির দেয়াল ───────────────────────────────────────────────

    /**
     * ⛔ অন্য কোম্পানির সুযোগ — সুপার অ্যাডমিনের কাছেও ৪০৪।
     *
     * ⭐ একই মানুষ, কেবল কোম্পানি বদলায়: ওখানে গেলে খোলে।
     */
    public function test_another_companys_opportunity_is_not_found(): void
    {
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $foreign = CompanyContext::forCompany($beta->id, function () {
            $service = app(OpportunityService::class);
            $service->ensureStages();
            $lead = app(LeadService::class)->create(['name' => 'Foreign Lead', 'source' => 'other'], $this->owner);

            return $service->create([
                'title' => 'Foreign deal',
                'lead_id' => $lead->id,
                'stage_id' => OpportunityStage::query()->where('code', 'PROP')->firstOrFail()->id,
            ], [['product_id' => $this->betaProduct()->id, 'qty' => '1', 'value' => '100']], $this->owner);
        });

        $this->owner->switchCompany($this->company->id);
        $this->actingAs($this->owner->fresh())->get(route('sales.opportunity.show', $foreign->id))->assertNotFound();

        $this->owner->switchCompany($beta->id);
        $this->actingAs($this->owner->fresh())->get(route('sales.opportunity.show', $foreign->id))->assertOk();
    }

    /** দ্বিতীয় কোম্পানির একটা পণ্য — ডেমোতে না থাকলে প্রথমটার নকল। */
    private function betaProduct(): Product
    {
        $existing = Product::query()->orderBy('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        $copy = Product::withoutGlobalScopes()->findOrFail($this->product->id)->replicate(['public_id']);
        $copy->company_id = CompanyContext::id();
        $copy->code = 'BETA-OPP-1';
        $copy->save();

        return $copy;
    }

    // ── ⓹ চাবি — একই মানুষ দুইবার ─────────────────────────────────────

    public function test_the_opportunity_screens_need_their_key(): void
    {
        $user = $this->member([]);

        $this->actingAs($user)->get(route('sales.opportunity.index'))->assertForbidden();
        $this->actingAs($user)->get(route('sales.opportunity.pipeline'))->assertForbidden();

        $this->grant($user, 'sales.opportunity.view');

        $this->actingAs($user->fresh())->get(route('sales.opportunity.index'))->assertOk();
        $this->actingAs($user->fresh())->get(route('sales.opportunity.pipeline'))->assertOk();
    }

    /** ⛔ সুযোগ লেখার দরজাও চাবি চায় — চাবি ছাড়া সারি বসে না। */
    public function test_storing_an_opportunity_needs_the_key(): void
    {
        $user = $this->member([]);
        $before = Opportunity::query()->count();

        $this->actingAs($user)->post(route('sales.opportunity.store'), $this->form())->assertForbidden();
        $this->assertSame($before, Opportunity::query()->count());

        $this->grant($user, 'sales.opportunity.view');

        $this->actingAs($user->fresh())->post(route('sales.opportunity.store'), $this->form())->assertSessionHasNoErrors();
        $this->assertSame($before + 1, Opportunity::query()->count());
    }

    // ── ⓺ কোটেশনের সংযোগ ────────────────────────────────────────────────

    /** ⛔ চলমান সুযোগে কোটেশন নয়; জেতায় একবার, তারপর সুযোগটা তালাবদ্ধ। */
    public function test_a_quotation_links_only_to_a_won_opportunity_and_only_once(): void
    {
        $seller = $this->member(['sales.opportunity.view']);
        $service = app(OpportunityService::class);

        $open = $this->opportunityOf($seller, 'PROP', [['1', '100']]);

        try {
            $service->linkQuotation($open, 501);
            $this->fail('⛔ চলমান সুযোগে কোটেশন বসে গেছে।');
        } catch (ValidationException) {
            $this->assertNull($open->fresh()->sales_quotation_id);
        }

        $won = $this->opportunityOf($seller, 'WON', [['1', '100']]);
        $this->assertNotNull($won->closed_at, 'জেতা সুযোগ বন্ধ হিসেবে দাগানো হয়নি।');

        $service->linkQuotation($won->fresh(), 502);
        $this->assertSame(502, (int) $won->fresh()->sales_quotation_id);

        $this->expectException(ValidationException::class);
        $service->linkQuotation($won->fresh(), 503);
    }

    /**
     * ⭐ "কোটেশন বানান" — কেবল জেতা সুযোগে, আর কেবল কোটেশনের চাবিধারীকে।
     *
     * ⓘ একই মানুষ, একই সুযোগ: চাবি ছাড়া বাটন নেই, চাবিতে আছে। চলমান
     * সুযোগে চাবি থাকলেও নেই; কোটেশন বসে গেলে আর নেই।
     */
    public function test_the_create_quotation_link_shows_only_on_a_won_opportunity_for_the_key_holder(): void
    {
        $seller = $this->member(['sales.opportunity.view']);
        $won = $this->opportunityOf($seller, 'WON', [['1', '100']]);
        $open = $this->opportunityOf($seller, 'PROP', [['1', '100']]);
        $link = route('sales.quotation.create', ['opportunity' => $won->id]);

        $this->actingAs($seller)->get(route('sales.opportunity.show', $won->id))
            ->assertOk()->assertDontSee($link, false);

        $this->grant($seller, 'sales.quotation.create');
        $seller = $seller->fresh();

        $this->actingAs($seller)->get(route('sales.opportunity.show', $won->id))
            ->assertOk()->assertSee($link, false);

        $this->actingAs($seller)->get(route('sales.opportunity.show', $open->id))
            ->assertOk()->assertDontSee(route('sales.quotation.create', ['opportunity' => $open->id]), false);

        app(OpportunityService::class)->linkQuotation($won->fresh(), 900);

        $this->actingAs($seller)->get(route('sales.opportunity.show', $won->id))
            ->assertOk()->assertDontSee($link, false);
    }

    /** ⛔ কোটেশন হয়ে গেলে সম্পাদনার পাতা ৪০৪, আর বদলানো থামে। */
    public function test_an_opportunity_with_a_quotation_is_locked(): void
    {
        $seller = $this->member(['sales.opportunity.view']);
        $won = $this->opportunityOf($seller, 'WON', [['1', '100']]);
        app(OpportunityService::class)->linkQuotation($won->fresh(), 777);

        $this->actingAs($seller)->get(route('sales.opportunity.edit', $won->id))->assertNotFound();
        $this->actingAs($seller)->put(route('sales.opportunity.update', $won->id), $this->form([
            'title' => 'Changed after quotation',
        ]))->assertSessionHasErrors('stage_id');

        $this->assertSame('Karim Store — mustard oil', $won->fresh()->title);
    }

    /** ⛔ একটা ধাপ একসাথে জেতা আর হারা — মডেল থামায়। */
    public function test_a_stage_cannot_be_both_won_and_lost(): void
    {
        $this->expectException(ValidationException::class);

        OpportunityStage::create([
            'code' => 'BOTH', 'name_en' => 'Both', 'probability' => 50,
            'is_won' => true, 'is_lost' => true,
        ]);
    }

    // ── লিড থেকে গ্রাহক হলে সুযোগও গ্রাহকের ───────────────────────────

    public function test_converting_a_lead_hands_its_open_opportunities_to_the_customer(): void
    {
        $manager = $this->member(['sales.lead.view', 'sales.lead.manage', 'customer.create', 'sales.opportunity.view']);
        $lead = app(LeadService::class)->create(['name' => 'Moving Store', 'phone' => '01711222333', 'source' => 'other'], $manager);

        $opportunity = app(OpportunityService::class)->create([
            'title' => 'From lead', 'lead_id' => $lead->id, 'stage_id' => $this->stage('PROP')->id,
        ], [['product_id' => $this->product->id, 'qty' => '1', 'value' => '100']], $manager);

        $this->assertNull($opportunity->customer_id);

        $this->actingAs($manager)->post(route('sales.lead.convert', $lead->id), ['name_en' => 'Moving Store'])
            ->assertSessionHasNoErrors();

        $customerId = $lead->fresh()->customer_id;
        $this->assertNotNull($customerId);
        $this->assertSame($customerId, $opportunity->fresh()->customer_id);
        $this->assertSame($lead->id, $opportunity->fresh()->lead_id, 'লিডের ইতিহাস মুছে গেছে।');

        // ⓘ নতুন গ্রাহকের বাকির সীমা শূন্য — তৈরির পথে সীমা বসে না (অডিট §১.২)
        $this->assertSame(0, bccomp('0', (string) (Customer::query()->findOrFail($customerId)->credit_limit ?? '0'), 4));
    }

    // ── ⓻ প্রতিটা পর্দা, আসল সারিসহ ─────────────────────────────────────

    public function test_every_screen_opens_with_a_real_row(): void
    {
        $user = $this->member(['sales.lead.view', 'sales.opportunity.view']);

        $lead = app(LeadService::class)->create(['name' => 'Screen Lead', 'source' => 'field_visit'], $user);
        $opportunity = $this->opportunityOf($user, 'PROP', [['3', '450']]);

        $this->actingAs($user)->get(route('sales.lead.index'))->assertOk()->assertSee($lead->document_no);
        $this->actingAs($user)->get(route('sales.lead.create'))->assertOk();
        $this->actingAs($user)->get(route('sales.lead.show', $lead->id))->assertOk()->assertSee('Screen Lead');
        $this->actingAs($user)->get(route('sales.lead.edit', $lead->id))->assertOk()->assertSee('Screen Lead');

        $this->actingAs($user)->get(route('sales.opportunity.index'))->assertOk()->assertSee($opportunity->document_no);
        $this->actingAs($user)->get(route('sales.opportunity.pipeline'))->assertOk()->assertSee('Karim Store — mustard oil');
        $this->actingAs($user)->get(route('sales.opportunity.create'))->assertOk();
        $this->actingAs($user)->get(route('sales.opportunity.create', ['lead' => $lead->id]))->assertOk();
        $this->actingAs($user)->get(route('sales.opportunity.show', $opportunity->id))->assertOk()->assertSee($opportunity->document_no);
        $this->actingAs($user)->get(route('sales.opportunity.edit', $opportunity->id))->assertOk();
    }

    // ── ⓼ ঢালাও `sales.%` ফাঁদ ─────────────────────────────────────────

    /**
     * ⛔ ডেমোর বিক্রয়কর্মী লিড ও সুযোগ দেখেন, কিন্তু সবার-দেখার চাবি পান না।
     *
     * ⓘ সিডারের ঢালাও `sales.%` নিয়ম নতুন ঘোষিত প্রতিটা চাবি দিয়ে দেয় —
     * `manage` পেলে তিনি সবার লিড দেখতেন, মালিকের ২৬ সেপ্টেম্বরের নিয়ম ভেঙে।
     */
    public function test_the_demo_salesman_sees_only_his_own(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        CompanyContext::forCompany($this->company->id, function () use ($salesman) {
            $this->assertTrue($salesman->can('sales.lead.view'));
            $this->assertTrue($salesman->can('sales.opportunity.view'));
            $this->assertFalse($salesman->can('sales.lead.manage'), '⛔ বিক্রয়কর্মী সবার লিড দেখার চাবি পেয়েছেন।');
            $this->assertFalse($salesman->can('sales.opportunity.manage'), '⛔ বিক্রয়কর্মী সবার সুযোগ দেখার চাবি পেয়েছেন।');
        });
    }
}
