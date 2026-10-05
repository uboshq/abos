<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Services\DeliveryStage;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "মাল কীভাবে নেবে" — তিন মোড, মালিক, ৪ অক্টোবর ২০২৬ (সংস্করণ ২; D365 Store Commerce / SAP কাউন্টারের ধাঁচ)।
 *
 *   এখনই নিয়ে যাবেন (Cash Sale)  এক চাপে গেট পাস আর "পৌঁছেছে", গ্রহণকারী ক্রেতা নিজে
 *   পরে পাঠানো হবে               ঠিকানা আর তারিখসহ, ডেলিভারির তালিকায় অপেক্ষা — গেট পাস মাল বেরোনোর দিন
 *   পরে নিয়ে যাবেন               ক্রেতার নামে অপেক্ষা — গেট পাস নেওয়ার দিন
 * ⓘ মোড না পাঠালে (ফোন, পুরনো পথ) আজকের আচরণ — অপেক্ষা।
 */
final class TheCounterHandsOverWhenTakenNowTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '1000000'])->save();
    }

    /** ⭐ এখনই নিয়ে যাবেন — একই চাপে গেট পাস, "পৌঁছেছে", গ্রহণকারী ক্রেতা */
    public function test_taken_now_passes_the_gate_and_is_delivered_in_the_same_press(): void
    {
        $challan = $this->sell(['delivery_mode' => 'take_now']);

        $this->assertSame(1, $this->passes($challan), '⛔ এখনই নেওয়া মালের গেট পাস হয়নি।');
        $this->assertSame(DeliveryStage::DELIVERED, $this->stage($challan), '⛔ এখনই নেওয়া মাল "পৌঁছেছে" নয়।');
    }

    /** পরে পাঠানো হবে, পরে নিয়ে যাবেন, আর মোড না পাঠানো — তিনটাই অপেক্ষা করে, গেট পাস নেই */
    public function test_later_modes_and_no_mode_wait_for_delivery(): void
    {
        foreach ([
            ['delivery_mode' => 'send_later', 'ship_to' => 'কাপ্তান বাজার', 'ship_date' => now()->addDay()->toDateString()],
            ['delivery_mode' => 'pickup_later'],
            [],
        ] as $extra) {
            // ⓘ একই কার্ট তিনবার, জেনেশুনে — "আবার করুন" টিক ([[DirectSaleService::refuseARepeatBill()]])
            $challan = $this->sell([...$extra, 'confirm_duplicate' => '1']);
            $mode = $extra['delivery_mode'] ?? 'না পাঠানো';

            $this->assertSame(0, $this->passes($challan), "⛔ {$mode}: মাল বেরোনোর আগেই গেট পাস।");
            $this->assertNotSame(DeliveryStage::DELIVERED, $this->stage($challan), "⛔ {$mode}: অপেক্ষার বিক্রি \"পৌঁছেছে\"।");
        }
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function sell(array $extra): DeliveryChallan
    {
        $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'vehicle_owner' => 'customer',
            'lines' => [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
            ...$extra,
        ])->assertSessionHasNoErrors();

        return DeliveryChallan::query()->latest('id')->firstOrFail();
    }

    private function passes(DeliveryChallan $challan): int
    {
        return GatePass::query()->where('delivery_challan_id', $challan->id)->where('status', '<>', GatePass::CANCELLED)->count();
    }

    private function stage(DeliveryChallan $challan): ?string
    {
        return DeliveryState::query()->where('delivery_challan_id', $challan->id)->value('stage');
    }
}
