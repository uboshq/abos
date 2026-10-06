<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * খোলা জের পক্ষের নিজের শাখায় — অডিট ⓘ১৭ (৬ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে শাখা পাঠানো হত না, তাই খোলা জের বসত যিনি ঢোকালেন তাঁর শাখায়। ময়মনসিংহে বসে নেত্রকোনার গ্রাহক খুললে তার
 * পুরনো বাকি ময়মনসিংহের খাতায় উঠত, আর নেত্রকোনার পাওনায় থাকত না।
 * দাবি: শাখা A-তে বসে শাখা B-র গ্রাহক আর সরবরাহকারী খোলা জেরসহ খোলা → খোলা জেরের দুই সারিই শাখা B-তে, A-তে একটাও নয়।
 */
final class AnOpeningBalanceLandsInThePartysOwnBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_partys_opening_balance_lands_in_its_own_branch_not_the_typists(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        // ⓘ ঢোকানো মানুষ শাখা A-তে
        CompanyContext::set($company->id, $a->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = app(CustomerService::class)->create(['code' => 'OB-C', 'name_en' => 'Opening in B', 'branch_id' => $b->id,
            'opening_balance' => '4321', 'opening_date' => now()->toDateString(), 'credit_limit' => 0]);
        $supplier = app(SupplierService::class)->create(['code' => 'OB-S', 'name_en' => 'Opening in B supplier', 'branch_id' => $b->id,
            'opening_balance' => '8765', 'opening_date' => now()->toDateString()]);

        foreach ([[Customer::drillSourceType(), $customer->id], [Supplier::drillSourceType(), $supplier->id]] as [$type, $id]) {
            $branches = DB::table('ledger_entries')->where('company_id', $company->id)
                ->where('source_type', $type.':opening')->where('source_id', $id)
                ->pluck('branch_id')->map(fn ($v) => (int) $v)->all();

            $this->assertCount(2, $branches, "{$type}: খোলা জেরের দুই সারি নেই — দাবি অন্ধ।");
            $this->assertSame([$b->id, $b->id], $branches, "⛔ {$type}: খোলা জের পক্ষের শাখায় (B) নয়, ঢোকানো মানুষের শাখায়।");
        }
    }
}
