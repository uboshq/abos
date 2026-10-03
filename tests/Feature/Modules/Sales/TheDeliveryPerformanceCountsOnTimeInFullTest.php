<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryEvent;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryStage;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ডেলিভারির মাপকাঠি — OTIF %, আদেশ থেকে রওনার গড়, দেরির তালিকা (মালিকের বিক্রয়-পরিকল্পনা, ৪ অক্টোবর ২০২৬)।
 *
 * পাঁচটা চালান, সব আজকের:
 *   ক — আদেশ আজকের জন্য, আজ পৌঁছেছে, পুরো                    → সময়মতো, পুরো, OTIF
 *   খ — আদেশ নেই (প্রতিশ্রুতি = চালানের দিন), আজ আংশিক          → সময়মতো, পুরো নয়
 *   গ — আদেশ গতকালের জন্য, আজ পৌঁছেছে, পুরো                   → পুরো, দেরি
 *   ঘ — আদেশ পরশুর জন্য, এখনো পথে                              → দেরি, পৌঁছায়নি
 *   ঙ — আদেশ আগামীকালের জন্য, এখনো পথে                         → এখনো সময় আছে — হিসাবেই নেই
 * ⇒ সময় পেরোনো ৪, সময়মতো ২, পুরো ২, OTIF ১ = ২৫.০%; দেরি: ঘ, তারপর গ; আদেশ থেকে রওনা কেবল ক-তে, ২৬ ঘণ্টা।
 * ⛔ একই মানুষ, `sales.delivery.view` ছাড়া পাতাই নয়।
 */
final class TheDeliveryPerformanceCountsOnTimeInFullTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private int $n = 0;

    public function test_otif_lead_time_and_the_late_list(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ ডেমোর নিজের চালানগুলো এ মাসের বাইরে — গোনা যেন কেবল এই পাঁচটার
        DeliveryChallan::query()->update(['trx_date' => now()->subYear()->toDateString()]);

        $a = $this->challan('ZPF-A', now()->toDateString(), DeliveryStage::DELIVERED, arrived: true, dispatchedAfterHours: 26);
        $this->challan('ZPF-B', null, DeliveryStage::PARTIALLY_DELIVERED, arrived: true);
        $c = $this->challan('ZPF-C', now()->subDay()->toDateString(), DeliveryStage::DELIVERED, arrived: true);
        $d = $this->challan('ZPF-D', now()->subDays(2)->toDateString(), DeliveryStage::DISPATCHED, arrived: false);
        $this->challan('ZPF-E', now()->addDay()->toDateString(), DeliveryStage::DISPATCHED, arrived: false);

        $this->actingAs($clerk)->get(route('sales.delivery_performance.index'))->assertForbidden();

        Permission::findOrCreate('sales.delivery.view', 'web');
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo('sales.delivery.view'));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->actingAs($clerk->fresh())->get(route('sales.delivery_performance.index'))->assertOk();

        $this->assertSame(
            ['due' => 4, 'otif' => 1, 'on_time' => 2, 'in_full' => 2, 'percent' => '25.0', 'avg_hours' => 26, 'late' => 2],
            $response->viewData('summary'),
            '⛔ মাপকাঠি ভুল — সামনের চালান গোনা, আংশিককে পুরো ধরা, বা দেরিকে সময়মতো।',
        );

        $this->assertSame([$d->id, $c->id], collect($response->viewData('late')->items())->pluck('id')->all(),
            '⛔ দেরির তালিকা ভুল — বা সবচেয়ে পুরনো প্রতিশ্রুতি উপরে নেই।');

        $html = (string) $response->getContent();
        $this->assertStringContainsString('25.0%', $html, 'পাতায় OTIF % নেই।');
        $this->assertStringContainsString(e(__('sales::delivery_performance.not_yet')), $html, 'পৌঁছায়নি চালানে "এখনো পৌঁছায়নি" লেখা নেই।');
        $this->assertStringNotContainsString(e(route('sales.challan.show', $a)).'"', $this->lateTable($html), '⛔ সময়মতো চালান দেরির তালিকায়।');
    }

    private function challan(string $no, ?string $deliverOn, string $stage, bool $arrived, ?int $dispatchedAfterHours = null): DeliveryChallan
    {
        $customer = Customer::query()->value('id');
        $order = null;

        if ($deliverOn !== null) {
            $order = SalesOrder::query()->forceCreate([
                'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
                'document_no' => 'SO-'.$no, 'customer_id' => $customer, 'trx_date' => now()->toDateString(),
                'deliver_on' => $deliverOn, 'subtotal' => '0', 'total' => '0', 'status' => DocumentStatus::CONFIRMED,
                'created_at' => now()->subHours($dispatchedAfterHours ?? 1), 'updated_at' => now(),
            ]);
        }

        $challan = DeliveryChallan::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => $no, 'customer_id' => $customer, 'sales_order_id' => $order?->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(), 'total' => '0', 'status' => DocumentStatus::CONFIRMED,
        ]);

        // ⓘ নিশ্চিত চালান তৈরি হলেই ধাপের সারি নিজে বসে — তাই নতুন নয়, সেটাই হালনাগাদ
        DeliveryState::query()->updateOrCreate(
            ['delivery_challan_id' => $challan->id],
            ['company_id' => $this->company->id, 'stage' => $stage, 'stage_at' => now()],
        );

        // ⓘ রওনার ঘটনা কেবল যেটার আদেশ-থেকে-রওনা মাপা হবে — বাকিদের থাকলে গড়টা ঐ চালানগুলোও টানত
        if ($dispatchedAfterHours !== null) {
            $this->event($challan, DeliveryStage::DISPATCHED, now());
        }

        if ($arrived) {
            $this->event($challan, $stage, now());
        }

        return $challan;
    }

    private function event(DeliveryChallan $challan, string $to, $at): void
    {
        DeliveryEvent::query()->forceCreate([
            'company_id' => $this->company->id, 'delivery_challan_id' => $challan->id, 'to_stage' => $to,
            'source' => DeliveryStage::BY_HAND, 'occurred_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function lateTable(string $html): string
    {
        $start = strpos($html, '<table');

        return $start === false ? '' : substr($html, $start);
    }
}
