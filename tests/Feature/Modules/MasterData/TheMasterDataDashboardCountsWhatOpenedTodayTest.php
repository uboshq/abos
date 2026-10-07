<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Dashboard\MasterDataDashboard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * মাস্টার ডেটার "আজ কত নতুন" — মালিকের ড্যাশবোর্ড নকশা §১২ (৬ অক্টোবর ২০২৬)।
 *
 * ⓘ দাবি, আগে-পরের ফারাক ধরে: আজ একজন গ্রাহক খোলা → "আজ নতুন রেকর্ড" +১, "এ মাসে নতুন"-এ গ্রাহকের দণ্ড +১,
 * বাকি তালিকার দণ্ড নড়ে না।
 * ⛔ গত মাসে খোলা গ্রাহক আজকেও না, এ মাসেও না। ⛔ অন্য কোম্পানির গ্রাহক কোথাও না। ⛔ সুইচ বন্ধে কিছুই না।
 */
final class TheMasterDataDashboardCountsWhatOpenedTodayTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_opened_today_counts_once_and_nothing_else_does(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $other = Company::query()->where('id', '<>', $company->id)->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        config(['abos.dashboards_v2' => false]);
        $this->assertNull($this->stat(), '⛔ সুইচ বন্ধ, তবু নতুন রেকর্ডের সংখ্যা।');
        config(['abos.dashboards_v2' => true]);

        $todayBefore = (int) $this->stat()->value;
        $monthBefore = $this->month();

        $this->customer($company, 'NEW-1');
        $old = $this->customer($company, 'OLD-1');
        DB::table('customers')->where('id', $old->id)->update(['created_at' => now()->startOfMonth()->subDays(2)]);
        $stranger = $this->customer($other, 'THEIRS-1');
        $this->assertSame($other->id, (int) $stranger->company_id, 'অন্য কোম্পানির গ্রাহক বসেনি — দাবির ভিত নেই।');

        $this->assertSame(1, (int) $this->stat()->value - $todayBefore, '⛔ "আজ নতুন রেকর্ড" ঠিক একজন বাড়েনি।');

        $monthAfter = $this->month();
        $customers = __('customer::menu.customers');
        $this->assertArrayHasKey($customers, $monthAfter, 'এ মাসের নতুনে গ্রাহকের দণ্ড নেই।');
        foreach ($monthAfter as $list => $n) {
            $this->assertSame($list === $customers ? 1 : 0, $n - ($monthBefore[$list] ?? 0), "⛔ এ মাসে নতুন — \"{$list}\" ভুল নড়েছে।");
        }

        $panel = collect(MasterDataDashboard::dashboard()->panels)->first(fn ($p) => $p->label === __('master_data::dashboard.new_month'));
        $this->assertSame('columns', $panel->chart);
        $this->assertMatchesRegularExpression('/২০২৬|2026/u', (string) $panel->range, 'চার্টে "কবে থেকে কবে" নেই।');
    }

    private function stat(): ?Stat
    {
        return collect(MasterDataDashboard::dashboard()->stats)->first(fn (Stat $s) => $s->label === __('master_data::dashboard.new_today'));
    }

    /** @return array<string, int> */
    private function month(): array
    {
        $panel = collect(MasterDataDashboard::dashboard()->panels)
            ->first(fn ($p) => $p instanceof Breakdown && $p->label === __('master_data::dashboard.new_month'));
        $this->assertNotNull($panel, 'এ মাসে নতুনের চার্ট নেই।');

        return collect($panel->parts)->mapWithKeys(fn ($p) => [$p['label'] => (int) $p['value']])->all();
    }

    private function customer(Company $company, string $code): Customer
    {
        return Customer::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => $code,
            'name_en' => 'Opened '.$code, 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
    }
}
