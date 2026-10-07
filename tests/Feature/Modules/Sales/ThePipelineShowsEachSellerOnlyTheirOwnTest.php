<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
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
 * সুযোগ ও পাইপলাইন — প্রত্যেকে কেবল নিজের অঙ্ক দেখেন।
 *
 * ── ⭐ মালিকের নিয়ম, ২৬ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * বিক্রয়কর্মী কেবল নিজেরটা দেখেন। ⚠️ পাইপলাইনে দেয়ালটা **যোগফলেও**
 * খাটে: সারি লুকিয়ে যোগে অন্যের অঙ্ক ধরে রাখলে সংখ্যাটাই খবর ফাঁস করত।
 */
final class ThePipelineShowsEachSellerOnlyTheirOwnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();

        app(OpportunityService::class)->ensureStages();
    }

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

    private function opportunityOf(User $seller, string $value, int $probability, string $stage = 'PROP'): Opportunity
    {
        return app(OpportunityService::class)->create([
            'title' => 'Eid stock',
            'customer_id' => $this->customer->id,
            'stage_id' => $this->stage($stage)->id,
            'probability' => $probability,
        ], [
            ['product_id' => $this->product->id, 'qty' => '10', 'value' => $value],
        ], $seller);
    }

    /** @param  list<array<string, mixed>>  $board */
    private function column(array $board, string $code): array
    {
        foreach ($board as $column) {
            if ($column['stage']->code === $code) {
                return $column;
            }
        }

        $this->fail("পাইপলাইনে {$code} ধাপই নেই — দাবিটা কিছু মাপছে না।");
    }

    // ── অঙ্ক ─────────────────────────────────────────────────────────────

    /** ⭐ আনুমানিক অঙ্ক সারিগুলোর যোগ, আর ওজন = অঙ্ক × সম্ভাবনা — দশমিকে। */
    public function test_the_value_is_the_sum_of_the_lines_and_the_weight_is_exact(): void
    {
        $seller = $this->member(['sales.opportunity.view']);

        $opportunity = app(OpportunityService::class)->create([
            'title' => 'Two lines',
            'customer_id' => $this->customer->id,
            'stage_id' => $this->stage('PROP')->id,
            'probability' => 33,
        ], [
            ['product_id' => $this->product->id, 'qty' => '1', 'value' => '1000.10'],
            ['product_id' => $this->product->id, 'qty' => '2', 'value' => '0.20'],
        ], $seller);

        $this->assertSame('1000.3000', (string) $opportunity->estimated_value);
        $this->assertSame('330.0990', $opportunity->weightedValue());
    }

    /** ⓘ সম্ভাবনা না দিলে ধাপেরটা বসে। */
    public function test_a_blank_probability_takes_the_stages(): void
    {
        $seller = $this->member(['sales.opportunity.view']);

        $opportunity = app(OpportunityService::class)->create([
            'title' => 'No guess',
            'customer_id' => $this->customer->id,
            'stage_id' => $this->stage('NEGO')->id,
        ], [['product_id' => $this->product->id, 'qty' => '1', 'value' => '100']], $seller);

        $this->assertSame(70, $opportunity->probability);
    }

    // ── দেয়াল ────────────────────────────────────────────────────────────

    /**
     * ⛔ পাইপলাইনের যোগফলে অন্যের অঙ্ক নেই।
     *
     * ⭐ একই মানুষকে সবার-দেখার চাবি দিলে দুইজনেরটা মিলে আসে — অর্থাৎ
     * আগের ছোট সংখ্যাটা দেয়ালের কারণে, ভুল যোগের নয়।
     */
    public function test_the_pipeline_counts_only_the_sellers_own_money(): void
    {
        $karim = $this->member(['sales.opportunity.view']);
        $rahim = $this->member(['sales.opportunity.view']);

        $this->opportunityOf($karim, '1000', 50);
        $this->opportunityOf($rahim, '3000', 50);

        $mine = $this->column(app(OpportunityService::class)->pipeline($karim), 'PROP');

        $this->assertSame(1, $mine['count']);
        $this->assertSame('1000.0000', $mine['total'], '⛔ অন্যের অঙ্ক যোগে ঢুকে গেছে।');
        $this->assertSame('500.0000', $mine['weighted']);

        $this->grant($karim, 'sales.opportunity.manage');

        $all = $this->column(app(OpportunityService::class)->pipeline($karim->fresh()), 'PROP');

        $this->assertSame(2, $all['count']);
        $this->assertSame('4000.0000', $all['total']);
        $this->assertSame('2000.0000', $all['weighted']);
    }

    /** ⛔ অন্যের সুযোগ — ৪০৪; একই মানুষ চাবি পেলে খোলে। */
    public function test_another_sellers_opportunity_is_not_found(): void
    {
        $karim = $this->member(['sales.opportunity.view']);
        $rahim = $this->member(['sales.opportunity.view']);

        $theirs = $this->opportunityOf($rahim, '3000', 50);

        $this->actingAs($karim)->get(route('sales.opportunity.show', $theirs->id))->assertNotFound();
        $this->actingAs($karim)->get(route('sales.opportunity.index'))->assertOk()->assertDontSee($theirs->document_no);
        $this->actingAs($karim)->get(route('sales.opportunity.pipeline'))->assertOk()->assertDontSee($theirs->document_no);

        $this->grant($karim, 'sales.opportunity.manage');

        $this->actingAs($karim->fresh())->get(route('sales.opportunity.show', $theirs->id))->assertOk();
    }

    /**
     * ⛔ বিপজ্জনক ইনপুট: অন্যের লিডের id পাঠিয়ে তার নামে সুযোগ।
     *
     * ⓘ ফর্মের তালিকায় ঐ লিড থাকে না, কিন্তু id হাতে লিখে পাঠানো যায় —
     * দেয়াল তাই সার্ভিসে।
     */
    public function test_an_opportunity_cannot_be_hung_on_another_sellers_lead(): void
    {
        $karim = $this->member(['sales.opportunity.view', 'sales.lead.view']);
        $rahim = $this->member(['sales.lead.view']);

        $theirLead = app(LeadService::class)->create(['name' => 'Rahim Prospect', 'source' => 'other'], $rahim);

        $this->actingAs($karim)->post(route('sales.opportunity.store'), [
            'title' => 'Borrowed',
            'lead_id' => $theirLead->id,
            'stage_id' => $this->stage('PROS')->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '1', 'value' => '10']],
        ])->assertSessionHasErrors('lead_id');

        $this->assertSame(0, Opportunity::query()->where('lead_id', $theirLead->id)->count());
    }

    /** ⛔ গ্রাহক আর লিড দুইটাই, অথবা কোনোটাই না — দুইটাই থামে। */
    public function test_exactly_one_party_is_required(): void
    {
        $seller = $this->member(['sales.opportunity.view', 'sales.lead.view']);
        $lead = app(LeadService::class)->create(['name' => 'Own Prospect', 'source' => 'other'], $seller);

        foreach ([
            ['customer_id' => $this->customer->id, 'lead_id' => $lead->id],
            [],
        ] as $party) {
            try {
                app(OpportunityService::class)->create([
                    'title' => 'Ambiguous',
                    'stage_id' => $this->stage('PROS')->id,
                    ...$party,
                ], [['product_id' => $this->product->id, 'qty' => '1', 'value' => '1']], $seller);

                $this->fail('দুইটা বা শূন্য পক্ষ নিয়ে সুযোগ বসে গেছে।');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('customer_id', $e->errors());
            }
        }
    }

    /** ⭐ লিড গ্রাহক হলে তার সুযোগও গ্রাহকের — লিডের সংযোগ থেকে যায়। */
    public function test_converting_a_lead_hands_its_opportunities_to_the_customer(): void
    {
        $manager = $this->member(['sales.opportunity.view', 'sales.lead.view', 'sales.lead.manage', 'customer.create']);
        $lead = app(LeadService::class)->create(['name' => 'Soon Customer', 'phone' => '01822333444', 'source' => 'other'], $manager);

        $opportunity = app(OpportunityService::class)->create([
            'title' => 'First order',
            'lead_id' => $lead->id,
            'stage_id' => $this->stage('PROS')->id,
        ], [['product_id' => $this->product->id, 'qty' => '1', 'value' => '500']], $manager);

        $this->actingAs($manager);
        $customer = app(LeadService::class)->convert($lead, ['name_en' => 'Soon Customer'], $manager);

        $opportunity->refresh();
        $this->assertSame($customer->id, $opportunity->customer_id);
        $this->assertSame($lead->id, $opportunity->lead_id, 'লিডের ইতিহাস মুছে গেছে।');
    }

    /** ⛔ জেতা আর হারা একই ধাপে — থামে। */
    public function test_a_stage_cannot_be_both_won_and_lost(): void
    {
        $this->expectException(ValidationException::class);

        $this->stage('WON')->update(['is_lost' => true]);
    }

    /** ⭐ কোটেশন কেবল জেতা সুযোগে, আর একবারই। */
    public function test_a_quotation_links_only_to_a_won_opportunity_once(): void
    {
        $seller = $this->member(['sales.opportunity.view']);
        $service = app(OpportunityService::class);

        $open = $this->opportunityOf($seller, '100', 40, 'PROP');

        try {
            $service->linkQuotation($open, 77);
            $this->fail('চলমান সুযোগে কোটেশন বসে গেছে।');
        } catch (ValidationException) {
            $this->assertNull($open->fresh()->sales_quotation_id);
        }

        $won = $this->opportunityOf($seller, '100', 100, 'WON');
        $this->assertNotNull($won->closed_at, 'জেতা সুযোগ বন্ধ হিসেবে দাগ পায়নি।');

        $service->linkQuotation($won->fresh(), 77);
        $this->assertSame(77, (int) $won->fresh()->sales_quotation_id);

        $this->expectException(ValidationException::class);
        $service->linkQuotation($won->fresh(), 78);
    }

    // ── কোম্পানি ও চাবি ─────────────────────────────────────────────────

    /** ⛔ অন্য কোম্পানির সুযোগ — ৪০৪, মালিক দুই কোম্পানিতে থাকলেও। */
    public function test_another_companys_opportunity_is_not_found(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $foreign = CompanyContext::forCompany($beta->id, function () use ($owner) {
            app(OpportunityService::class)->ensureStages();
            $customer = app(\App\Modules\Customer\Services\CustomerService::class)->create([
                'name_en' => 'Beta Buyer', 'opening_balance' => '0',
            ]);
            /*
             * ⓘ সারি সরাসরি — ফ্যামিলি মার্টে ডেমো পণ্য নেই, আর এই দাবি
             * লেখার পথ নয়, দেখার দেয়াল মাপে।
             */
            return Opportunity::create([
                'document_no' => 'OPP-BETA-1',
                'title' => 'Beta deal',
                'customer_id' => $customer->id,
                'salesperson_user_id' => $owner->id,
                'stage_id' => OpportunityStage::query()->where('code', 'PROS')->value('id'),
                'estimated_value' => '1',
                'probability' => 10,
            ]);
        });

        $owner->switchCompany($this->company->id);

        $this->actingAs($owner->fresh())->get(route('sales.opportunity.show', $foreign->id))->assertNotFound();

        $owner->switchCompany($beta->id);

        $this->actingAs($owner->fresh())->get(route('sales.opportunity.show', $foreign->id))->assertOk();
    }

    public function test_the_pipeline_needs_its_key(): void
    {
        $user = $this->member([]);

        $this->actingAs($user)->get(route('sales.opportunity.pipeline'))->assertForbidden();

        $this->grant($user, 'sales.opportunity.view');

        $this->actingAs($user->fresh())->get(route('sales.opportunity.pipeline'))->assertOk();
    }

    /** ⓘ ফর্ম খোলে, আর ধাপগুলো বসানো থাকে — খালি তালিকায় সুযোগ লেখাই যেত না। */
    public function test_the_form_opens_with_stages(): void
    {
        $seller = $this->member(['sales.opportunity.view']);

        $this->actingAs($seller)->get(route('sales.opportunity.create'))
            ->assertOk()
            ->assertSee($this->stage('PROS')->name());
    }

    /** ⭐ HTTP দিয়ে লেখা — সারিসহ, আর বিক্রয়কর্মী নিজেই। */
    public function test_a_seller_writes_an_opportunity_through_the_screen(): void
    {
        $seller = $this->member(['sales.opportunity.view']);

        $this->actingAs($seller)->post(route('sales.opportunity.store'), [
            'title' => 'From the screen',
            'customer_id' => $this->customer->id,
            'stage_id' => $this->stage('PROP')->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => '5', 'value' => '250'],
                ['product_id' => '', 'qty' => '', 'value' => ''],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $opportunity = Opportunity::query()->where('title', 'From the screen')->firstOrFail();

        $this->assertSame($seller->id, $opportunity->salesperson_user_id);
        $this->assertSame(1, $opportunity->lines()->count(), 'ফাঁকা সারিটা বসে গেছে।');
        $this->assertSame('250.0000', (string) $opportunity->estimated_value);

        $this->actingAs($seller)->get(route('sales.opportunity.show', $opportunity->id))->assertOk();
        $this->actingAs($seller)->get(route('sales.opportunity.pipeline'))->assertOk()->assertSee('From the screen');
    }
}
