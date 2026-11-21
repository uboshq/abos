<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\ChallanLoading;
use App\Modules\Sales\Services\CostNarration;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\FarePayment;
use App\Modules\Sales\Services\ShipmentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HiresTheLabour;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ খরচের ভাউচারের বিবরণে সব পয়েন্ট — মালিক, ২১ নভেম্বর ২০২৬ (নমুনা v6, ক; [[CostNarration]])।
 *
 * দাবি:
 *  - চালান: "গাড়ি ভাড়া — চালান CH-…, পয়েন্ট: …, গাড়ি … (ড্রাইভার: …)" — নতুন EV-তে, মাথায় আর সারিতে।
 *  - পয়েন্ট নেই: গ্রাহকের নাম আর ছোট ঠিকানা।
 *  - ট্রিপ: আগে সব চালান, তারপর সব পয়েন্ট — প্রথম দেখার ক্রমে, একবার করে, কাটা নয়।
 *  - গাড়ি বা ড্রাইভার না থাকলে সেই অংশ নেই; লোডিং: "লোডিং CH-…, পয়েন্ট: …", গাড়ি-ড্রাইভার নয়।
 *  - ভাউচার খুললে ভাড়া, মাধ্যম, মোবাইল, গাড়ি, সব চালান, সব পয়েন্ট; তালিকায় বিবরণের ঘর ভাঙে (কাটা নয়)।
 */
final class AFareVoucherNamesEveryPointTest extends TestCase
{
    use HiresTheLabour;
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app()->setLocale('bn');

        $this->warehouse = Warehouse::query()->where('is_default', true)->first() ?? Warehouse::query()->firstOrFail();
        $till = app(CashTillService::class)->create(['code' => 'CN-T', 'name_en' => 'Narration till']);
        $this->till = Account::query()->findOrFail($till->account_id);
        $this->putMoneyIn($this->till, '50000');
    }

    public function test_a_new_challan_fare_voucher_names_the_point_the_vehicle_and_the_driver_in_the_owners_order(): void
    {
        $shop = $this->shop('CN-1', 'Uttara Store', 'উত্তরা স্টোর', $this->place('PT-UT', 'Uttara', 'উত্তরা', Location::POINT));
        $challan = $this->challan($shop, '9000', ['vehicle_no' => 'ঢাকা মেট্রো-ট 14-6789', 'driver_name' => 'রফিক', 'driver_phone' => '01712-000000']);

        app(FarePayment::class)->stamp($challan, ['fare_when' => 'now', 'fare_account_id' => $this->till->id]);
        app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));
        $voucher = Voucher::query()->with('lines')->findOrFail($challan->fresh()->fare_voucher_id);

        $expected = "গাড়ি ভাড়া — চালান {$challan->document_no}, পয়েন্ট: উত্তরা, গাড়ি ঢাকা মেট্রো-ট 14-6789 (ড্রাইভার: রফিক)";
        $this->assertSame($expected, $voucher->narration, '⛔ নতুন ভাড়ার ভাউচারের বিবরণ মালিকের ক্রমে নয়।');
        foreach ($voucher->lines as $line) {
            $this->assertSame($expected, $line->narration, '⛔ ভাউচারের সারিতে আগের বিবরণ রয়ে গেল।');
        }
        $this->assertStringNotContainsString('বাহক বা চালক', $voucher->narration, '⛔ আগের লেখা ("বাহক বা চালক") নতুন ভাউচারে।');

        // ⭐ ভাউচার খুললে বিস্তারিত — ভাড়া (লাখের কমা, ইংরেজি অঙ্ক), মোবাইল, গাড়ি, চালান, পয়েন্ট
        $html = $this->get(route('accounts.voucher.show', $voucher))->assertOk()->getContent();
        $this->assertStringContainsString('data-voucher-cost-facts', $html, '⛔ ভাড়ার ভাউচার খুললে বিস্তারিত নেই।');
        foreach (['৳9,000', '01712-000000', 'ঢাকা মেট্রো-ট 14-6789', $challan->document_no, __('sales::fare.facts_points', ['count' => 1]), 'উত্তরা'] as $fact) {
            $this->assertStringContainsString(e($fact), $html, "⛔ ভাউচারের পাতায় {$fact} নেই।");
        }

        // ⭐ তালিকায় বিবরণ পুরোটা, লাইন ভেঙে — কাটা নয়
        $list = $this->get(route('accounts.voucher.index', Voucher::EXPENSE))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<td[^>]*data-wrap[^>]*>\s*'.preg_quote(e($expected), '/').'/u', $list,
            '⛔ খরচের ভাউচারের তালিকায় বিবরণের ঘর ভাঙে না (data-wrap নেই) বা পুরো বিবরণ নেই।');
    }

    public function test_without_a_point_the_narration_names_the_customer_and_a_short_address(): void
    {
        $area = $this->place('AR-KM', 'Kamrangirchar', 'কামরাঙ্গীরচর', Location::AREA);
        $shop = $this->shop('CN-2', 'Rahman Enterprise', 'রহমান এন্টারপ্রাইজ', $area);
        $challan = $this->challan($shop, '4500', ['vehicle_no' => 'ঢাকা মেট্রো-ন 20-1122', 'driver_name' => 'সেলিম']);

        $this->assertSame(
            "গাড়ি ভাড়া — চালান {$challan->document_no}, রহমান এন্টারপ্রাইজ, কামরাঙ্গীরচর, গাড়ি ঢাকা মেট্রো-ন 20-1122 (ড্রাইভার: সেলিম)",
            app(CostNarration::class)->challanNarration($challan->fresh()),
            '⛔ পয়েন্ট না থাকলে গ্রাহকের নাম আর ছোট ঠিকানা আসেনি।',
        );
    }

    public function test_no_vehicle_and_no_driver_leave_their_parts_out(): void
    {
        $shop = $this->shop('CN-3', 'Savar Shop', 'সাভার শপ', $this->place('PT-SV', 'Savar', 'সাভার', Location::POINT));
        $challan = $this->challan($shop, '300', ['vehicle_no' => null, 'driver_name' => null]);

        $this->assertSame(
            "গাড়ি ভাড়া — চালান {$challan->document_no}, পয়েন্ট: সাভার",
            app(CostNarration::class)->challanNarration($challan->fresh()),
            '⛔ গাড়ি বা ড্রাইভার না থাকলেও ফাঁকা অংশ বসল ("গাড়ি —" বা "(ড্রাইভার: )")।',
        );
    }

    public function test_a_trip_fare_lists_every_challan_then_every_point_once_in_first_seen_order(): void
    {
        $narayanganj = $this->place('PT-NG', 'Narayanganj', 'নারায়ণগঞ্জ', Location::POINT);
        $fatullah = $this->place('PT-FT', 'Fatullah', 'ফতুল্লা', Location::POINT);
        $a = $this->confirmedChallan($this->shop('CN-4', 'NG One', 'এনজি এক', $narayanganj));
        $b = $this->confirmedChallan($this->shop('CN-5', 'FT One', 'এফটি এক', $fatullah));
        $c = $this->confirmedChallan($this->shop('CN-6', 'NG Two', 'এনজি দুই', $narayanganj));

        $trip = app(ShipmentService::class)->create(
            ['trx_date' => now()->toDateString(), 'warehouse_id' => $this->warehouse->id,
                'transport_cost' => '7800', 'fare_when' => 'now', 'fare_account_id' => $this->till->id],
            [$a->id, $b->id, $c->id],
        )->fresh();
        $trip->forceFill(['vehicle_id' => null, 'vehicle_no' => 'ঢাকা মেট্রো-ট 11-2345', 'driver_name' => 'করিম', 'driver_phone' => '01811-111111'])->save();

        $expected = "ট্রাকের ভাড়া — চালান {$a->document_no}, {$b->document_no}, {$c->document_no}, পয়েন্ট: নারায়ণগঞ্জ, ফতুল্লা, গাড়ি ঢাকা মেট্রো-ট 11-2345 (ড্রাইভার: করিম)";
        $this->assertSame($expected, app(CostNarration::class)->tripNarration($trip->fresh()), '⛔ ট্রিপের বিবরণে সব চালান আর সব পয়েন্ট (একবার করে, ক্রমে) নেই।');
        $this->assertSame(['নারায়ণগঞ্জ', 'ফতুল্লা'], app(CostNarration::class)->pointsOf($trip->fresh()), '⛔ একই পয়েন্ট দুবার, বা ক্রম ভাঙল।');

        $trip = app(ShipmentService::class)->dispatch($trip->fresh());
        $voucher = Voucher::query()->where('against_type', Shipment::drillSourceType())->where('against_id', $trip->id)->firstOrFail();
        $this->assertSame($expected, $voucher->narration, '⛔ ট্রাক রওনার ভাউচারে নতুন বিবরণ বসেনি।');

        $html = $this->get(route('accounts.voucher.show', $voucher))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('sales::fare.facts_challans', ['count' => 3])), $html, '⛔ ট্রিপের ভাউচারে সব চালানের সারি নেই।');
        $this->assertStringContainsString(e(__('sales::fare.facts_points', ['count' => 2])), $html, '⛔ ট্রিপের ভাউচারে সব পয়েন্টের সারি নেই।');
    }

    public function test_every_point_is_listed_however_many_there_are(): void
    {
        $names = ['টঙ্গী', 'আশুলিয়া', 'গাজীপুর', 'কালিয়াকৈর', 'শ্রীপুর', 'মাওনা'];
        $ids = [];

        foreach ($names as $i => $name) {
            $point = $this->place('PT-M'.$i, 'Point '.$i, $name, Location::POINT);
            $ids[] = $this->confirmedChallan($this->shop('CN-M'.$i, 'Shop '.$i, 'দোকান '.$i, $point))->id;
        }

        $trip = app(ShipmentService::class)->create(['trx_date' => now()->toDateString(), 'warehouse_id' => $this->warehouse->id], $ids)->fresh();
        $text = app(CostNarration::class)->tripNarration($trip);

        $this->assertStringContainsString('পয়েন্ট: '.implode(', ', $names), $text, '⛔ সব পয়েন্ট নেই — কাটা হলো?');
        $this->assertDoesNotMatchRegularExpression('/\+\d/u', $text, '⛔ "+n" বসল — মালিক সব নাম চেয়েছেন।');
    }

    public function test_loading_names_the_challan_and_its_point_without_vehicle_or_driver(): void
    {
        $shop = $this->shop('CN-7', 'Keraniganj Shop', 'কেরানীগঞ্জ শপ', $this->place('PT-KG', 'Keraniganj', 'কেরানীগঞ্জ', Location::POINT));
        $till = $this->labourTill('CN-LT');
        $service = app(DeliveryChallanService::class);
        $challan = $service->create(
            ['customer_id' => $shop->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(), 'own_transport' => true,
                'loading_mode' => 'cash', 'loading_amount' => '1200', 'loading_account_id' => $till->id],
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '50']],
        );
        // ⓘ ক্রেতা নিজে নিলেন — `driver_name` তখন যিনি নিলেন ([[DeliveryChallan::transportFacts()]]); লোডিংয়ের লেখায় তবু নাম নয়
        $challan->forceFill(['driver_name' => 'জামাল'])->save();
        $challan = $service->confirm($challan->fresh(['lines']));

        $expected = "লোডিং {$challan->document_no}, পয়েন্ট: কেরানীগঞ্জ";
        $narrations = LedgerEntry::query()->where('source_type', ChallanLoading::SOURCE)->where('source_id', $challan->id)->pluck('narration')->unique()->values()->all();
        $this->assertSame([$expected], $narrations, '⛔ লোডিংয়ের খাতার সারিতে "লোডিং CH-…, পয়েন্ট: …" নেই, বা গাড়ি-ড্রাইভার ঢুকল।');
        $this->assertTrue(LedgerEntry::query()->where('source_type', ChallanLoading::SOURCE)->where('source_id', $challan->id)
            ->where('account_id', StandardChart::accountFor(StandardChart::LOADING)->id)->exists());
    }

    // ── যন্ত্রপাতি ──

    private function place(string $code, string $en, string $bn, string $level): Location
    {
        return Location::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'level' => $level,
            'name_en' => $en, 'name_bn' => $bn, 'is_active' => true,
        ]);
    }

    private function shop(string $code, string $en, string $bn, ?Location $place): Customer
    {
        $shop = Customer::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'code' => $code, 'name_en' => $en, 'name_bn' => $bn, 'location_id' => $place?->id, 'is_active' => true,
        ]);
        // ⓘ সীমা পরম (মালিক) — নতুন দোকানের সীমা ০, তাই নিশ্চিত করতে সীমা বসানো, অন্য চালানের পরীক্ষার মতো
        $shop->forceFill(['credit_limit' => '1000000000'])->save();

        return $shop;
    }

    /** @param  array<string, mixed>  $transport */
    private function challan(Customer $shop, string $fare, array $transport): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create(
            ['customer_id' => $shop->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '50']],
        );
        $challan->forceFill(['transport_cost' => $fare, 'fare_paid_by' => 'us', 'vehicle_id' => null] + $transport)->save();

        return $challan->fresh();
    }

    private function confirmedChallan(Customer $shop): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create(
            ['customer_id' => $shop->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '100']],
        ));
    }
}
