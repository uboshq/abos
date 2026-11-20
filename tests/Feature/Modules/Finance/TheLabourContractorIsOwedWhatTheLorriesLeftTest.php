<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Finance\Services\CarrierAndLabourLedger;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HiresTheLabour;
use Tests\TestCase;

/**
 * ⭐ হাম্মালি ঠিকাদারের পাওনা — "পরিবহন ও শ্রমিকের খতিয়ান"-এর শ্রমিক ট্যাবে (মালিক, ১৮ নভেম্বর ২০২৬: "ওকে"; ২০ নভেম্বর ২০২৬ দাবি)।
 *
 * ⓘ মালিকের কারণ: বাকিতে নামানো আর তোলার মজুরি মাসে একবার মেটে — "করিম হাম্মালিকে এখন কত দিতে হবে" প্রশ্নের উত্তর এক পাতায়।
 *   মাল গ্রহণের নামানো (৪৫০) আর চালানের তোলা (২৮৭.৫০) একই ঠিকাদারের নামে ২১১৭-এ → এখন বাকি ৭৩৭.৫০।
 */
final class TheLabourContractorIsOwedWhatTheLorriesLeftTest extends TestCase
{
    use HiresTheLabour;
    use RefreshDatabase;

    public function test_the_labour_tab_shows_the_contractor_with_what_he_is_owed(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $contractor = $this->labourContractor('LAB-FIN', 'Karim Hammali Gang');
        $product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();

        $receipts = app(PurchaseReceiptService::class);
        $receipts->confirm($receipts->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(), 'unload_mode' => 'due', 'unload_amount' => '450', 'unload_contractor_id' => $contractor->id],
            [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '100']],
        )->fresh());

        $challans = app(DeliveryChallanService::class);
        $challans->confirm($challans->create(
            ['customer_id' => Customer::query()->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->firstOrFail()->id,
                'trx_date' => now()->toDateString(), 'own_transport' => true,
                'loading_mode' => 'due', 'loading_amount' => '287.50', 'loading_contractor_id' => $contractor->id],
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '50']],
        )->fresh(['lines']));

        $ledger = app(CarrierAndLabourLedger::class);
        $rows = $ledger->parties($ledger->head('labour'), now()->startOfMonth()->toDateString(), now()->toDateString())->keyBy('key');
        $mine = $rows['supplier:'.$contractor->id] ?? null;

        $this->assertNotNull($mine, '⛔ ঠিকাদার শ্রমিকের খতিয়ানে নেই — দেনা পক্ষ ছাড়া বসল?');
        $this->assertSame(0, bccomp($mine['charged'], '737.50', 2), '⛔ নামানো + তোলা = ৭৩৭.৫০ জমেনি।');
        $this->assertSame(0, bccomp($mine['closing'], '737.50', 2), '⛔ ঠিকাদারের এখনকার বাকি ভুল।');

        $this->get(route('finance.carrier_labour.index', ['tab' => 'labour']))
            ->assertOk()
            ->assertSee('Karim Hammali Gang')
            ->assertSee(Money::format('737.50'));
    }
}
