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
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\ShipmentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * দুইটা ট্রিপ একই চালান একসাথে নিতে পারে না — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * [[ShipmentService]] চালানটা "অন্য কোনো ট্রিপে আছে কি না" দেখত লেনদেনের ভেতরে ঠিকই, কিন্তু চালানের সারিতে
 * **তালা ছাড়া**। দুই ডেসপ্যাচার একই মুহূর্তে একই চালান দুই গাড়িতে তুললে দুজনেই "কোথাও নেই" দেখতেন — একই মাল
 * দুই গাড়ির কাগজে উঠত, আর গেট পাস, লোডিং শিট আর ট্রিপের হিসাব দুই জায়গায় একই চালান গুনত।
 *
 * ── ⓘ কেন কোয়েরির ক্রম মাপা ──────────────────────────────────────────
 * পরখটা প্রতিবার ডাটাবেজ থেকে পড়ে, তাই পরপর চালালে দ্বিতীয়টা আজও থামে — দৌড়টা এক প্রক্রিয়ায় বানানো যায়
 * না। ⓘ তাই দাবিটা সরাসরি কথাটা মাপে: চালানের সারিতে `FOR UPDATE` আগে, আর "অন্য ট্রিপে আছে কি না" প্রশ্ন তার
 * পরে — দ্বিতীয় ডেসপ্যাচার তখন অপেক্ষা করেন, তারপর প্রথমজনের ট্রিপ দেখে ফেরেন।
 */
final class TwoTripsCannotTakeOneChallanTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_the_challan_is_locked_before_the_other_trips_are_asked(): void
    {
        $challan = $this->confirmedChallan();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        app(ShipmentService::class)->create(
            ['trx_date' => now()->toDateString(), 'warehouse_id' => $this->warehouse->id],
            [$challan->id],
        );

        $lockAt = null;
        $askAt = null;
        foreach ($queries as $i => $sql) {
            if ($lockAt === null && str_contains($sql, 'from `sal_challans`') && str_contains($sql, 'for update')) {
                $lockAt = $i;
            }
            if ($askAt === null && str_contains($sql, 'sal_shipment_lines') && str_contains($sql, 'delivery_challan_id')) {
                $askAt = $i;
            }
        }

        $this->assertNotNull($lockAt, 'চালানের সারিতে তালা পড়েনি — দুই ট্রিপ একই চালান একসাথে নিতে পারে।');
        $this->assertNotNull($askAt, '"অন্য ট্রিপে আছে কি না" প্রশ্নটাই পাওয়া গেল না — দাবিটা কিছু মাপছে না।');
        $this->assertLessThan($askAt, $lockAt, 'অন্য ট্রিপের খোঁজ তালার আগে — দ্বিতীয় ডেসপ্যাচার পুরনো উত্তর পাবেন।');
    }

    private function confirmedChallan(): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'delivered_qty' => '5', 'rate' => '10']]);

        return app(DeliveryChallanService::class)->confirm($challan);
    }
}
