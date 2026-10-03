<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * সারি থেকেই "পৌঁছেছে" — মালিক, ৩ অক্টোবর ২০২৬: "sob jaygathekei"।
 *
 * ⭐ চালান, ইনভয়েস, ট্র্যাকিং আর পরিবহন বরাদ্দের "গেট পাস হয়েছে" — চারটাতেই রওনা হওয়া চালানের সারিতে "পৌঁছেছে"
 * ফর্ম, যা যায় পুরনো `sales.delivery.move`-এ; চেপে সেই তালিকাতেই ফেরা, আর বোতামটা চলে যায়।
 * ⛔ একই মানুষ: ধাপ বদলানোর চাবি (`sales.delivery.update`) ছাড়া কোথাও বোতাম নেই, আর সরাসরি POST-ও ৪০৩।
 * ⛔ রওনার আগের চালানে কোনো তালিকায় বোতাম নেই।
 */
final class TheDeliveryIsConfirmedFromEveryListTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->lotForTheBiscuit();
    }

    /**
     * ⓘ লট ছাড়া বিক্রি নয় (মালিক, ৩০ সেপ্টেম্বর ২০২৬) — ডেমোর বিস্কুটের মজুদ লট বসার আগের, তাই একটা লট আর তাতে মাল।
     */
    private function lotForTheBiscuit(): void
    {
        $product = \App\Modules\Inventory\Models\Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $batch = \App\Modules\Inventory\Models\Batch::query()->create([
            'company_id' => $this->company->id, 'product_id' => $product->id, 'batch_no' => 'TST-LOT', 'expiry_date' => now()->addYear()->toDateString(),
        ]);

        \App\Modules\Inventory\Models\StockMovement::query()->create([
            'company_id' => $this->company->id, 'product_id' => $product->id,
            'warehouse_id' => \App\Modules\Inventory\Models\Warehouse::query()->where('is_default', true)->value('id'),
            'batch_id' => $batch->id, 'trx_date' => now()->toDateString(), 'floor_change' => '50',
            'source_type' => 'test', 'source_id' => 1, 'document_no' => 'TST-LOT-IN',
        ]);
    }

    public function test_every_list_offers_arrived_after_dispatch_only_and_only_to_the_key(): void
    {
        $early = $this->challan();
        $gone = $this->challan();
        app(DeliveryStageService::class)->move($gone, DeliveryStage::DISPATCHED);

        $invoice = SalesInvoice::query()
            ->whereHas('lines', fn ($q) => $q->whereIn('delivery_challan_line_id', $gone->lines()->pluck('id')))
            ->firstOrFail();
        $earlyNo = $early->document_no;

        foreach (['sales.challan.view', 'sales.invoice.view', 'sales.delivery.view'] as $key) {
            $this->grant($key);
        }

        $lists = [
            'challan' => route('sales.challan.index', ['q' => $gone->document_no]),
            'invoice' => route('sales.invoice.index', ['q' => $invoice->document_no]),
            'tracking' => route('sales.tracking.index', ['q' => $gone->document_no]),
            'transport' => route('sales.transport.index', ['tab' => 'passed', 'q' => $gone->document_no]),
        ];
        $form = 'action="'.e(route('sales.delivery.move', $gone)).'"';

        // ⛔ চাবি ছাড়া — তালিকা খোলে, বোতাম নেই; সরাসরি POST-ও বন্ধ
        foreach ($lists as $name => $url) {
            $html = (string) $this->actingAs($this->clerk)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($form, $html, "⛔ {$name}: ধাপ বদলানোর চাবি ছাড়াই \"পৌঁছেছে\"।");
        }

        $this->actingAs($this->clerk)->post(route('sales.delivery.move', $gone), [
            'stage' => DeliveryStage::DELIVERED, 'receiver_name' => 'Shop',
        ])->assertForbidden();

        // ⭐ একই মানুষ, চাবিসহ — চারটা তালিকাতেই
        $this->grant('sales.delivery.update');

        foreach ($lists as $name => $url) {
            $html = (string) $this->actingAs($this->clerk)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString($form, $html, "{$name}: রওনা হওয়া চালানের সারিতে \"পৌঁছেছে\" নেই।");
        }

        // ⛔ রওনার আগের চালান — কোনো তালিকায় বোতাম নয়
        $earlyForm = 'action="'.e(route('sales.delivery.move', $early)).'"';
        foreach ([route('sales.challan.index', ['q' => $earlyNo]), route('sales.tracking.index', ['q' => $earlyNo]),
            route('sales.transport.index', ['tab' => 'unassigned', 'q' => $earlyNo])] as $url) {
            $this->assertStringNotContainsString($earlyForm, (string) $this->actingAs($this->clerk)->get($url)->assertOk()->getContent(),
                '⛔ রওনার আগের চালানে "পৌঁছেছে"।');
        }

        // ⭐ ইনভয়েস তালিকা থেকে চাপা — সেখানেই ফেরা, ধাপ "পৌঁছেছে", বোতাম চলে যায়
        $this->actingAs($this->clerk)->from($lists['invoice'])->post(route('sales.delivery.move', $gone), [
            'stage' => DeliveryStage::DELIVERED, 'receiver_name' => 'Shop Owner',
        ])->assertRedirect($lists['invoice']);

        $this->assertSame(DeliveryStage::DELIVERED, (string) app(DeliveryStageService::class)->ensure($gone->fresh())->stage);
        $this->assertStringNotContainsString($form, (string) $this->actingAs($this->clerk)->get($lists['invoice'])->assertOk()->getContent(),
            '⛔ পৌঁছানোর পরেও "পৌঁছেছে" বোতাম।');
    }

    private function challan(): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'delivered_qty' => '1', 'rate' => '10']]));
    }

    private function grant(string $key): void
    {
        Permission::findOrCreate($key, 'web');
        CompanyContext::forCompany($this->company->id, fn () => $this->clerk->givePermissionTo($key));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->clerk = $this->clerk->fresh();
    }
}
