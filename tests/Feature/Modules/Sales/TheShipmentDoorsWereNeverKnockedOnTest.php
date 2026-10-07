<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\DocumentStatus;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\ShipmentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গাড়ির ট্রিপের পাঁচটা দরজার একটাতেও কেউ কোনোদিন কড়া নাড়েনি।
 *
 * ── ⛔ কেন এই ফাইলটা, ২৬ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * বিক্রয়ের চেকলিস্টে ১০৪টা রুট একে একে খুঁজে দেখা গেছে **৩৭টা দরজা
 * কোনো পরীক্ষা নাম ধরে ডাকে না** (`de052cdc`)। ⓘ শিপমেন্টে **১০টার ৫টা**:
 * `store` · `update` · `edit` · `close` · `cancel`।
 *
 * ⓘ কাজটা খুব ভালোভাবেই মাপা — `AVanGoesOutAndMustComeBackTest`-এ **২৩টা
 * দাবি**। ⚠️ কিন্তু সবগুলোই `app(ShipmentService::class)` ধরে; দরজার
 * দিকে কেবল `index` · `show` · `dispatch` · `settle`।
 *
 * ── ⚠️ এখানেও দুইটা আলাদা চাবি, আর সেটাই আসল কথা ─────────────────────
 * ```
 * dispatch · settle · close → sales.shipment.create
 * cancel                    → sales.shipment.cancel   (আলাদা, ইচ্ছাকৃত)
 * ```
 * ⭐ ট্রিপ চালানো আর ট্রিপ **মুছে ফেলা** এক জিনিস নয়। ⛔ কিন্তু কোনো
 * পরীক্ষা এই ভাগটা মাপত না, তাই দুইটা এক করে ফেললেও কোথাও লাল হত না।
 *
 * ⚠️ কী হারাত: যিনি গাড়ি পাঠান, তিনিই একটা বেরিয়ে যাওয়া ট্রিপ বাতিল
 * করে দিতে পারতেন — মাল বাইরে, কাগজে কিছু নেই।
 *
 * ── ⭐ নকশা: ভূমিকাহীন লোক, চাবি দাবির ভিতরে ─────────────────────────
 * ⓘ কোনো ডেমো-ভূমিকা ধার করা হয় না — মালিকের সিদ্ধান্তে ভূমিকার ছাঁচ
 * বদলাচ্ছে, তাই ভূমিকার উপর দাঁড়ানো দাবি কাল **ভুল কারণে** লাল হত
 * ([[same-user-key-off-then-on]])।
 */
final class TheShipmentDoorsWereNeverKnockedOnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $outsider;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        /* ⭐ ভূমিকাহীন — কারো ভূমিকা ধার করা হয় না, উপরের ব্লকে কেন */
        $this->outsider = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->outsider->companies()->attach($this->company->id, ['is_active' => true]);
    }

    /**
     * ⛔ চাবি ছাড়া গাড়ি ছাড়া যায় না — আর ট্রিপটা খসড়াই থাকে।
     *
     * ⚠️ দ্বিতীয় দাবিটাই আসল: ৪০৩ ফেরত দিয়েও যদি ট্রিপ বেরিয়ে যেত,
     * তবে মাল গুদাম ছেড়ে যেত আর কাগজে কেউ দায়ী থাকত না।
     */
    public function test_a_trip_cannot_be_dispatched_without_the_key(): void
    {
        $trip = $this->draftTrip();

        $this->assertFalse($this->outsider->can('sales.shipment.create'),
            'ⓘ এই দাবির ভিত্তি: ভূমিকাহীন ব্যবহারকারীর ট্রিপের চাবি নেই। '
            .'⛔ থাকলে নিচের ৪০৩ কিছুই প্রমাণ করত না।');

        $this->actingAs($this->outsider)
            ->post(route('sales.shipment.dispatch', $trip))
            ->assertForbidden();

        $this->assertTrue($trip->fresh()->isDraft(),
            '⛔ ৪০৩ ফিরেছে, তবু ট্রিপটা বেরিয়ে গেছে।');
    }

    /** ⭐ চাবিটাই দরজা খোলে — আর খুলে গাড়ি সত্যিই ছাড়ে। */
    public function test_the_key_is_what_opens_the_dispatch_door(): void
    {
        $trip = $this->draftTrip();

        $this->outsider->givePermissionTo('sales.shipment.create');

        /* ⚠️ যাচাই-ব্যর্থতাও ৩০২, তাই আলাদা করে দেখা */
        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.shipment.dispatch', $trip))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(DocumentStatus::CONFIRMED, $trip->fresh()->status,
            '⛔ দরজা খুলেছে, কিন্তু ট্রিপটা খসড়াই — ৩০২ এসেছে, কাজ হয়নি।');
    }

    /**
     * ⛔ ট্রিপ চালানোর চাবি দিয়ে সেটা **বাতিল** করা যায় না।
     *
     * ⭐ এই ফাইলের সবচেয়ে জরুরি দাবি — ফেরতের দুই-চাবির দাবিটার জোড়া।
     * ⓘ দুইটা চাবি আলাদা রাখা নকশা, আর এই দাবিটা ছাড়া কেউ দুইটা এক
     * করে ফেললে কোথাও লাল হত না।
     */
    public function test_the_dispatch_key_does_not_open_the_cancel_door(): void
    {
        $trip = $this->draftTrip();

        $this->outsider->givePermissionTo('sales.shipment.create');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.shipment.cancel', $trip), ['reason' => 'গাড়ি নষ্ট'])
            ->assertForbidden();

        $this->assertFalse($trip->fresh()->isCancelled(),
            '⛔ ট্রিপ চালানোর চাবি দিয়েই ট্রিপটা বাতিল হয়ে গেছে — '
            .'দুইটা চাবি আলাদা রাখার কোনো মানে থাকল না।');
    }

    /** ⭐ বাতিলের নিজের চাবিতে বাতিল হয়। */
    public function test_the_cancel_key_is_what_opens_the_cancel_door(): void
    {
        $trip = $this->draftTrip();

        $this->outsider->givePermissionTo('sales.shipment.cancel');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.shipment.cancel', $trip), ['reason' => 'গাড়ি নষ্ট'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertTrue($trip->fresh()->isCancelled(),
            '⛔ দরজা খুলেছে, কিন্তু ট্রিপটা বাতিল হয়নি।');
    }

    /**
     * ⛔ চাবি ছাড়া ট্রিপ বন্ধ করা যায় না।
     *
     * ⓘ বন্ধ করাটা দিনের হিসাব চূড়ান্ত করে — কোন চালান পৌঁছেছে, কোনটা
     * ফিরেছে। ⚠️ চাবি ছাড়া বন্ধ করা গেলে দিনের হিসাব যে কেউ সিল করে
     * দিতে পারতেন।
     */
    public function test_a_trip_cannot_be_closed_without_the_key(): void
    {
        $trip = app(ShipmentService::class)->dispatch($this->draftTrip());

        $this->actingAs($this->outsider)
            ->post(route('sales.shipment.close', $trip))
            ->assertForbidden();

        $this->assertNotSame(DocumentStatus::CLOSED, $trip->fresh()->status,
            '⛔ ৪০৩ ফিরেছে, তবু ট্রিপটা বন্ধ হয়ে গেছে।');
    }

    /**
     * একটা খসড়া ট্রিপ — একটা নিশ্চিত চালান নিয়ে।
     *
     * ⓘ সহায়কগুলো `AVanGoesOutAndMustComeBackTest`-এর ছাঁচেই, হাতে
     * নতুন করে লেখা নয় ([[never-retype-a-body-you-are-extracting]])।
     */
    private function draftTrip(): Shipment
    {
        return app(ShipmentService::class)->create(
            ['trx_date' => now()->toDateString(), 'warehouse_id' => $this->warehouse->id],
            [$this->challan()->id],
        );
    }

    /** একটা নিশ্চিত চালান — ট্রিপে তোলার জন্য। */
    private function challan(): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        $challan = $service->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'delivered_qty' => '1', 'rate' => '100']],
        );

        return $service->confirm($challan);
    }
}
