<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Widgets;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের "মাল আসছে" উইজেট — `GET /api/v1/widgets/summary` → `widgets.goods_incoming` (মালিকের উইজেট-নকশা ১৫, ২০ অক্টোবর
 * ২০২৬; [[GoodsIncomingSummary]])।
 *
 * দাবি, আসল HTTP দিয়ে:
 *  - এই সপ্তাহের (শুক্রবার পর্যন্ত) নিশ্চিত ক্রয়াদেশ আর দেরির আদেশ, তারিখ ধরে আগে, তিনটা; গোনা সবগুলো, দেরির গোনা আলাদা;
 *  - দেরির আদেশ `late`, বাকিগুলো নয়; পরের সপ্তাহের, তারিখ ছাড়া, খসড়া আর বাতিল আদেশ নেই;
 *  - সারিতে সরবরাহকারী আর তার শাখা, নম্বর, তারিখ, বাকি পরিমাণ আর একক, অঙ্ক (bcmath স্ট্রিং), আর আদেশের পাতার লিংক;
 *  - ⛔ ক্রয়মূল্যের চাবি (`inventory.cost.view`) ছাড়া `amount` ঘরটাই নেই — ওয়েবের ক্রয়াদেশের পাতার একই নিয়ম;
 *  - ⛔ ক্রয়াদেশের চাবি ছাড়া উইজেটটাই নেই।
 *
 * ⓘ লেখা হয়েছে ২০ অক্টোবর, Mac বন্ধ থাকায় চালানো হয়নি (ec)।
 */
final class TheGoodsIncomingWidgetMarksTheLateOrderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set((int) $this->company->id, null);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => null, 'view_all_branches' => true])->save();
        $this->actingAs($this->owner);

        // ⓘ নমুনার খোলা আদেশ সরানো — কেবল এই টেস্টের আদেশ, তাই ক্রম আর গোনা নিশ্চিত
        PurchaseOrder::query()->where('status', DocumentStatus::CONFIRMED)->update(['status' => DocumentStatus::CLOSED]);

        // ⓘ ঘড়ি বুধবারে — সপ্তাহের শেষ শুক্রবার ২৩ অক্টোবর
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_this_weeks_orders_come_by_date_and_the_late_one_is_marked(): void
    {
        $late = $this->order('PO-0209', '2026-10-16', '106577.00');
        $tomorrow = $this->order('PO-0215', '2026-10-22', '680000.00', '400');
        $friday = $this->order('PO-0217', '2026-10-23', '390000.00');
        $this->order('PO-0218', '2026-10-23', '1000.00');
        $this->order('PO-NEXT', '2026-10-24', '5000.00');
        $this->order('PO-NODATE', null, '5000.00');
        $this->order('PO-DRAFT', '2026-10-22', '5000.00', '10', DocumentStatus::DRAFT);
        $this->order('PO-GONE', '2026-10-22', '5000.00', '10', DocumentStatus::CANCELLED);

        $widget = $this->widget($this->owner);

        $this->assertSame(['PO-0209', 'PO-0215', 'PO-0217'], array_column($widget['rows'], 'no'), '⛔ তারিখ ধরে আগে নয়, বা তিনটার বেশি/কম।');
        $this->assertSame(4, $widget['count'], '⛔ এই সপ্তাহের চারটা — পরের সপ্তাহ, তারিখ ছাড়া, খসড়া আর বাতিল নয়।');
        $this->assertSame(1, $widget['late'], '⛔ দেরি একটাই।');
        $this->assertSame('/purchase/orders', $widget['link']);
        $this->assertSame([true, false, false], array_column($widget['rows'], 'late'), '⛔ দেরির চিহ্ন ভুল আদেশে।');

        $row = $widget['rows'][1];
        $this->assertSame('2026-10-22', $row['expected']);
        $this->assertSame('680000.00', $row['amount']);
        $this->assertSame('400', $row['qty']);
        $this->assertSame('/purchase/orders/'.$tomorrow->id, $row['link']);
        $supplier = $tomorrow->supplier()->with('branch')->first();
        $this->assertSame($supplier->name(), $row['supplier']);
        $this->assertSame($supplier->branch?->name(), $row['supplier_branch']);
        $this->assertSame('/purchase/orders/'.$late->id, $widget['rows'][0]['link']);
        $this->assertSame('/purchase/orders/'.$friday->id, $widget['rows'][2]['link']);
    }

    public function test_an_order_due_today_is_this_weeks_and_not_yet_late(): void
    {
        $today = Carbon::today()->toDateString();
        $this->order('PO-TODAY', $today, '1000.00');

        $widget = $this->widget($this->owner);
        $this->assertSame(['PO-TODAY'], array_column($widget['rows'], 'no'), 'প্রস্তুতিটাই ভুল — আজকের আদেশটা আসেনি');
        $this->assertSame($today, $widget['rows'][0]['expected']);
        $this->assertFalse($widget['rows'][0]['late'], '⛔ আজ আসার কথা, দিন শেষ হয়নি — তবু "দেরি" দাগ পড়ল।');
        $this->assertSame(0, $widget['late'], '⛔ আজকের আদেশ দেরির গোনায়।');
    }

    public function test_the_amount_needs_the_cost_key_and_the_widget_needs_the_order_key(): void
    {
        $this->order('PO-0215', '2026-10-22', '680000.00');

        $clerk = $this->staff(['purchase.order.view']);
        $row = $this->widget($clerk)['rows'][0];
        $this->assertSame('PO-0215', $row['no'], 'প্রস্তুতিটাই ভুল — চাবিধারী আদেশটা দেখেন।');
        $this->assertArrayNotHasKey('amount', $row, '⛔ ক্রয়মূল্যের চাবি ছাড়া অঙ্ক গেল — ওয়েব ঢাকে।');

        $nobody = $this->staff(['sales.order.view']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($nobody, [AuthController::APP]);
        $widgets = $this->getJson('/api/v1/widgets/summary')->assertOk()->json('widgets');
        $this->assertArrayNotHasKey('goods_incoming', (array) $widgets, '⛔ ক্রয়াদেশের চাবি ছাড়া উইজেট গেল।');
    }

    private function order(string $no, ?string $expected, string $total, string $qty = '10', string $status = DocumentStatus::CONFIRMED): PurchaseOrder
    {
        $order = PurchaseOrder::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()->id, 'document_no' => $no,
            'supplier_id' => Supplier::query()->orderBy('id')->value('id'), 'trx_date' => '2026-10-10', 'expected_on' => $expected,
            'subtotal' => $total, 'total' => $total, 'status' => $status, 'created_by' => $this->owner->id,
        ]);
        $order->lines()->create([
            'product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id,
            'ordered_qty' => $qty, 'rate' => bcdiv($total, $qty, 4), 'amount' => $total, 'line_no' => 1,
        ]);

        return $order->fresh();
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->company->id, fn () => $user->givePermissionTo(
            ...array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    private function widget(User $user): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
        $widget = $this->getJson('/api/v1/widgets/summary?keys=goods_incoming')->assertOk()->json('widgets.goods_incoming');
        $this->assertIsArray($widget, 'প্রস্তুতিটাই ভুল — চাবিধারী উইজেট পান।');

        return $widget;
    }
}
