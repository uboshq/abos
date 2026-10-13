<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\CommissionClaim;
use App\Modules\Sales\Services\CommissionClaimService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কমিশন দাবির খাতার সারি দাবির নিজের শাখায় — Sales লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ আগে দাখিলায় শাখা দেওয়া হত না: যিনি হেডারে "সব শাখা" (বা অন্য শাখা) রেখে দাবি লিখতেন, মানতেন বা নাকচ করতেন, খাতার সারি
 * বসত তাঁর হেডারের শাখায় — বিলের শাখার গ্রাহক-পাওনা কমত না, শাখার হিসাব মিলত না।
 */
final class ACommissionClaimPostsInItsOwnBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_settle_and_reject_post_in_the_claims_branch_whatever_the_header_says(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $branchId = (int) $company->defaultBranch()?->id;
        CompanyContext::set($company->id, $branchId);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('sales.commission_max_amount', 100000);
        app(SettingsService::class)->set('sales.commission_max_percent', 60);

        $first = $this->aBill();
        $second = $this->aBill();

        // ⓘ হেডারে "সব শাখা"
        CompanyContext::set($company->id, null);

        $settled = $this->claimOn($first);
        $rejected = $this->claimOn($second);
        app(CommissionClaimService::class)->settle($settled);
        app(CommissionClaimService::class)->reject($rejected, 'কোম্পানি মানেনি');

        $this->assertSame($branchId, (int) $settled->branch_id, 'দৃশ্যটাই ভুল — দাবি বিলের শাখায় নেই।');

        $rows = LedgerEntry::query()->withoutGlobalScopes()
            ->where('source_type', 'like', CommissionClaim::STOCK_SOURCE.'%')
            ->whereIn('source_id', [$settled->id, $rejected->id])->get();

        $this->assertCount(8, $rows, 'দৃশ্যটাই ভুল — দুই দাবির চার দাখিলা নেই।');

        foreach ($rows as $row) {
            $this->assertSame($branchId, (int) $row->branch_id, "⛔ {$row->source_type} সারি দাবির শাখায় বসেনি।");
        }
    }

    private function aBill(): \App\Modules\Sales\Models\SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1',
                // ⓘ একই কার্ট দুইবার — জেনেশুনে
                DirectSaleService::REPEAT_FIELD => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '10', 'rate' => '100', 'free_qty' => '0']],
        )['invoice'];
    }

    private function claimOn(\App\Modules\Sales\Models\SalesInvoice $invoice): CommissionClaim
    {
        return app(CommissionClaimService::class)->create([
            'customer_id' => $invoice->customer_id,
            'supplier_id' => Supplier::query()->firstOrFail()->id,
            'sales_invoice_id' => $invoice->id,
            'rate_percent' => '5',
        ]);
    }
}
