<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Sync\SyncService;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনের প্রতিটা দরজায় গ্রাহকের পাশে পয়েন্ট আর শাখা — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম): *"গ্রাহকের নামের পাশে পয়েন্ট
 * দিতে হবে অবশ্যই, সব জায়গায়, আর শাখার নামও দিতে হবে।"* ফোন লেখে "নাম · পয়েন্ট · শাখা" ([[Customer::branchName()]])।
 *
 * ⭐ দাবি:
 *   · আদেশ, DO, দরপত্র (তালিকা আর খোলা কাগজ) — `customer.point` আর `customer.branch`
 *   · আদায়, আজকের ডেলিভারি, ফেরতের বিল, ট্র্যাকিং, বিল আর চালানের তালিকা — `customer_point` আর `customer_branch` পাশাপাশি
 *     (পুরনো অ্যাপের `customer_point` যেমন ছিল তেমনই)
 *   · গ্রাহকের খাতা আর বাকির পাতা — `point` আর `branch`; সরবরাহকারীর তালিকা — `branch`
 *   · বাকির তালিকার `name` খালি নাম (রিপোর্টের জোড়া লেখা নয়) — পয়েন্ট আর শাখা আলাদা ঘরে, দুইবার নয়
 *   · সিঙ্কের দোকান — `branchName`; শাখার নাম বদলালে দোকানগুলো পরের টানায় আবার যায়
 *   · ⛔ সারি-প্রতি ডাক নয় — এক সারি আর তিন সারিতে শাখার প্রশ্নের সংখ্যা একই
 */
final class ThePhoneSendsTheCustomersBranchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $owner;

    private Customer $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->branch = $this->company->defaultBranch();
        CompanyContext::set($this->company->id, $this->branch->id);
        app(StandardChart::class)->install();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
        app(SettingsService::class)->set(PhoneModules::key('credit_control'), true);

        // ⓘ ভাষা যাই হোক একই নাম — মালিকের উদাহরণের "Super"
        $this->branch->forceFill(['name_en' => 'Super', 'name_bn' => 'Super'])->save();

        $point = Location::query()->create([
            'company_id' => $this->company->id, 'code' => 'PT-BR', 'name_en' => 'Kawran Bazar', 'name_bn' => 'কারওয়ান বাজার',
            'level' => Location::POINT, 'is_active' => true,
        ]);
        $this->shop = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->shop->forceFill(['location_id' => $point->id, 'branch_id' => $this->branch->id])->save();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
    }

    public function test_the_order_the_delivery_order_and_the_quotation_carry_the_branch_beside_the_point(): void
    {
        $body = ['customer' => (string) $this->shop->public_id, 'lines' => [['product' => (string) $this->product->public_id, 'qty' => '2']]];
        $this->phone();

        foreach (['orders' => 'orders', 'delivery-orders' => 'orders', 'quotations' => 'quotations'] as $door => $key) {
            $id = $this->postJson('/api/v1/sales/'.$door, $body)->assertCreated()->json('id');
            $list = $this->getJson('/api/v1/sales/'.$door)->assertOk();
            $this->assertSame('কারওয়ান বাজার', $list->json($key.'.0.customer.point'), "⛔ {$door}: তালিকায় পয়েন্ট নেই।");
            $this->assertSame('Super', $list->json($key.'.0.customer.branch'), "⛔ {$door}: তালিকায় গ্রাহকের শাখা নেই।");
            $paper = $this->getJson('/api/v1/sales/'.$door.'/'.$id)->assertOk();
            $this->assertSame('কারওয়ান বাজার', $paper->json('customer.point'), "⛔ {$door}: খোলা কাগজে পয়েন্ট নেই।");
            $this->assertSame('Super', $paper->json('customer.branch'), "⛔ {$door}: খোলা কাগজে গ্রাহকের শাখা নেই।");
        }
    }

    public function test_money_in_deliveries_return_bills_tracking_and_the_paper_lists_carry_point_and_branch(): void
    {
        $this->collection('COL-BR-1', $this->shop);
        SalesInvoice::query()->create([
            'branch_id' => $this->branch->id, 'document_no' => 'INV-BR-1', 'customer_id' => $this->shop->id,
            'trx_date' => now()->toDateString(), 'due_on' => now()->addDays(30)->toDateString(),
            'subtotal' => '500', 'discount' => '0', 'tax' => '0', 'total' => '500', 'status' => DocumentStatus::CONFIRMED,
        ]);
        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create([
            'customer_id' => $this->shop->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'delivered_qty' => '1', 'rate' => '10']]));
        // ⓘ "আজকের ডেলিভারি" কেবল পথে থাকা চালান দেখায় ([[DeliveryRunApiController]]) — তাই রওনা করানো
        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED);

        $this->phone();
        $range = '?from='.now()->subDay()->toDateString().'&to='.now()->toDateString();
        $rows = [
            'আদায়' => collect($this->getJson('/api/v1/sales/collections'.$range)->assertOk()->json('rows'))->firstWhere('no', 'COL-BR-1'),
            'আজকের ডেলিভারি' => collect($this->getJson('/api/v1/sales/deliveries')->assertOk()->json('rows'))->firstWhere('document_no', $challan->document_no),
            'ফেরতের বিল' => collect($this->getJson('/api/v1/sales/returns/setup')->assertOk()->json('invoices'))->firstWhere('no', 'INV-BR-1'),
            'ট্র্যাকিং' => collect($this->getJson('/api/v1/sales/tracking')->assertOk()->json('rows'))->firstWhere('document_no', $challan->document_no),
            'বিলের তালিকা' => collect($this->getJson('/api/v1/sales/invoices'.$range)->assertOk()->json('rows'))->firstWhere('no', 'INV-BR-1'),
            'চালানের তালিকা' => collect($this->getJson('/api/v1/sales/challans'.$range)->assertOk()->json('rows'))->firstWhere('no', $challan->document_no),
        ];

        foreach ($rows as $door => $row) {
            $this->assertNotNull($row, "⛔ {$door}: সারিটাই এল না — প্রস্তুতি ভুল।");
            $this->assertSame('Super', $row['customer_branch'] ?? null, "⛔ {$door}: গ্রাহকের পাশে শাখা নেই।");
        }
        // ⓘ পুরনো অ্যাপ যা পড়ে, তা যেমন ছিল — পয়েন্ট নিজের ঘরে, শাখা মেশানো নয়
        foreach (['আদায়', 'আজকের ডেলিভারি', 'ফেরতের বিল', 'ট্র্যাকিং'] as $door) {
            $this->assertSame('কারওয়ান বাজার', $rows[$door]['customer_point'] ?? null, "⛔ {$door}: পুরনো অ্যাপের পয়েন্টের ঘর বদলে গেল।");
        }
        foreach (['বিলের তালিকা', 'চালানের তালিকা'] as $door) {
            $this->assertSame('কারওয়ান বাজার', $rows[$door]['point'] ?? null, "⛔ {$door}: পুরনো অ্যাপের পয়েন্টের ঘর বদলে গেল।");
        }
    }

    public function test_the_customer_ledger_the_credit_page_and_the_supplier_list_name_the_branch(): void
    {
        $supplier = Supplier::query()->onlySuppliers()->orderBy('id')->firstOrFail();
        $supplier->forceFill(['branch_id' => $this->branch->id])->save();

        $this->phone();
        $ledger = $this->getJson('/api/v1/sales/customers/'.$this->shop->public_id.'/ledger')->assertOk();
        $this->assertSame('কারওয়ান বাজার', $ledger->json('point'), '⛔ গ্রাহকের খাতায় পয়েন্ট নেই।');
        $this->assertSame('Super', $ledger->json('branch'), '⛔ গ্রাহকের খাতায় শাখা নেই।');

        $credit = $this->getJson('/api/v1/credit-control/customers/'.$this->shop->public_id)->assertOk();
        $this->assertSame('কারওয়ান বাজার', $credit->json('point'), '⛔ বাকির পাতায় পয়েন্ট নেই।');
        $this->assertSame('Super', $credit->json('branch'), '⛔ বাকির পাতায় শাখা নেই।');

        // ⛔ বাকির তালিকায় নাম খালি — পয়েন্ট আর শাখা আলাদা ঘরে যায়, ফোন জোড়ে; নামে আবার জুড়লে দুইবার দেখাত
        $row = collect($this->getJson('/api/v1/credit-control/lists/positions?q='.urlencode((string) $this->shop->code))->assertOk()->json('rows'))
            ->firstWhere('customer', (string) $this->shop->public_id);
        $this->assertNotNull($row, '⛔ বাকির তালিকায় দোকানটা নেই — প্রস্তুতি ভুল।');
        $this->assertSame($this->shop->name(), $row['name'], '⛔ বাকির তালিকার নামে পয়েন্ট-শাখা জোড়া — ফোনে দুইবার দেখাত।');
        $this->assertSame('কারওয়ান বাজার', $row['point'] ?? null, '⛔ বাকির তালিকায় পয়েন্ট নেই।');
        $this->assertSame('Super', $row['branch'] ?? null, '⛔ বাকির তালিকায় শাখা নেই।');

        $row = collect($this->getJson('/api/v1/purchase/principals')->assertOk()->json('rows'))->firstWhere('id', (string) $supplier->public_id);
        $this->assertNotNull($row, '⛔ সরবরাহকারী তালিকায় নেই — প্রস্তুতি ভুল।');
        $this->assertSame('Super', $row['branch'] ?? null, '⛔ সরবরাহকারীর পাশে শাখা নেই।');
    }

    public function test_the_synced_shop_carries_its_branch_and_a_renamed_branch_sends_its_shops_again(): void
    {
        $sync = app(SyncService::class);
        $this->assertSame('Super', $this->pulled('phone-br')[$this->shop->public_id]['branchName'] ?? null, '⛔ সিঙ্কের দোকানে শাখার নাম নেই।');

        $this->travel(2)->minutes();
        $sync->pull($this->owner, 'phone-br2', 'customer', 500);
        $this->travel(2)->minutes();
        $sync->recordSuccessfulPull('phone-br2', 'customer');
        $this->travel(5)->minutes();
        $this->assertArrayNotHasKey($this->shop->public_id, $this->pulled('phone-br2'), 'প্রস্তুতিটাই ভুল — কিছু না বদলেও দোকান আবার এল।');

        $this->branch->forceFill(['name_en' => 'Super Group', 'name_bn' => 'Super Group'])->save();

        $shops = $this->pulled('phone-br2');
        $this->assertArrayHasKey($this->shop->public_id, $shops, '⛔ শাখার নাম বদলাল, কিন্তু দোকানটা ফোনে আবার গেল না — পুরনো নাম থেকে যেত।');
        $this->assertSame('Super Group', $shops[$this->shop->public_id]['branchName']);
    }

    public function test_the_branch_is_fetched_once_for_a_page_not_once_per_row(): void
    {
        $this->collection('COL-BR-A', $this->shop);
        $this->phone();
        $one = $this->branchQueries(fn () => $this->getJson('/api/v1/sales/collections')->assertOk());
        $oneSync = $this->branchQueries(fn () => $this->pulled('phone-q1'));

        foreach (Customer::query()->whereKeyNot($this->shop->id)->orderBy('id')->take(2)->get() as $i => $other) {
            $other->forceFill(['branch_id' => $this->branch->id])->save();
            $this->collection('COL-BR-'.($i + 2), $other);
        }
        $this->phone();
        $three = $this->branchQueries(fn () => $this->getJson('/api/v1/sales/collections')->assertOk());
        $threeSync = $this->branchQueries(fn () => $this->pulled('phone-q3'));

        $this->assertSame($one, $three, "⛔ আদায়ের তালিকায় শাখা সারি-প্রতি আনা হচ্ছে ({$one} → {$three})।");
        $this->assertSame($oneSync, $threeSync, "⛔ সিঙ্কে শাখা সারি-প্রতি আনা হচ্ছে ({$oneSync} → {$threeSync})।");
    }

    private function collection(string $no, Customer $customer): void
    {
        Collection::query()->create([
            'branch_id' => $this->branch->id, 'document_no' => $no, 'customer_id' => $customer->id,
            'account_id' => Account::query()->postable()->firstOrFail()->id,
            'trx_date' => now()->toDateString(), 'amount' => '100', 'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    /** `branches` টেবিলে কতগুলো প্রশ্ন গেল */
    private function branchQueries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = collect(DB::getQueryLog())->filter(fn (array $q) => preg_match('/from [`"]?branches[`"]?/i', (string) $q['query']) === 1)->count();
        DB::disableQueryLog();

        return $count;
    }

    /** @return array<string, array<string, mixed>> দোকানের public_id ধরে payload */
    private function pulled(string $device): array
    {
        $records = app(SyncService::class)->pull($this->owner, $device, 'customer', 500)['records'];

        return collect($records)->where('entityType', 'Customer')->mapWithKeys(fn (array $r) => [$r['entityId'] => json_decode($r['payloadJson'], true)])->all();
    }

    private function phone(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
    }
}
