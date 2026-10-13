<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\PricingRule;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মেয়াদ পেরোনো বাছা লট চালান বা গেট পাস দিয়ে বেরোয় না — Sales লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (a4-এর মজুদ নিরীক্ষা, fe)।
 *
 * ⛔ আগে পাহারা কেবল কাউন্টারে ছিল; চালান আর গেট পাস বাছা লট সরাসরি মজুদের সেবায় দিত, যেখানে কেবল পরিমাণ দেখা হয় — আটকানো চালানের
 * লট গেট পাসের দিন মেয়াদ পেরোলেও মাল বেরিয়ে যেত।
 */
final class AnExpiredLotDoesNotLeaveOnAChallanOrGatePassTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
        app(SettingsService::class)->set(PricingRule::POLICY, PricingRule::ALLOW);

        $this->product = app(ProductService::class)->create([
            'name_en' => 'Expiry Lot Juice',
            'name_bn' => 'মেয়াদি লটের জুস',
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => '10',
            'sale_price' => '12',
            'reorder_level' => '0',
        ]);
    }

    public function test_an_expired_chosen_lot_is_refused_on_the_challan_and_at_the_gate_and_a_good_one_leaves(): void
    {
        $good = $this->lot('GOOD-1', null);
        $this->assertSame(DocumentStatus::CONFIRMED, app(DeliveryChallanService::class)->confirm($this->challan($good))->status,
            'দৃশ্যটাই ভুল — ভালো লটও বেরোল না।');

        $old = $this->lot('OLD-1', now()->subDay()->toDateString());
        $challan = $this->challan($old);

        $this->assertStringContainsString('OLD-1', $this->refused(fn () => app(DeliveryChallanService::class)->confirm($challan)),
            '⛔ মেয়াদ পেরোনো লট চালানে বেরিয়ে গেল।');
        $this->assertSame(DocumentStatus::DRAFT, $challan->fresh()->status);

        // ⓘ গেট পাসের দরজা — আটকানো চালানের লট আজ মেয়াদ পেরিয়েছে
        $this->assertStringContainsString('OLD-1', $this->refused(fn () => app(DeliveryChallanService::class)->issueHeldGoods($challan->fresh())),
            '⛔ মেয়াদ পেরোনো লট গেট পাসে বেরিয়ে গেল।');
    }

    private function challan(Batch $lot): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
            'vehicle_no' => 'DHA-GA-11-2233',
        ], [['product_id' => $this->product->id, 'batch_id' => $lot->id, 'delivered_qty' => '1', 'rate' => '12']]);
    }

    private function lot(string $no, ?string $expiry): Batch
    {
        $batch = Batch::query()->create([
            'company_id' => CompanyContext::id(),
            'product_id' => $this->product->id,
            'batch_no' => $no,
            'expiry_date' => $expiry,
        ]);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: Warehouse::query()->where('is_default', true)->firstOrFail(),
            sourceType: StockService::ADJUSTMENT,
            sourceId: $batch->id,
            floor: '5',
            batch: $batch,
        );

        return $batch;
    }

    private function refused(callable $work): string
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return implode(' ', array_merge(...array_values($e->errors())));
        }

        $this->fail('⛔ কিছুই আটকায়নি।');
    }
}
