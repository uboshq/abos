<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Phone\PhonePaperRegistry;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\Purchase\Models\PurchaseContract;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseRequisition;
use App\Modules\Sales\Models\PriceListItem;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের নিজের পর্দায় লেনদেন — মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app style e zate bujazay sei vabe koro,
 * direct webpage app e bujajayna"* (fe-র ধাপ ৪; [[PhonePaperApiController]])।
 *
 * দাবি, আসল HTTP দিয়ে:
 *  - সতেরোটা ধরনের প্রতিটা তালিকা খোলে — খোঁজা আর অবস্থার ছাঁকনিসহ (প্রতিটা ধরনের নিজের কোয়েরি চলে)।
 *  - ক্রয়াদেশ তালিকায় তার অঙ্কসহ; সর্বমোট ডাটাবেজের সরাসরি যোগ; পাতায় সারি, সই আর ছাপার ধরন।
 *  - চাবি ছাড়া ৪০৩; অচেনা ধরন, ক্রমিক id আর অন্য কোম্পানির কাগজ ৪০৪; অচেনা অবস্থা ৪২২।
 *  - ক্রয়মূল্য দেখার চাবি ছাড়া অঙ্ক নেই, সর্বমোট নেই, পাতায় দর নেই — ওয়েবের পাতার একই নিয়ম।
 *  - শাখার ছাঁকনি দেয়ালের ভিতরে সরু করে: এক শাখা বাছলে অন্য শাখার কাগজ নেই।
 *  - স্থানান্তর, চাহিদা, চুক্তি, ট্রিপ, লোডিং শিট আর দর তালিকার পাতা আঁকা যায়।
 */
final class ThePhoneShowsEveryPaperOnItsOwnScreenTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'purchase_order', 'purchase_bill', 'purchase_receipt', 'purchase_return', 'purchase_requisition', 'purchase_rfq',
        'purchase_contract', 'sales_return', 'sales_gate_pass', 'sales_shipment', 'sales_loading_sheet', 'sales_transport',
        'sales_price_list_customer', 'sales_price_list_tier', 'sales_price_list_territory', 'sales_price_list_all',
        'inventory_transfer',
        // ⓘ হিসাব আর অর্থের তিনটা (১৭ অক্টোবর ২০২৬; [[ThePhoneMovesMoneyTheWebWayTest]])
        'accounts_inter_company', 'finance_capital', 'finance_insurance',
    ];

    private Company $company;

    private User $owner;

    private Branch $mms;

    private Branch $ntk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->mms = Branch::query()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->ntk = Branch::query()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();

        // ⓘ মালিক "সব শাখা"-য় — শাখার ছাঁকনি তখন কেবল ফোনের বাছাই
        $this->owner->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => null])->save();
        $this->actingAs($this->owner);
        CompanyContext::set((int) $this->company->id, null);
    }

    public function test_every_registered_paper_opens_its_list_with_search_and_every_status(): void
    {
        $this->assertEqualsCanonicalizing(self::KEYS, array_keys(app(PhonePaperRegistry::class)->all()),
            '⛔ ফোনের লেনদেনের ধরন ঘোষিত তালিকার সাথে মেলে না — কোনোটা বাদ, বা নাম বদলেছে।');

        foreach (self::KEYS as $key) {
            $list = $this->phone($this->owner, '/api/v1/papers/'.$key)->assertOk()->json();
            $this->assertSame($key, $list['type']);
            $this->assertIsArray($list['rows']);

            $this->phone($this->owner, '/api/v1/papers/'.$key.'?q=ZZ-nothing')->assertOk()->assertJsonPath('count', 0);

            foreach ($list['filters']['statuses'] as $status) {
                $this->phone($this->owner, '/api/v1/papers/'.$key.'?status='.$status['code'])->assertOk();
            }
        }
    }

    public function test_an_order_lists_with_its_total_and_opens_with_lines_signatures_and_print(): void
    {
        $order = $this->order('PO-PH-001', $this->mms, '125000');
        $this->order('PO-PH-002', $this->ntk, '5000');
        Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => PurchaseOrder::class, 'approvable_id' => $order->id,
            'module' => 'purchase', 'action' => 'order', 'amount' => '125000', 'status' => Approval::PENDING, 'current_level' => 1,
            'requested_by' => $this->owner->id, 'requested_at' => now(),
        ]);

        $list = $this->phone($this->owner, '/api/v1/papers/purchase_order')->assertOk()->json();
        $row = collect($list['rows'])->firstWhere('id', $order->public_id);
        $this->assertNotNull($row, '⛔ নতুন ক্রয়াদেশ ফোনের তালিকায় নেই।');
        $this->assertSame('125000.00', $row['total']);
        $this->assertSame(self::nameOf($order->supplier), $row['party']);

        $direct = (string) DB::table('pur_orders')->where('company_id', $this->company->id)->where('status', '<>', 'cancelled')
            ->whereBetween('trx_date', [$list['filters']['from'], $list['filters']['to']])->sum('total');
        $this->assertSame(0, bccomp($direct, $list['sum'], 2), '⛔ সর্বমোট ডাটাবেজের সরাসরি যোগ নয়।');
        $this->assertSame(0, bccomp('130000', $list['page_sum'], 2), '⛔ এই পাতার মোট সারিগুলোর যোগ নয়।');

        $page = $this->phone($this->owner, '/api/v1/papers/purchase_order/'.$order->public_id)->assertOk()->json();
        $this->assertSame('PO-PH-001', $page['no']);
        $this->assertSame('PurchaseOrder', $page['print_type'], '⛔ ক্রয়াদেশ ফোন থেকে ছাপা যায় না।');
        $this->assertCount(1, $page['rows'], '⛔ ক্রয়াদেশের সারি পাতায় নেই।');
        $this->assertSame(['product', 'qty', 'rate', 'amount'], array_column($page['columns'], 'key'));
        $this->assertContains(self::nameOf($order->supplier), array_column($page['facts'], 'value'));
        $this->assertCount(1, $page['signatures'], '⛔ অপেক্ষার সই পাতায় নেই।');
        $this->assertSame(Approval::PENDING, $page['signatures'][0]['status']);
        $this->assertSame(__('approval::status.pending', [], $this->owner->locale ?: config('app.locale')), $page['signatures'][0]['status_label'],
            '⛔ সইয়ের অবস্থা দেখার মানুষের ভাষায় নয়।');
    }

    public function test_the_branch_filter_narrows_inside_the_wall(): void
    {
        $here = $this->order('PO-PH-011', $this->mms, '100');
        $there = $this->order('PO-PH-012', $this->ntk, '200');

        $ids = fn (string $branch) => array_column($this->phone($this->owner, '/api/v1/papers/purchase_order?branch='.$branch)
            ->assertOk()->json('rows'), 'id');

        $this->assertContains($here->public_id, $ids((string) $this->mms->public_id));
        $this->assertNotContains($there->public_id, $ids((string) $this->mms->public_id), '⛔ এক শাখা বাছলে অন্য শাখার ক্রয়াদেশ এল।');
        $this->assertSame([], $ids('00000000-0000-0000-0000-000000000000'), '⛔ অচেনা শাখায় কাগজ এল।');

        // ⓘ মালিক নিজে এক শাখায় বসলে অন্য শাখার কাগজ ছাঁকনি ছাড়াও আসে না — দেয়াল মডেলের নিজের
        $this->owner->forceFill(['current_branch_id' => $this->mms->id])->save();
        $this->assertNotContains($there->public_id, array_column($this->phone($this->owner, '/api/v1/papers/purchase_order')->json('rows'), 'id'),
            '⛔ এক শাখায় বসা মানুষ অন্য শাখার ক্রয়াদেশ দেখলেন।');
        $this->phone($this->owner, '/api/v1/papers/purchase_order/'.$there->public_id)->assertNotFound();
    }

    public function test_the_doors_ask_their_keys_and_refuse_what_is_not_theirs(): void
    {
        $order = $this->order('PO-PH-021', $this->mms, '900');

        $nobody = $this->staff([]);
        $this->phone($nobody, '/api/v1/papers/purchase_order')->assertForbidden();
        $this->phone($nobody, '/api/v1/papers/purchase_order/'.$order->public_id)->assertForbidden();

        $this->phone($this->owner, '/api/v1/papers/no_such_paper')->assertNotFound();
        $this->phone($this->owner, '/api/v1/papers/purchase_order/'.$order->id)->assertNotFound();
        $this->phone($this->owner, '/api/v1/papers/purchase_order?status=nonsense')->assertStatus(422);

        $other = Company::query()->where('id', '<>', $this->company->id)->orderBy('id')->firstOrFail();
        // ⓘ সরাসরি সারি — মডেলের create কোম্পানিটা চলতি কোম্পানিতে বসিয়ে দিত
        $foreign = (string) \Illuminate\Support\Str::uuid();
        DB::table('pur_orders')->insert([
            'public_id' => $foreign, 'company_id' => $other->id, 'document_no' => 'PO-PH-FOREIGN', 'supplier_id' => $order->supplier_id,
            'trx_date' => now()->toDateString(), 'subtotal' => '1', 'discount' => '0', 'tax' => '0', 'total' => '1', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->phone($this->owner, '/api/v1/papers/purchase_order/'.$foreign)->assertNotFound();
    }

    public function test_without_the_cost_key_no_rate_no_amount_and_no_total(): void
    {
        $order = $this->order('PO-PH-031', $this->mms, '700');
        $buyer = $this->staff(['purchase.order.view']);

        $list = $this->phone($buyer, '/api/v1/papers/purchase_order')->assertOk()->json();
        $row = collect($list['rows'])->firstWhere('id', $order->public_id);
        $this->assertNotNull($row, 'প্রস্তুতিটাই ভুল — চাবিওয়ালা ক্রয়াদেশটা দেখছেন না।');
        $this->assertNull($row['total'], '⛔ ক্রয়মূল্যের চাবি ছাড়া ক্রয়াদেশের অঙ্ক ফোনে।');
        $this->assertNull($list['sum'], '⛔ ক্রয়মূল্যের চাবি ছাড়া সর্বমোট ফোনে।');

        $page = $this->phone($buyer, '/api/v1/papers/purchase_order/'.$order->public_id)->assertOk()->json();
        $this->assertSame(['product', 'qty'], array_column($page['columns'], 'key'), '⛔ ক্রয়মূল্যের চাবি ছাড়া দর ফোনের পাতায়।');
        $this->assertArrayNotHasKey('amount', $page['totals']);
        $this->assertArrayNotHasKey('rate', $page['rows'][0]);
    }

    public function test_the_other_papers_open_their_pages(): void
    {
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $from = Warehouse::query()->where('code', 'WH-MMS')->firstOrFail();
        $to = Warehouse::query()->where('code', 'WH-NTK')->firstOrFail();
        $base = ['company_id' => $this->company->id, 'branch_id' => $this->mms->id];

        $transfer = StockTransfer::query()->create($base + ['document_no' => 'TR-PH-1', 'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id, 'trx_date' => now()->toDateString(), 'status' => 'confirmed']);
        $transfer->lines()->create(['company_id' => $this->company->id, 'product_id' => $product->id, 'qty' => '12', 'line_no' => 1]);

        $requisition = PurchaseRequisition::query()->create($base + ['document_no' => 'PR-PH-1', 'trx_date' => now()->toDateString(),
            'status' => 'draft', 'requested_by' => $this->owner->id, 'purpose' => 'দোকানের তাক']);
        $requisition->lines()->create(['company_id' => $this->company->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => '5']);

        $contract = PurchaseContract::query()->create($base + ['document_no' => 'PC-PH-1', 'supplier_id' => $supplier->id,
            'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), 'status' => 'confirmed']);
        $contract->lines()->create(['company_id' => $this->company->id, 'line_no' => 1, 'product_id' => $product->id, 'agreed_rate' => '42']);

        $trip = Shipment::query()->create($base + ['document_no' => 'TRP-PH-1', 'trx_date' => now()->toDateString(),
            'warehouse_id' => $from->id, 'vehicle_no' => 'DHA-11-2233', 'driver_name' => 'করিম', 'status' => 'draft']);

        $customer = Customer::acrossDealers()->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();
        $list = PriceList::query()->create(['code' => 'PL-PH-1', 'name_en' => 'Phone list', 'customer_id' => $customer->id, 'is_active' => true]);
        PriceListItem::query()->create(['price_list_id' => $list->id, 'product_id' => $product->id, 'price' => '38',
            'valid_from' => now()->toDateString()]);

        foreach ([
            ['inventory_transfer', $transfer->public_id, 'TR-PH-1'],
            ['purchase_requisition', $requisition->public_id, 'PR-PH-1'],
            ['purchase_contract', $contract->public_id, 'PC-PH-1'],
            ['sales_shipment', $trip->public_id, 'TRP-PH-1'],
            ['sales_loading_sheet', $trip->public_id, 'TRP-PH-1'],
            ['sales_price_list_customer', $list->public_id, 'PL-PH-1'],
        ] as [$key, $id, $no]) {
            $this->assertContains($id, array_column($this->phone($this->owner, '/api/v1/papers/'.$key)->assertOk()->json('rows'), 'id'),
                "⛔ {$no} ফোনের {$key} তালিকায় নেই।");

            $page = $this->phone($this->owner, '/api/v1/papers/'.$key.'/'.$id)->assertOk()->json();
            $this->assertSame($no, $page['no'], "⛔ {$key}-এর পাতা অন্য কাগজের।");
            $this->assertNotEmpty($page['facts'], "⛔ {$key}-এর পাতায় কোনো ঘর নেই।");
        }

        $this->assertSame(__('inventory::status.on_the_way', [], $this->owner->locale ?: config('app.locale')),
            $this->phone($this->owner, '/api/v1/papers/inventory_transfer/'.$transfer->public_id)->json('status_label'));
        $this->assertCount(1, $this->phone($this->owner, '/api/v1/papers/purchase_requisition/'.$requisition->public_id)->json('rows'));
        $this->assertNotContains($list->public_id, array_column($this->phone($this->owner, '/api/v1/papers/sales_price_list_all')->json('rows'), 'id'),
            '⛔ গ্রাহকের দর তালিকা "সবার" ধরনে এল।');
    }

    /**
     * ⛔ দর তালিকার দর পাতায় ভাগ — আগে ৫০০-তে চুপচাপ কাটত; আর তালিকার "কার জন্য" সারিপ্রতি কোয়েরি নয়, রকমপ্রতি একটা
     * (a5-এর পর্যালোচনা, fe, ১৭ অক্টোবর ২০২৬; [[PriceListPaper::prepare()]])।
     */
    public function test_a_price_list_pages_its_prices_and_the_list_asks_once_for_whom(): void
    {
        $product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $customers = Customer::query()->inViewedBranch()->orderBy('id')->limit(4)->get();
        $this->assertCount(4, $customers, 'প্রস্তুতিটাই ভুল — চারজন গ্রাহক নেই।');

        $list = PriceList::query()->create(['code' => 'PL-PG-0', 'name_en' => 'Paged list', 'customer_id' => $customers[0]->id, 'is_active' => true]);
        $per = \App\Modules\Sales\Phone\PriceListPaper::ITEMS_PER_PAGE;
        PriceListItem::query()->insert(array_map(fn (int $i) => ['public_id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->company->id, 'price_list_id' => $list->id,
            'product_id' => $product->id, 'price' => '38', 'valid_from' => now()->subDays($i)->toDateString(),
            'valid_to' => now()->subDays($i)->toDateString(), 'created_at' => now(), 'updated_at' => now()], range(1, $per + 1)));

        $first = $this->phone($this->owner, '/api/v1/papers/sales_price_list_customer/'.$list->public_id)->assertOk()->json();
        $this->assertCount($per, $first['rows'], '⛔ দরের প্রথম পাতা পুরো নয়।');
        $this->assertSame($per + 1, $first['rows_count']);
        $this->assertSame(2, $first['rows_next_page'], '⛔ আরও দর আছে, ফোনকে বলা হলো না — চুপচাপ কাটা।');
        $second = $this->phone($this->owner, '/api/v1/papers/sales_price_list_customer/'.$list->public_id.'?page=2')->assertOk()->json();
        $this->assertCount(1, $second['rows']);
        $this->assertNull($second['rows_next_page']);
        $this->phone($this->owner, '/api/v1/papers/sales_price_list_customer/'.$list->public_id.'?page[]=x')->assertStatus(422);

        // ⓘ এক তালিকা বনাম চার তালিকা — কোয়েরির সংখ্যা একই
        $count = function (): int {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->phone($this->owner, '/api/v1/papers/sales_price_list_customer')->assertOk();

            return count(\Illuminate\Support\Facades\DB::getQueryLog());
        };
        $one = $count();
        foreach ($customers->slice(1) as $i => $customer) {
            PriceList::query()->create(['code' => 'PL-PG-'.$i, 'name_en' => 'More '.$i, 'customer_id' => $customer->id, 'is_active' => true]);
        }
        $this->assertSame($one, $count(), '⛔ তালিকা বাড়লে কোয়েরিও বাড়ে — সারিপ্রতি "কার জন্য" খোঁজা (N+1)।');
        $rows = $this->phone($this->owner, '/api/v1/papers/sales_price_list_customer')->json('rows');
        $this->assertStringContainsString((string) \App\Core\Phone\PhonePaper::nameOf($customers[1]),
            (string) collect($rows)->firstWhere('no', 'PL-PG-1')['note'], '⛔ একসাথে খোঁজায় "কার জন্য" ভুল গ্রাহক।');
    }

    /** ⓘ চাহিদাপত্র, RFQ আর চুক্তির ওয়েব-তালিকা বাতিলও দেখায় — ফোনেও, অবস্থা না বাছলে; আদেশ আগের মতো লুকায় (a5-এর পর্যালোচনা, ১৭ অক্টোবর ২০২৬) */
    public function test_a_cancelled_requisition_shows_like_the_web_and_a_cancelled_order_stays_hidden(): void
    {
        $product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $requisition = PurchaseRequisition::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->mms->id,
            'document_no' => 'PR-CX-1', 'trx_date' => now()->toDateString(), 'status' => 'cancelled', 'requested_by' => $this->owner->id]);
        $requisition->lines()->create(['company_id' => $this->company->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => '1']);
        $order = $this->order('PO-CX-1', $this->mms, '100');
        $order->forceFill(['status' => 'cancelled'])->save();

        $this->assertContains($requisition->public_id, array_column($this->phone($this->owner, '/api/v1/papers/purchase_requisition')->assertOk()->json('rows'), 'id'),
            '⛔ বাতিল চাহিদাপত্র ফোনে লুকানো, ওয়েবে দেখা যায়।');
        $this->assertNotContains($order->public_id, array_column($this->phone($this->owner, '/api/v1/papers/purchase_order')->assertOk()->json('rows'), 'id'),
            '⛔ বাতিল আদেশ ফোনে দেখা গেল — ওয়েব লুকায়।');
    }

    private function order(string $no, Branch $branch, string $total): PurchaseOrder
    {
        $order = PurchaseOrder::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id, 'document_no' => $no,
            'supplier_id' => Supplier::query()->orderBy('id')->value('id'), 'trx_date' => now()->toDateString(),
            'subtotal' => $total, 'total' => $total, 'status' => 'draft', 'created_by' => $this->owner->id,
        ]);
        $order->lines()->create([
            'product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id,
            'ordered_qty' => '10', 'rate' => bcdiv($total, '10', 4), 'amount' => $total, 'line_no' => 1,
        ]);

        return $order->fresh();
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company, ['is_active' => true]);

        if ($keys !== []) {
            CompanyContext::forCompany((int) $this->company->id, fn () => $user->givePermissionTo(
                ...array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $user->fresh();
    }

    private static function nameOf(?Supplier $supplier): ?string
    {
        return $supplier?->name();
    }

    private function phone(User $user, string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }
}
