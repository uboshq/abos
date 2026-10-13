<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrintsTheStandardPaper;
use Tests\TestCase;

/**
 * ⛔ `?prices=1` শাখার "দাম লুকাও" সুইচ পেরোয় কেবল বিল দেখার চাবিতে (১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা;
 * [[SalesPrintController::challan()]])।
 *
 * ⓘ চালানের ছাপার দরজা খোলে `sales.challan.view`-এ — গেটপাসের একই চাবি, যেটা দারোয়ান আর চালকের কাছে থাকে, যাতে তাঁরা দর না
 * দেখেন। আগে ঠিকানায় `?prices=1` লিখলেই সুইচ বন্ধ থাকা সত্ত্বেও টাকাসহ চালান বেরোত।
 */
final class OnlyABillViewerCanAskForAChallanWithPricesTest extends TestCase
{
    use PrintsTheStandardPaper;
    use RefreshDatabase;

    private Company $company;

    private DeliveryChallan $challan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->printTheStandardPaper();

        // ⓘ শাখা বলেছে: চালানে দাম নয়
        app(SettingsService::class)->set('sales.print.challan_show.prices', false);

        $product = Product::query()->orderBy('id')->firstOrFail();

        $this->challan = DeliveryChallan::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'CHL-PRC-0001',
            'customer_id' => Customer::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
            'total' => '200.0000',
            'status' => DocumentStatus::CONFIRMED,
        ]);

        DeliveryChallanLine::query()->create([
            'delivery_challan_id' => $this->challan->id,
            'product_id' => $product->id,
            'line_no' => 1,
            'delivered_qty' => '2.0000',
            'rate' => '100.0000',
            'amount' => '200.0000',
        ]);
    }

    public function test_a_challan_only_clerk_cannot_turn_the_prices_on_from_the_address(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo('sales.challan.view');
        $clerk = $clerk->fresh();
        $this->assertFalse($clerk->can('sales.invoice.view'), 'ⓘ কর্মীর বিলের চাবি আছে — দাবিটা কিছু মাপছে না');

        $this->actingAs($clerk)->get(route('sales.print.challan', [$this->challan, 'prices' => 1]))->assertOk();
        $this->assertSame('without_amounts', DocumentDelivery::query()->latest('id')->value('variant'),
            '⛔ কেবল চালানের চাবিওয়ালা ঠিকানায় prices=1 লিখে শাখার সুইচ পেরিয়ে টাকাসহ চালান ছাপলেন');

        // ⓘ বিল দেখার চাবিওয়ালা — ছাপার বোতামের "টাকাসহ" আগের মতোই কাজ করে
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail())
            ->get(route('sales.print.challan', [$this->challan, 'prices' => 1]))->assertOk();
        $this->assertSame('with_amounts', DocumentDelivery::query()->latest('id')->value('variant'),
            'মালিকের "টাকাসহ" বাছাই কাজ করল না — দরজাটা বেশি বন্ধ হয়ে গেছে');
    }
}
