<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Http\Controllers\PlannedScreenController;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * পরিবহন বরাদ্দ ছিল "তৈরি হচ্ছে" পাতা — মালিক, ৩ অক্টোবর ২০২৬: "Delivery Processing er kaj ke korteche?"।
 *
 * ⭐ নিশ্চিত চালান তিন ট্যাবে, প্রতিটা ঠিক একটায়: পরিবহন ঠিক হয়নি · ঠিক হয়েছে · গেট পাস হয়েছে।
 * ⭐ সারির বোতাম চালানের নিজের পরিবহন-পপআপ খোলে; সেখানে সেভ করলে এই তালিকাতেই ফেরা, আর চালান পরের ট্যাবে।
 * ⛔ একই মানুষ: `sales.challan.view` ছাড়া পাতাই নয়; দেখার চাবিতে পাতা আছে কিন্তু বোতাম নেই; `sales.challan.create`-এ বোতাম।
 * ⛔ গেট পাসের পরে বোতাম নেই (abos-af-এর নিয়ম); অন্য কোম্পানির চালান তালিকায় নেই।
 */
final class TheTransportWasAssignedOnAPlannedPageTest extends TestCase
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

        // ⓘ চালান আর গেট পাস বানায় মালিক — কেরানির চাবি আলাদা করে দেওয়া-নেওয়া হয়
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

    public function test_the_three_tabs_the_one_actor_twice_and_the_way_back_to_the_list(): void
    {
        $open = $this->challan([]);
        $named = $this->challan(['vehicle_no' => 'DHA-METRO-11', 'driver_name' => 'Rahim Driver', 'driver_phone' => '01711111111', 'transport_cost' => '450']);
        $gone = $this->challan(['own_transport' => true]);
        app(DeliveryStageService::class)->move($gone, DeliveryStage::DISPATCHED);

        $this->assertNotNull(\App\Modules\Sales\Models\GatePass::query()->where('delivery_challan_id', $gone->id)->value('id'),
            'রওনায় গেট পাস হয়নি — তৃতীয় ট্যাবের দাবিটা কিছুই মাপত না।');

        // ⛔ চাবি ছাড়া — পাতাই নয়
        $this->actingAs($this->clerk)->get(route('sales.transport.index'))->assertForbidden();

        // ⓘ দেখার চাবি — পাতা আছে, বোতাম নেই
        $this->grant('sales.challan.view');
        $html = $this->page('unassigned', $open);
        $this->assertStringContainsString($this->row($open), $html, 'পরিবহন-ছাড়া চালান "ঠিক হয়নি" ট্যাবে নেই।');
        $this->assertStringNotContainsString('data-transport-assign', $html, '⛔ বসানোর চাবি ছাড়াই "পরিবহন বসান" বোতাম।');

        // ⭐ একই মানুষ, বসানোর চাবি — বোতাম আসে
        $this->grant('sales.challan.create');

        foreach ([[$open, 'unassigned', true], [$named, 'assigned', true], [$gone, 'passed', false]] as [$challan, $tab, $button]) {
            $html = $this->page($tab, $challan);
            $this->assertStringContainsString($this->row($challan), $html, "{$challan->document_no} \"{$tab}\" ট্যাবে নেই।");

            foreach (array_diff(['unassigned', 'assigned', 'passed'], [$tab]) as $other) {
                $this->assertStringNotContainsString($this->row($challan), $this->page($other, $challan),
                    "⛔ {$challan->document_no} \"{$other}\" ট্যাবেও — একটা চালান দুই ট্যাবে।");
            }

            $door = e(route('sales.challan.transport', [$challan, 'from' => 'transport']));
            $button
                ? $this->assertStringContainsString($door, $html, "{$challan->document_no}: সারিতে পরিবহনের পপআপের বোতাম নেই।")
                : $this->assertStringNotContainsString($door, $html, "⛔ {$challan->document_no}: গেট পাসের পরেও বদলানোর বোতাম।");

            $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('sales.challan.show', $challan), '/').'"[^>]*data-row-view/', $html,
                "{$challan->document_no}: সারিতে \"দেখুন\" নেই।");
        }

        // ⭐ ছাঁকনি — চালক আর গ্রাহক; সর্বমোটে ভাড়া
        $byDriver = (string) $this->actingAs($this->clerk)->get(route('sales.transport.index', ['tab' => 'assigned', 'driver' => 'Rahim']))->assertOk()->getContent();
        $this->assertStringContainsString($this->row($named), $byDriver, 'চালকের নামে খুঁজে চালান পাওয়া গেল না।');
        $this->assertStringContainsString(\App\Core\Support\Money::format('450'), $byDriver, 'ভাড়া বা তার সর্বমোট নেই।');

        $nobody = (string) $this->actingAs($this->clerk)->get(route('sales.transport.index', ['tab' => 'assigned', 'driver' => 'No Such Driver']))->assertOk()->getContent();
        $this->assertStringNotContainsString($this->row($named), $nobody, '⛔ চালকের ছাঁকনি কিছুই ছাঁকে না।');

        // ⭐ পপআপে সেভ → তালিকায় ফেরা, চালান "ঠিক হয়েছে"-তে
        $this->actingAs($this->clerk)->put(route('sales.challan.transport.update', $open), [
            'mode' => 'vehicle', 'vehicle_no' => 'DHA-NEW-22', 'driver_name' => 'Karim', 'from' => 'transport',
        ])->assertRedirect(route('sales.transport.index'));

        $this->assertStringContainsString($this->row($open), $this->page('assigned', $open), 'পরিবহন বসানোর পরেও চালান "ঠিক হয়েছে"-তে আসেনি।');

        // ⛔ অন্য কোম্পানির চালান
        $other = Company::create(['code' => 'OTH', 'name_en' => 'Other Co']);
        DeliveryChallan::withoutGlobalScopes()->whereKey($named->id)->update(['company_id' => $other->id]);
        $this->assertStringNotContainsString($this->row($named), $this->page('assigned', $named), '⛔ অন্য কোম্পানির চালান তালিকায়।');
    }

    public function test_the_planned_page_is_gone_and_the_menu_points_at_the_real_one(): void
    {
        $this->assertNotContains('transport_assign', PlannedScreenController::SCREENS, '"তৈরি হচ্ছে" তালিকায় এখনো পরিবহন বরাদ্দ।');

        $item = collect(app(\App\Core\Module\ModuleRegistry::class)->get('sales')->menu)->flatten(1)
            ->first(fn ($i) => is_array($i) && ($i['label'] ?? null) === 'sales::planned.transport_assign');
        $this->assertNotNull($item, 'মেনুতে পরিবহন বরাদ্দ নেই।');
        $this->assertSame('sales.transport.index', $item['route'], 'মেনুর সারি এখনো "তৈরি হচ্ছে" পাতায় যায়।');
        $this->assertSame('sales.challan.view', $item['permission']);
    }

    /** @param array<string, mixed> $transport */
    private function challan(array $transport): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);
        $challan = $service->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'delivered_qty' => '1', 'rate' => '10']]);

        $challan = $service->confirm($challan);

        if ($transport !== []) {
            $challan->forceFill($transport)->save();
        }

        return $challan->fresh();
    }

    private function grant(string $key): void
    {
        Permission::findOrCreate($key, 'web');
        CompanyContext::forCompany($this->company->id, fn () => $this->clerk->givePermissionTo($key));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->clerk = $this->clerk->fresh();
    }

    /** ⓘ সারির "দেখুন" লিংক — খোঁজার ঘরেও নম্বরটা লেখা থাকে, তাই নম্বর দিয়ে সারি চেনা যায় না */
    private function row(DeliveryChallan $challan): string
    {
        return 'href="'.e(route('sales.challan.show', $challan)).'"';
    }

    private function page(string $tab, DeliveryChallan $challan): string
    {
        return (string) $this->actingAs($this->clerk)
            ->get(route('sales.transport.index', ['tab' => $tab, 'q' => $challan->document_no]))
            ->assertOk()->getContent();
    }
}
