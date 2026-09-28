<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * চালান ভুলে যেত মাল কীভাবে গেল — লাইভের যাচাইয়ে ধরা (কোঅর্ডিনেটর, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * নিশ্চিতের আগে "মাল কীভাবে যাবে" বলতেই হয় ([[TransportRule]]), অথচ বলা কথাটা চালানের পাতায় বা ছাপায়
 * কোথাও উঠত না: "পরিবহন লাগবে না (ক্রেতার নিজের)" টিক, বাহকের নাম, চালকের মোবাইল — কিছুই না;
 * আর পাতা বহরের গাড়ির নম্বরপ্লেটের বদলে কেবল হাতে লেখা নম্বর দেখাত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * পাতা আর ছাপা (চালান, গেটপাস) একই উত্তর দেয় ([[DeliveryChallan::transportLabel()]]): নিজস্ব পরিবহন
 * নয়তো বাহক, আর গাড়ি (বহরের হলে মাস্টারের নম্বরপ্লেট), চালক, চালকের মোবাইল।
 */
final class TheChallanForgotHowTheGoodsTravelledTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ⭐ "নিজস্ব পরিবহন" — পাতায়, চালানের ছাপায়, গেটপাসে। */
    public function test_own_transport_shows_on_the_page_and_on_both_papers(): void
    {
        $challan = $this->confirmedChallan(['own_transport' => true]);
        $own = __('sales::field.transport_own');

        $this->get(route('sales.challan.show', $challan))->assertOk()->assertSee($own);

        foreach (['sales.print.challan', 'sales.print.gatepass'] as $paper) {
            $this->assertSame($own, $this->meta($paper, $challan)['sales::field.carrier'] ?? null,
                "⛔ {$paper}-এ নিজস্ব পরিবহন ওঠেনি।");
        }
    }

    /** ⭐ বহরের গাড়ি: মাস্টারের নম্বরপ্লেট, চালক, চালকের মোবাইল — পাতায় ও ছাপায়। */
    public function test_the_vehicle_driver_and_phone_show_on_the_page_and_the_paper(): void
    {
        $vehicle = Vehicle::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'VAN-7',
            'name_en' => 'Van 7',
            'name_bn' => 'ভ্যান ৭',
            'registration_no' => 'ঢাকা মেট্রো ন ৭৭-০০০৭',
            'is_active' => true,
        ]);

        $challan = $this->confirmedChallan([
            'vehicle_id' => $vehicle->id,
            'driver_name' => 'রহিম',
            'driver_phone' => '01711000077',
        ]);

        $this->get(route('sales.challan.show', $challan))->assertOk()
            ->assertSee('ঢাকা মেট্রো ন ৭৭-০০০৭')
            ->assertSee('রহিম')
            ->assertSee('01711000077');

        $meta = $this->meta('sales.print.challan', $challan);
        $this->assertSame('ঢাকা মেট্রো ন ৭৭-০০০৭', $meta['sales::field.vehicle_no'] ?? null);
        $this->assertSame('01711000077', $meta['sales::field.driver_phone'] ?? null, '⛔ ছাপায় চালকের মোবাইল নেই।');
    }

    /** ⭐ বাহকের নাম — আর নিজস্ব পরিবহনের কথা তখন ওঠে না। */
    public function test_a_named_carrier_shows_instead_of_own_transport(): void
    {
        $challan = $this->confirmedChallan(['carrier_name' => 'সুন্দরবন কুরিয়ার']);

        $this->get(route('sales.challan.show', $challan))->assertOk()
            ->assertSee('সুন্দরবন কুরিয়ার')
            ->assertDontSee(__('sales::field.transport_own'));

        $this->assertSame('সুন্দরবন কুরিয়ার', $this->meta('sales.print.challan', $challan)['sales::field.carrier'] ?? null);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $transport */
    private function confirmedChallan(array $transport): DeliveryChallan
    {
        $biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $service = app(DeliveryChallanService::class);

        $challan = $service->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $biscuit->id, 'delivered_qty' => '2', 'rate' => '10']]);

        // ⓘ ঘরগুলো সরাসরি — দাবিটা দেখানো মাপে, ফর্ম কোন ঘর নেয় তা নয়
        $challan->forceFill($transport)->save();

        return $service->confirm($challan->fresh());
    }

    /** @return array<string, string> */
    private function meta(string $route, DeliveryChallan $challan): array
    {
        $seen = [];

        View::composer('print.document', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route($route, $challan))->assertOk();

        return $seen['doc']->meta ?? [];
    }
}
