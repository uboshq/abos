<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Supplier\Dashboard\SupplierDashboard;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ "সবচেয়ে বেশি যাঁদের দিতে হবে" চার্ট শীর্ষ পাঁচ নিয়ে তারপর ছাঁকত (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[SupplierDashboard::mostOwed()]])।
 *
 * ⚠️ পাঁচজন সেবাদাতার বড় দেনা শীর্ষ পাঁচ ভরাত, তারপর "কেবল প্রিন্সিপাল" ছাঁকনিতে সবাই বাদ — চার্ট খালি, অথচ তিনজন প্রিন্সিপালের দেনা আছে।
 */
final class TheMostOwedChartLostPrincipalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_chart_filters_first_and_takes_five_after(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        config(['abos.dashboards_v2' => true]);
        DB::table('ledger_entries')->where('party_type', Supplier::drillSourceType())->delete();

        $service = PartyType::query()->for(PartyType::SUPPLIER)->where('code', '!=', Supplier::VENDOR_CODE)->orderBy('id')->firstOrFail();
        $payable = (int) StandardChart::find(StandardChart::PAYABLE)->id;
        $expense = (int) Account::query()->postable()->active()->where('type', Account::EXPENSE)->value('id');
        $owe = function (Supplier $s, string $amount, int $id) use ($payable, $expense): void {
            app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: $id, trxDate: now()->toDateString(), lines: [
                ['account_id' => $expense, 'debit' => $amount],
                ['account_id' => $payable, 'credit' => $amount, 'party_type' => Supplier::drillSourceType(), 'party_id' => $s->id],
            ]);
        };

        foreach (range(1, 5) as $i) {
            $s = Supplier::query()->create(['company_id' => $company->id, 'code' => 'SVC-'.$i, 'name_en' => 'Big Service '.$i,
                'party_type_id' => $service->id, 'branch_id' => $company->defaultBranch()?->id, 'is_active' => true]);
            $owe($s, '900000', 93000 + $i);
        }

        $principals = [];
        foreach (range(1, 3) as $i) {
            $principals[] = $s = Supplier::query()->create(['company_id' => $company->id, 'code' => 'PRN-'.$i, 'name_en' => 'Small Principal '.$i,
                'party_type_id' => null, 'branch_id' => $company->defaultBranch()?->id, 'is_active' => true]);
            $owe($s, (string) (1000 * $i), 93100 + $i);
        }

        $chart = collect(SupplierDashboard::dashboard()->panels)
            ->first(fn ($p) => $p instanceof Breakdown && $p->label === __('supplier::dashboard.most_owed'));

        $this->assertNotNull($chart, '⛔ তিন প্রিন্সিপালের দেনা আছে, অথচ চার্টটাই নেই');
        $this->assertSame(['Small Principal 3', 'Small Principal 2', 'Small Principal 1'], array_column($chart->parts, 'label'));
    }
}
