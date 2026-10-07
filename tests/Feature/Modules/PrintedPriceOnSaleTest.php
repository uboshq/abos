<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ছাপা দামের সীমা — বিক্রয়ে, আর দামটা বদলানোর পথ।
 *
 * ── দুইটা কেন এক টেস্টে ──────────────────────────────────────────────
 * দুইটা একই মুদ্রার দুই পিঠ। সীমাটা আইন, তাই কোনো সুইচ নেই; কিন্তু
 * সংখ্যাটা স্থির নয় — প্রস্তুতকারক বাড়ান-কমান (মালিকের কথা, ২০২৬-০৮-১৩)।
 * বদলানোর পথ না থাকলে দোকানিকে হয় ভুল দামে বেচতে হত, নয় একটা নকল লট
 * খুলতে হত — আর নকল লট মানে রিকলের খাতায় মিথ্যা সারি।
 */
class PrintedPriceOnSaleTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    private Customer $customer;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();

        $this->product = Product::query()->firstOrFail();
        $this->product->forceFill(['track_batch' => true])->save();

        $this->batch = Batch::query()->create([
            'product_id' => $this->product->id,
            'batch_no' => 'B1',
            'expiry_date' => now()->addYear()->toDateString(),
            'mrp' => '120',
        ]);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_opening',
            sourceId: $this->batch->id,
            floor: '100',
            batch: $this->batch,
        );
    }

    /**
     * একটা চালান, চাইলে লাইনে ছাড় বসিয়ে।
     *
     * ছাড়ের শতাংশটা লাইনের সারিতে সরাসরি বসানো হয়, create()-এর লাইন
     * অ্যারেতে নয়: চালান সার্ভিস ওই ঘরটা পড়ে না, ওটা বসে সরাসরি
     * বিক্রির পর্দা থেকে (DirectSaleService::stampExtras)। প্রথমবার
     * অ্যারেতে পাঠিয়েছিলাম আর টেস্ট দুইটা মিথ্যা ফল দিয়েছিল — ছাড়
     * কোথাও পৌঁছায়নি, তাই দাম ছিল ছাড়হীন।
     *
     * @param  array<string, mixed>  $line
     */
    private function sell(array $line, ?string $discountPercent = null): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'delivered_qty' => '1', ...$line]],
        );

        if ($discountPercent !== null) {
            $challan->lines()->update(['discount_percent' => $discountPercent]);
            $challan->load('lines');
        }

        return app(DeliveryChallanService::class)->confirm($challan);
    }

    // ── সীমা ─────────────────────────────────────────────────────

    public function test_selling_at_the_printed_price_is_allowed(): void
    {
        $challan = $this->sell(['rate' => '120']);

        $this->assertSame('confirmed', $challan->status);
    }

    /** MRP ১২০-এর ওষুধ ১২৫-এ যায় না। */
    public function test_selling_above_the_printed_price_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->sell(['rate' => '125']);
    }

    /**
     * সীমা ভাঙলে মালও বেরোয় না।
     *
     * একই লেনদেনে বলে চলাচলগুলো ফিরে যায়। না ফিরলে "অর্ধেক বেরোনো"
     * বলে একটা অবস্থা তৈরি হত: মজুদ কমে গেছে, অথচ কোনো কাগজ নেই।
     */
    public function test_a_refused_sale_moves_no_stock(): void
    {
        $before = $this->batch->balance($this->warehouse);

        try {
            $this->sell(['rate' => '125']);
        } catch (ValidationException) {
            // আশা করাই হচ্ছিল
        }

        $this->assertSame(0, bccomp($before, $this->batch->fresh()->balance($this->warehouse), 4));
    }

    /**
     * ঋণাত্মক ছাড় দিয়ে সীমা পেরোনো যায় না।
     *
     * ক্রেতা যা দেন সেটাই দাম। ছাড়ের আগের অঙ্ক দেখলে ১২০ দর আর −১০%
     * ছাড় বসিয়ে ১৩২-এ বেচা যেত, আর নিয়মটা কাগজে টিকে থাকত।
     */
    public function test_a_negative_discount_cannot_lift_the_price(): void
    {
        $this->expectException(ValidationException::class);

        $this->sell(['rate' => '120'], discountPercent: '-10');
    }

    /** ছাড়ের পরে সীমার নিচে নামলে বিক্রয় চলে। */
    public function test_a_discount_that_brings_it_under_is_fine(): void
    {
        $challan = $this->sell(['rate' => '130'], discountPercent: '20');

        $this->assertSame('confirmed', $challan->status);
    }

    /** গায়ে দাম না থাকলে কোনো সীমা নেই — চাল, সাবান, বিস্কুট। */
    public function test_a_lot_without_a_printed_price_has_no_ceiling(): void
    {
        $this->batch->forceFill(['mrp' => null])->save();

        $challan = $this->sell(['rate' => '999']);

        $this->assertSame('confirmed', $challan->status);
    }

    // ── দাম বদলানো ───────────────────────────────────────────────

    /**
     * MRP বাড়ানো যায়, আর বাড়ানোর পর নতুন দামে বেচা যায়।
     *
     * এটাই মালিকের চাওয়া: সংখ্যাটা স্থির নয়।
     */
    public function test_the_printed_price_can_be_raised_and_then_charged(): void
    {
        $this->put(route('inventory.batch.reprice', ['batch' => $this->batch->id]), [
            'mrp' => '150',
            'reason' => 'প্রস্তুতকারক নতুন দাম ছেপেছেন',
        ])->assertRedirect();

        $challan = $this->sell(['rate' => '145']);

        $this->assertSame('confirmed', $challan->status);
        $this->assertSame(0, bccomp('150', (string) $this->batch->fresh()->mrp, 4));
    }

    /** কমানোও যায় — আর তখন পুরনো দামে আর বেচা যায় না। */
    public function test_lowering_the_printed_price_lowers_the_ceiling(): void
    {
        $this->put(route('inventory.batch.reprice', ['batch' => $this->batch->id]), [
            'mrp' => '100',
            'reason' => 'দাম কমেছে',
        ])->assertRedirect();

        $this->expectException(ValidationException::class);

        $this->sell(['rate' => '110']);
    }

    /**
     * কারণ ছাড়া দাম বদলানো যায় না।
     *
     * ছয় মাস পরে "এই লটের দাম কেন বাড়ল" প্রশ্নের উত্তর কেবল ওই লেখাটাই
     * দিতে পারে।
     */
    public function test_repricing_without_a_reason_is_refused(): void
    {
        $this->put(route('inventory.batch.reprice', ['batch' => $this->batch->id]), [
            'mrp' => '150',
        ])->assertSessionHasErrors('reason');
    }

    /**
     * বদলটা অডিটে বসে — পুরনো ও নতুন দুইটা মান সহ।
     *
     * টাকার ঘর, তাই চিহ্ন ছাড়া বদলানো চলে না।
     */
    public function test_the_change_lands_in_the_audit_trail(): void
    {
        $this->put(route('inventory.batch.reprice', ['batch' => $this->batch->id]), [
            'mrp' => '150',
            'reason' => 'নতুন স্টিকার',
        ])->assertRedirect();

        $change = $this->batch->fresh()->auditTrail()->get()
            ->flatMap(fn ($row) => $row->changes)
            ->firstWhere('field', 'mrp');

        $this->assertNotNull($change, 'অডিটে দামের কোনো বদল নেই');

        /*
         * সংখ্যা ধরে মেলানো, স্ট্রিং ধরে নয়।
         *
         * প্রথমে '120.0000' লিখে মিলিয়েছিলাম আর ফেল করেছিল — অডিট কী
         * ফরম্যাটে লেখে সেটা এই টেস্টের বিষয়ই নয়। বিষয় একটাই: পুরনো
         * আর নতুন দুইটা মানই রাখা হয়েছে কি না।
         */
        $this->assertSame(0, bccomp('120', (string) $change->old_value, 4));
        $this->assertSame(0, bccomp('150', (string) $change->new_value, 4));
    }

    /** কাউন্টারের লোক দাম বদলাতে পারে না — আলাদা অনুমতি। */
    public function test_a_salesman_cannot_reprice_a_lot(): void
    {
        $role = Role::findOrCreate('counter-hand');
        $role->syncPermissions(Permission::query()->where('name', 'like', 'sales.%')->get());

        $hand = User::factory()->create();
        $hand->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $hand->forceFill(['current_company_id' => CompanyContext::id()])->save();
        $hand->assignRole($role);

        $this->actingAs($hand)
            ->put(route('inventory.batch.reprice', ['batch' => $this->batch->id]), [
                'mrp' => '999',
                'reason' => 'যা খুশি',
            ])
            ->assertForbidden();
    }

    /**
     * মেয়াদ শোধরানোও একই পাহারার পেছনে।
     *
     * মেয়াদ পিছিয়ে দিলে মেয়াদোত্তীর্ণ মাল আবার বিক্রয়যোগ্য হয়ে যায় —
     * এক ঘরের সম্পাদনা, অথচ ফল কাউন্টারে মেয়াদ পেরোনো ওষুধ।
     */
    public function test_correcting_an_expiry_needs_the_same_permission_and_a_reason(): void
    {
        $this->put(route('inventory.batch.expiry', ['batch' => $this->batch->id]), [
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ])->assertSessionHasErrors('reason');

        $this->put(route('inventory.batch.expiry', ['batch' => $this->batch->id]), [
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'reason' => 'কার্টনে ভুল ছাপা ছিল',
        ])->assertRedirect();

        $this->assertSame(
            now()->addMonths(6)->toDateString(),
            $this->batch->fresh()->expiry_date?->toDateString(),
        );
    }
}
