<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Services\SalesQuotationService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ উদ্ধৃতির তুলনা ও সংস্করণ, মান যাচাই আর সিরিয়াল নম্বর — ফোনের নিজের পর্দায় (মালিক, ১৭ অক্টোবর ২০২৬; fe;
 * [[QuotationCompareApiController]], [[QualityPhoneController]], [[SerialPhoneController]])।
 *
 * দাবি, আসল HTTP দিয়ে:
 *  - তুলনা ওয়েবের একই হিসাব বলে, পরিবার ধরেও; দেয়ালের বাইরের বা অচেনা উদ্ধৃতি ৪০৪; চাবি ছাড়া ৪০৩।
 *  - সংস্করণের তালিকায় যার সংস্করণ আছে সে, আর তার সর্বশেষটা।
 *  - নতুন যাচাই একবারই বসে (একই চাবি); রায় কেবল রায়ের চাবিতে; চাবি ছাড়া ৪০৩।
 *  - সিরিয়াল ঢোকানো একবারই, নকল নম্বর ফেরে, বের করলে পিস "গ্রাহকের কাছে"; চাবি ছাড়া ৪০৩।
 */
final class ThePhoneComparesQuotationsAndRunsQualityAndSerialsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'WH-MMS')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->warehouse->branch_id])->save();
        CompanyContext::set((int) $this->company->id, (int) $this->warehouse->branch_id);
        $this->actingAs($this->owner);
    }

    public function test_two_quotations_compare_the_web_way_and_the_doors_ask_the_view_key(): void
    {
        $first = $this->quotation('3');
        $second = $this->quotation('5');

        $body = $this->phoneGet($this->owner, '/api/v1/sales/quotations/compare?ids[]='.$first->public_id.'&ids[]='.$second->public_id)
            ->assertOk()->json();
        $this->assertSame([$first->public_id, $second->public_id], array_column($body['quotations'], 'id'));
        $this->assertNotNull($body['comparison'], '⛔ দুটো উদ্ধৃতির তুলনা এল না।');
        $this->assertNotEmpty($body['comparison']['head']);
        $this->assertCount(2, $body['comparison']['lines'][0]['cells'], '⛔ সারির দুই কলাম নেই।');

        $one = $this->phoneGet($this->owner, '/api/v1/sales/quotations/compare?ids[]='.$first->public_id)->assertOk()->json();
        $this->assertNull($one['comparison'], 'একটা দিয়ে তুলনা হলো');

        $this->phoneGet($this->owner, '/api/v1/sales/quotations/compare?ids[]='.$first->public_id.'&ids[]=00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
        $this->phoneGet($this->staff([]), '/api/v1/sales/quotations/compare?ids[]='.$first->public_id)->assertForbidden();
        $this->phoneGet($this->staff([]), '/api/v1/sales/quotations/revisions')->assertForbidden();
    }

    public function test_a_revised_quotation_is_on_the_revision_list_with_its_latest_and_compares_by_family(): void
    {
        $service = app(SalesQuotationService::class);
        $original = $service->markSent($service->submit($this->quotation('2')));
        $revision = $service->revise($original->fresh());

        $rows = $this->phoneGet($this->owner, '/api/v1/sales/quotations/revisions')->assertOk()->json('rows');
        $row = collect($rows)->firstWhere('id', $original->public_id);
        $this->assertNotNull($row, '⛔ সংস্করণ-করা উদ্ধৃতি তালিকায় নেই।');
        $this->assertSame($revision->public_id, $row['latest']['id'], '⛔ সর্বশেষ সংস্করণ ভুল।');

        $family = $this->phoneGet($this->owner, '/api/v1/sales/quotations/compare?family='.$revision->public_id)->assertOk()->json();
        $this->assertEqualsCanonicalizing([$original->public_id, $revision->public_id], array_column($family['quotations'], 'id'));
    }

    public function test_an_inspection_opens_once_and_only_the_decide_key_gives_the_verdict(): void
    {
        $product = $this->stocked('30');
        $body = ['product' => $product->public_id, 'warehouse' => $this->warehouse->public_id,
            'inspected_on' => now()->toDateString(), 'inspected_qty' => '10'];

        $made = $this->phonePost($this->owner, '/api/v1/inventory/quality', $body, 'qc-key-0001')->assertCreated()->json();
        $this->phonePost($this->owner, '/api/v1/inventory/quality', $body, 'qc-key-0001')->assertCreated();
        $this->assertSame(1, QualityInspection::query()->where('product_id', $product->id)->count(), '⛔ একই চাবিতে দুটো যাচাই।');

        $this->assertContains($made['id'], array_column($this->phoneGet($this->owner, '/api/v1/inventory/quality')->assertOk()->json('rows'), 'id'));
        $page = $this->phoneGet($this->owner, '/api/v1/inventory/quality/'.$made['id'])->assertOk()->json();
        $this->assertTrue($page['can_decide']);
        $this->assertSame(QualityInspection::PENDING, $page['status']);

        $viewer = $this->staff(['inventory.qc.view']);
        $this->phoneGet($viewer, '/api/v1/inventory/quality/'.$made['id'])->assertOk()->assertJsonPath('can_decide', false);
        $verdict = ['result' => QualityInspection::APPROVED, 'accepted_qty' => '10', 'rejected_qty' => '0'];
        $this->phonePost($viewer, '/api/v1/inventory/quality/'.$made['id'].'/decide', $verdict, 'qc-key-0002')->assertForbidden();
        $this->phonePost($this->staff([]), '/api/v1/inventory/quality', $body, 'qc-key-0003')->assertForbidden();
        $this->phoneGet($this->staff([]), '/api/v1/inventory/quality')->assertForbidden();

        $this->phonePost($this->owner, '/api/v1/inventory/quality/'.$made['id'].'/decide', $verdict, 'qc-key-0004')->assertCreated();
        $this->assertSame(QualityInspection::APPROVED, QualityInspection::query()->where('public_id', $made['id'])->value('status'));
    }

    public function test_serials_go_in_once_a_copy_is_refused_and_an_issued_piece_is_with_the_customer(): void
    {
        $product = $this->product('SN');
        $product->forceFill(['track_serial' => true])->save();
        $body = ['product' => $product->public_id, 'warehouse' => $this->warehouse->public_id,
            'received_on' => now()->toDateString(), 'serials' => "SN-PH-1\nSN-PH-2"];

        $this->phonePost($this->owner, '/api/v1/inventory/serials', $body, 'sn-key-0001')->assertCreated()->assertJsonPath('count', 2);
        $this->phonePost($this->owner, '/api/v1/inventory/serials', $body, 'sn-key-0001')->assertCreated();
        $this->assertSame(2, SerialNumber::query()->where('product_id', $product->id)->count(), '⛔ একই চাবিতে সিরিয়াল দুবার।');

        $this->phonePost($this->owner, '/api/v1/inventory/serials', ['serials' => 'SN-PH-1'] + $body, 'sn-key-0002')->assertStatus(422);
        $this->assertSame(2, SerialNumber::query()->where('product_id', $product->id)->count(), '⛔ নকল সিরিয়াল ঢুকল।');

        $this->phonePost($this->owner, '/api/v1/inventory/serials/issue',
            ['serials' => 'SN-PH-1', 'issued_on' => now()->toDateString(), 'sold_to' => 'রহিম স্টোর'], 'sn-key-0003')->assertCreated();
        $rows = $this->phoneGet($this->owner, '/api/v1/inventory/serials?serial=SN-PH-1')->assertOk()->json('rows');
        $this->assertSame(SerialNumber::SOLD, collect($rows)->firstWhere('serial_no', 'SN-PH-1')['status'] ?? null);

        $this->phoneGet($this->staff([]), '/api/v1/inventory/serials')->assertForbidden();
        $this->phonePost($this->staff(['inventory.serial.view']), '/api/v1/inventory/serials', $body, 'sn-key-0004')->assertForbidden();
    }

    /** ⛔ যিনি যাচাই করলেন, রায়ের চাবি থাকলেও নিজে রায় দেন না — অন্যজন দেন (maker-checker; a5-এর পর্যালোচনা, fe, ১৭ অক্টোবর ২০২৬) */
    public function test_the_inspector_with_the_decide_key_still_waits_for_another_hand(): void
    {
        $product = $this->stocked('20');
        $inspector = $this->staff(['inventory.qc.view', 'inventory.qc.create', 'inventory.qc.decide']);
        $decider = $this->staff(['inventory.qc.view', 'inventory.qc.decide']);

        $made = $this->phonePost($inspector, '/api/v1/inventory/quality', ['product' => $product->public_id, 'warehouse' => $this->warehouse->public_id,
            'inspected_on' => now()->toDateString(), 'inspected_qty' => '5'], 'qc-self-0001')->assertCreated()->json();
        $this->phoneGet($inspector, '/api/v1/inventory/quality/'.$made['id'])->assertOk()->assertJsonPath('can_decide', false);

        $verdict = ['result' => QualityInspection::APPROVED, 'accepted_qty' => '5', 'rejected_qty' => '0'];
        $this->phonePost($inspector, '/api/v1/inventory/quality/'.$made['id'].'/decide', $verdict, 'qc-self-0002')->assertForbidden();
        $this->assertSame(QualityInspection::PENDING, QualityInspection::query()->where('public_id', $made['id'])->value('status'),
            '⛔ যিনি যাচাই করলেন তিনিই রায় দিলেন।');

        $this->phonePost($decider, '/api/v1/inventory/quality/'.$made['id'].'/decide', $verdict, 'qc-self-0003')->assertCreated();
        $this->assertSame(QualityInspection::APPROVED, QualityInspection::query()->where('public_id', $made['id'])->value('status'));
    }

    /** ⛔ "1e3" পরিমাণে ৪২২, ৫০০ নয়; চাবি ছাড়া নতুন লেখা ৪২৮ — কিছুই বসে না */
    public function test_a_scientific_quantity_and_a_keyless_write_are_refused_and_nothing_is_written(): void
    {
        $product = $this->stocked('20');
        $body = ['product' => $product->public_id, 'warehouse' => $this->warehouse->public_id, 'inspected_on' => now()->toDateString(), 'inspected_qty' => '1e1'];

        $this->phonePost($this->owner, '/api/v1/inventory/quality', $body, 'qc-sci-0001')->assertStatus(422)->assertJsonValidationErrors('inspected_qty');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $this->flushHeaders();
        $this->postJson('/api/v1/inventory/quality', ['inspected_qty' => '5'] + $body)->assertStatus(428);

        $this->assertSame(0, QualityInspection::query()->where('product_id', $product->id)->count(), '⛔ "1e1" বা চাবি ছাড়া যাচাই বসল।');
    }

    /** ⛔ অন্য শাখার গুদামের পিস তালিকায় নেই — নম্বর জানলেও না (গুদামের দেয়াল; a5-এর পর্যালোচনা, fe, ১৭ অক্টোবর ২০২৬) */
    public function test_a_serial_in_another_branchs_warehouse_is_not_listed(): void
    {
        $product = $this->product('SW');
        $there = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'WH-NTK')->firstOrFail();
        $this->assertNotSame((int) $this->warehouse->branch_id, (int) $there->branch_id, 'প্রস্তুতিটাই ভুল — দুই গুদাম এক শাখার।');

        foreach (['SN-WALL-HERE' => $this->warehouse, 'SN-WALL-THERE' => $there] as $no => $warehouse) {
            SerialNumber::query()->create(['company_id' => $this->company->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
                'serial_no' => $no, 'received_on' => now()->toDateString(), 'status' => SerialNumber::IN_STOCK, 'sold_to' => 'গোপন গ্রাহক']);
        }

        $listed = array_column($this->phoneGet($this->owner, '/api/v1/inventory/serials?serial=SN-WALL')->assertOk()->json('rows'), 'serial_no');
        $this->assertContains('SN-WALL-HERE', $listed);
        $this->assertNotContains('SN-WALL-THERE', $listed, '⛔ অন্য শাখার গুদামের পিস ফোনে দেখা গেল।');
        $this->assertSame([], $this->phoneGet($this->owner, '/api/v1/inventory/serials?serial=SN-WALL-THERE')->assertOk()->json('rows'));
    }

    /** ⛔ পরিবারের একটা সংস্করণ দেয়ালের বাইরে — পুরো তুলনা ৪০৪, চুপচাপ আধা পরিবার নয় (a5-এর পর্যালোচনা, ১৭ অক্টোবর ২০২৬) */
    public function test_a_family_with_a_revision_outside_the_wall_is_not_found(): void
    {
        $service = app(SalesQuotationService::class);
        $original = $service->markSent($service->submit($this->quotation('4')));
        $revision = $service->revise($original->fresh());
        $this->phoneGet($this->owner, '/api/v1/sales/quotations/compare?family='.$original->public_id)->assertOk();

        $there = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'WH-NTK')->value('branch_id');
        $this->assertNotSame((int) $this->warehouse->branch_id, (int) $there, 'প্রস্তুতিটাই ভুল — দুই গুদাম এক শাখার।');
        SalesQuotation::query()->withoutGlobalScopes()->whereKey($revision->id)->update(['branch_id' => $there]);

        $this->phoneGet($this->owner, '/api/v1/sales/quotations/compare?family='.$original->public_id)->assertNotFound();
    }

    // ── যন্ত্রপাতি ──

    private function quotation(string $qty): SalesQuotation
    {
        $product = Product::query()->orderBy('id')->firstOrFail();
        $rate = bccomp((string) $product->sale_price, '0', 4) > 0 ? (string) $product->sale_price : '100';

        return app(SalesQuotationService::class)->create([
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'valid_until' => now()->addDays(10)->toDateString(),
            'header_discount' => '0',
        ], [['product_id' => $product->id, 'qty' => $qty, 'rate' => $rate]]);
    }

    private function product(string $tag): Product
    {
        return Product::query()->create([
            'code' => $tag.'-'.mb_substr(md5(microtime()), 0, 8),
            'name_en' => 'Phone probe '.$tag,
            'name_bn' => 'ফোনের নমুনা '.$tag,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    private function stocked(string $qty): Product
    {
        $product = $this->product('QC');
        app(StockService::class)->move(product: $product, warehouse: $this->warehouse, sourceType: 'test.opening', sourceId: $product->id, floor: $qty);
        app(CostLayerService::class)->receive(product: $product, qty: $qty, unitCost: '10.00', sourceType: 'test.opening', sourceId: $product->id);

        return $product;
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id, 'current_branch_id' => $this->warehouse->branch_id]);
        $user->companies()->attach($this->company, ['is_active' => true]);

        if ($keys !== []) {
            CompanyContext::forCompany((int) $this->company->id, fn () => $user->givePermissionTo(
                ...array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $user->fresh();
    }

    private function phoneGet(User $user, string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }

    private function phonePost(User $user, string $url, array $body, string $key): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->withHeader('Idempotency-Key', $key)->postJson($url, $body);
    }
}
