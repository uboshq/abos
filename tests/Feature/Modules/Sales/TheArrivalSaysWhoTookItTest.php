<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Reports\DeliveryReports;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\SaleTracking;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ পৌঁছানোর প্রমাণ সময়রেখায় আর রিপোর্টে — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৭, ৬ অক্টোবর ২০২৬।
 *
 * ⭐ দাবি:
 *   · সময়রেখার "পৌঁছেছে" ধাপে কে বুঝে নিলেন (নাম · ফোন), আর আংশিক হলেও কখন পৌঁছাল; চালানের পাতায় দেখা যায়
 *   · চালানের অবস্থার রিপোর্টে "বুঝে নিলেন" আর "ভাঙা" — একই ঘটনার খাতা থেকে
 *   · পৌঁছায়নি এমন চালানে কিছুই নয়
 */
final class TheArrivalSaysWhoTookItTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_delivered_step_and_the_status_report_name_the_receiver_and_the_broken_count(): void
    {
        $arrived = $this->dispatched();
        $waiting = $this->dispatched();
        [$biscuit, $tea] = $arrived->lines()->orderBy('line_no')->get()->all();

        app(DeliveryStageService::class)->move($arrived, DeliveryStage::PARTIALLY_DELIVERED, [
            'receiver_name' => 'রহিম', 'receiver_phone' => '01711-000000',
            'lines' => [$biscuit->id => '3', $tea->id => '3'], 'damaged' => [$biscuit->id => '2'],
        ]);

        $step = collect(app(SaleTracking::class)->timeline($arrived->fresh()))->firstWhere('step', 'delivered');
        $this->assertSame('রহিম · 01711-000000', $step['receiver'], '⛔ "পৌঁছেছে" ধাপে কে বুঝে নিলেন নেই।');
        $this->assertNotNull($step['at'], '⛔ আংশিক পৌঁছানোর সময় ধাপে নেই।');
        $this->assertNull(collect(app(SaleTracking::class)->timeline($waiting->fresh()))->firstWhere('step', 'delivered')['receiver']);

        $page = (string) $this->get(route('sales.challan.show', $arrived))->assertOk()->getContent();
        $this->assertStringContainsString('data-timeline-receiver', $page);
        $this->assertStringContainsString('রহিম · 01711-000000', $page);

        $rows = collect(app(ReportEngine::class)->run(DeliveryReports::CHALLAN_STATUS,
            ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()], perPage: 100)->rows);
        $mine = $rows->firstWhere('document_no', $arrived->document_no);
        $this->assertSame('রহিম · 01711-000000', $mine['received_by'], '⛔ রিপোর্টে কে বুঝে নিলেন নেই।');
        $this->assertSame(0, bccomp('2', (string) $mine['damaged_qty'], 4), '⛔ রিপোর্টে ভাঙার যোগ নেই।');

        $other = $rows->firstWhere('document_no', $waiting->document_no);
        $this->assertTrue(in_array($other['received_by'], [null, ''], true), '⛔ পৌঁছায়নি এমন চালানে নেওয়া-লোক।');
        $this->assertSame(0, bccomp('0', (string) $other['damaged_qty'], 4));
    }

    private function dispatched(): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);
        $challan = $service->confirm($service->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
        ], [
            ['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10'],
            ['product_id' => Product::query()->where('name_en', 'Premium Tea 250gm')->firstOrFail()->id, 'delivered_qty' => '3', 'rate' => '165'],
        ]));

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);

        return $challan->fresh();
    }
}
