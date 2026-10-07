<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\OwnerSignsDiscounts;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের কাউন্টার — মালিক, ৪ অক্টোবর ২০২৬: *"direct sales er counter banaw app e"*।
 *
 * ⛔ ফোনের দরজা নিজে কোনো নিয়ম জানে না — ওয়েবের একই যাচাই আর একই দরজা ([[DirectSaleApiController]])।
 * দাবি: প্রতিটা দেয়াল ফোন দিয়েও আটকায় —
 *   একই মানুষ: কাউন্টারের চাবি ছাড়া ৪০৩, চাবি দিলে খোলে;
 *   লট-ধরা পণ্যে লট ছাড়া ৪২২; শূন্য দর ৪২২; অচেনা public id ৪২২;
 *   যেকোনো হাতের ছাড় মালিকের সইয়ের অপেক্ষায়; ঋণসীমা পেরোলে বিক্রি ৪২২, অথচ খসড়া আটকায় না;
 *   আর ঠিকঠাক বিক্রিতে বাছা লটটাই বেরোয়।
 */
final class ThePhoneCounterHoldsEveryWallOfTheWebCounterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private Batch $lot;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->update(['track_batch' => true]);

        $this->lot = Batch::query()->create([
            'company_id' => $this->company->id, 'product_id' => $this->product->id,
            'batch_no' => 'LOT-PHONE', 'expiry_date' => '2028-01-01',
        ]);
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: 'opening', sourceId: $this->lot->id,
            floor: '100', date: now()->toDateString(), documentNo: 'TEST-LOT-PHONE', batch: $this->lot,
        );

        $this->seller = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->seller->companies()->attach($this->company->id, ['is_active' => true]);
    }

    public function test_the_same_seller_needs_the_counter_key_and_then_sees_the_lots_and_accounts(): void
    {
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
        $this->getJson('/api/v1/sales/direct/setup')->assertForbidden();
        $this->postJson('/api/v1/sales/direct', $this->sale())->assertForbidden();

        $this->grant('sales.challan.create');
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
        $setup = $this->getJson('/api/v1/sales/direct/setup')->assertOk()->json();

        $lots = $setup['lots'][(string) $this->product->public_id] ?? [];
        $this->assertSame([(string) $this->lot->public_id], array_column($lots, 'id'), 'লটের তালিকায় বাছার লট নেই, বা ক্রমিক id গেছে।');
        $this->assertNotSame([], $setup['moneyAccounts'], 'টাকার খাত নেই।');
        $this->assertNotSame([], $setup['paymentTerms']);
    }

    public function test_a_good_sale_goes_through_and_the_chosen_lot_leaves(): void
    {
        $this->asSeller();

        $out = $this->postJson('/api/v1/sales/direct', $this->sale())->assertCreated()->json();

        $this->assertContains($out['status'], ['done', 'held'], json_encode($out));
        $this->assertNotSame('', $out['invoice']['no'] ?? '');
        $this->assertSame((int) $this->lot->id, (int) StockMovement::query()->where('product_id', $this->product->id)
            ->where('floor_change', '<', 0)->latest('id')->value('batch_id'), '⛔ বাছা লটটা বেরোয়নি।');
    }

    public function test_a_tracked_product_without_a_lot_a_zero_price_and_an_unknown_id_are_refused(): void
    {
        $this->asSeller();

        $this->postJson('/api/v1/sales/direct', $this->sale(['lot' => null]))->assertStatus(422);
        $this->postJson('/api/v1/sales/direct', $this->sale(['rate' => '0']))->assertStatus(422)->assertJsonValidationErrors('lines.0.rate');
        $this->postJson('/api/v1/sales/direct', $this->sale(['product' => '00000000-0000-0000-0000-000000000000']))
            ->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->assertSame(0, StockMovement::query()->where('product_id', $this->product->id)->where('floor_change', '<', 0)->count(),
            '⛔ ফেরানো বিক্রিতেও মাল বেরিয়েছে।');
    }

    public function test_any_hand_discount_waits_for_the_owner(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);
        $this->asSeller();

        $out = $this->postJson('/api/v1/sales/direct', $this->sale(['discount_percent' => '5']))->json();

        $this->assertSame('held', $out['status'] ?? null, '⛔ ফোনের ছাড় মালিকের সই ছাড়াই গেল: '.json_encode($out));
        $this->assertNotSame('', (string) ($out['notice'] ?? ''));
    }

    public function test_over_the_credit_limit_a_sale_is_refused_but_a_draft_is_kept(): void
    {
        $this->customer->forceFill(['credit_limit' => '100'])->save();
        $this->asSeller();

        $this->postJson('/api/v1/sales/direct', $this->sale())->assertStatus(422);

        $draft = $this->postJson('/api/v1/sales/direct', [...$this->sale(), 'save_as_draft' => '1'])->assertCreated()->json();
        $this->assertSame('parked', $draft['status'], '⛔ সীমা পেরোনো খসড়াও আটকে গেল — খসড়ায় দেয়াল নেই (মালিক, ২৭ সেপ্টেম্বর)।');
    }

    // ── ⭐ ওয়েবের ৮ বোতাম ফোনেও — মালিক, ৪ অক্টোবর ২০২৬ ─────────────────

    /** টাকা নেওয়ার পদ্ধতি আর বাহক — ওয়েবের একই তালিকা, public_id দিয়ে */
    public function test_the_setup_carries_the_payment_ways_and_the_carriers_the_web_counter_offers(): void
    {
        $this->asSeller();

        $setup = $this->getJson('/api/v1/sales/direct/setup')->assertOk()->json();

        $web = app(\App\Modules\Sales\Services\DirectSaleOptions::class)->depositMethods();
        $this->assertCount($web->count(), $setup['depositMethods'], '⛔ ফোনের পদ্ধতির তালিকা ওয়েবের থেকে আলাদা।');
        $this->assertArrayHasKey('carriers', $setup);
        foreach ($setup['depositMethods'] as $method) {
            $this->assertFalse(ctype_digit((string) $method['id']), '⛔ ক্রমিক id ফোনে গেল।');
        }
    }

    /** খসড়া রাখা → তালিকায় → খুলে একই সারি → আবার পাঠালে একই খসড়াই পাকা হয়, দ্বিতীয় বিল নয় */
    public function test_a_kept_draft_is_listed_opened_with_its_lines_and_finished_as_the_same_bill(): void
    {
        $this->asSeller();
        $parked = $this->postJson('/api/v1/sales/direct', [...$this->sale(), 'save_as_draft' => '1'])->assertCreated()->json();

        $listed = $this->getJson('/api/v1/sales/direct/drafts')->assertOk()->json('drafts');
        $this->assertContains($parked['invoice']['id'], array_column($listed, 'id'), '⛔ রাখা খসড়া তালিকায় নেই।');

        $opened = $this->getJson('/api/v1/sales/direct/drafts/'.$parked['invoice']['id'])->assertOk()->json();
        $this->assertSame((string) $this->customer->public_id, $opened['customer']);
        $this->assertSame((string) $this->product->public_id, $opened['lines'][0]['product']);
        $this->assertSame((string) $this->lot->public_id, $opened['lines'][0]['lot']);
        $this->assertEqualsWithDelta(10.0, (float) $opened['lines'][0]['qty'], 0.0001);

        $invoices = SalesInvoice::query()->count();
        $done = $this->postJson('/api/v1/sales/direct', [...$this->sale(), 'resume' => $parked['invoice']['id']])->assertCreated()->json();

        $this->assertSame('done', $done['status']);
        $this->assertSame($parked['invoice']['id'], $done['invoice']['id'], '⛔ খসড়া খুলে পাঠালে নতুন বিল হলো, খসড়াটা পড়ে রইল।');
        $this->assertSame($invoices, SalesInvoice::query()->count());
    }

    /** বাতিল — একই বিক্রেতা: বিল বানানোর চাবি ছাড়া ৪০৩; দিলে খসড়া বাতিল হয়, না-জমা বিল অডিটে লেখা হয় */
    public function test_voiding_needs_the_bill_key_cancels_a_kept_draft_and_records_an_unsaved_bill(): void
    {
        $this->asSeller();
        $parked = $this->postJson('/api/v1/sales/direct', [...$this->sale(), 'save_as_draft' => '1'])->assertCreated()->json();

        $this->postJson('/api/v1/sales/direct/void', ['reason' => 'ভুল গ্রাহক', 'resume' => $parked['invoice']['id']])->assertForbidden();

        $this->grant('sales.invoice.create');
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);

        $this->postJson('/api/v1/sales/direct/void', ['resume' => $parked['invoice']['id']])->assertStatus(422);
        $this->postJson('/api/v1/sales/direct/void', ['reason' => 'ভুল গ্রাহক', 'resume' => $parked['invoice']['id']])
            ->assertOk()->assertJson(['voided' => true, 'draft' => true]);
        $this->assertSame(DocumentStatus::CANCELLED, SalesInvoice::query()->where('public_id', $parked['invoice']['id'])->value('status'),
            '⛔ খসড়া বাতিল হলো না।');

        $this->postJson('/api/v1/sales/direct/void', [
            'reason' => 'ক্রেতা চলে গেলেন', 'customer' => (string) $this->customer->public_id, 'lines' => 2, 'total' => '500',
        ])->assertOk()->assertJson(['voided' => true, 'draft' => false]);
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('audit_trails')->where('action', 'counter_bill_voided')->exists(),
            '⛔ না-জমা বিলের বাতিল অডিটে লেখা হয়নি।');
    }

    /**
     * "দাম দেখুন" — একই বিক্রেতা: মজুদ দেখার চাবি ছাড়া কেবল দর (SR-এর ফোনে মজুদ নয়), চাবি দিলে ওয়েবের কাউন্টারের হুবহু
     * বিক্রয়যোগ্য মজুদ আর লট; বাতিলের কারণ ওয়েবের পপ-আপের একই তালিকা।
     */
    public function test_the_price_check_shows_the_web_figures_and_stock_only_with_the_stock_key(): void
    {
        $this->asSeller();
        $url = '/api/v1/sales/direct/price/'.$this->product->public_id;

        $plain = $this->getJson($url)->assertOk()->json();
        $this->assertSame((string) $this->product->fresh()->sale_price, $plain['rate']);
        $this->assertNull($plain['available'], '⛔ মজুদের চাবি ছাড়াই মজুদ দেখা গেল।');
        $this->assertNull($plain['lots'][0]['qty']);

        $this->grant('inventory.stock.view');
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
        $full = $this->getJson($url)->assertOk()->json();

        $web = app(\App\Modules\Sales\Services\DirectSaleOptions::class)
            ->catalogue($this->warehouse, 1, (int) $this->product->id)->first();
        $this->assertSame((string) $web->available, $full['available'], '⛔ ফোন আর ওয়েব আলাদা মজুদ বলছে।');
        $this->assertSame('LOT-PHONE', $full['lots'][0]['no']);
        $this->assertNotNull($full['lots'][0]['qty'], 'মজুদের চাবিতেও লটের পরিমাণ নেই।');

        // ⛔ প্রস্তুতি-উত্তরেও একই নিয়ম — চাবি ছাড়া লটের পরিমাণ নয় (সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬); একই মানুষ চাবি পেলে পান
        $pid = (string) $this->product->public_id;
        $this->assertNotNull($this->getJson('/api/v1/sales/direct/setup')->json('lots')[$pid][0]['qty'], 'চাবিসহ প্রস্তুতিতে পরিমাণ নেই।');
        $this->seller->revokePermissionTo('inventory.stock.view');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
        $lot = $this->getJson('/api/v1/sales/direct/setup')->assertOk()->json('lots')[$pid][0];
        $this->assertNull($lot['qty'], '⛔ মজুদের চাবি ছাড়া প্রস্তুতি-উত্তরে লটের পরিমাণ গেল।');
        $this->assertSame((string) $this->lot->public_id, $lot['id'], 'লট বাছার ঘর চাবি ছাড়াও থাকা চাই।');

        $this->assertSame(
            array_values(array_filter(explode('|', (string) __('sales::field.cancel_reasons')))),
            $this->getJson('/api/v1/sales/direct/setup')->json('voidReasons'),
        );
    }

    /** ডেলিভারি — "পরে পাঠানো" হলে ঠিকানা আর তারিখ ছাড়া নয়; দিলে চালানে মোড, গাড়ি আর ভাড়া বসে */
    public function test_send_later_needs_an_address_and_a_date_and_the_challan_keeps_how_the_goods_go(): void
    {
        $this->asSeller();

        $this->postJson('/api/v1/sales/direct', [...$this->sale(), 'own_transport' => '0', 'delivery_mode' => 'send_later'])
            ->assertStatus(422)->assertJsonValidationErrors(['ship_to', 'ship_date']);

        $done = $this->postJson('/api/v1/sales/direct', [
            ...$this->sale(), 'own_transport' => '0', 'delivery_mode' => 'send_later',
            'ship_to' => 'বাজার রোড, দোকান ৪', 'ship_date' => now()->addDay()->toDateString(),
            'vehicle_owner' => 'hired', 'fare_paid_by' => 'customer',
        ])->assertCreated()->json();

        $challan = DeliveryChallan::query()->where('document_no', $done['challan']['no'])->firstOrFail();
        $this->assertSame('send_later', $challan->delivery_mode);
        $this->assertSame('hired', $challan->vehicle_owner);
        $this->assertSame('customer', $challan->fare_paid_by);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $line */
    private function sale(array $line = []): array
    {
        return [
            'customer' => (string) $this->customer->public_id,
            'warehouse' => (string) $this->warehouse->public_id,
            'own_transport' => '1',
            'lines' => [[
                'product' => (string) $this->product->public_id,
                'lot' => (string) $this->lot->public_id,
                'qty' => '10',
                'rate' => '100',
                ...$line,
            ]],
        ];
    }

    private function asSeller(): void
    {
        $this->grant('sales.challan.create');
        Sanctum::actingAs($this->seller->fresh(), [AuthController::APP]);
    }

    private function grant(string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $this->seller->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
