<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Dashboard\CustomerDashboard;
use App\Modules\Supplier\Dashboard\SupplierDashboard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * বাকি আর দেনার বয়সের চার্ট = বয়সের রিপোর্ট (নতুন ড্যাশবোর্ড, ৩ অক্টোবর ২০২৬)।
 *
 * ⭐ চার ভাগের প্রতিটা রিপোর্টের পুরো ফলের যোগফলের সমান — দুই জায়গায় দুই হিসাব নয়।
 * ⛔ একই মানুষ: রিপোর্টের চাবি ছাড়া চার্ট নেই; চাবি পেলে আছে। ⛔ সুইচ বন্ধে নেই।
 */
final class TheAgeingChartIsTheAgeingReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_charts_carry_the_reports_own_totals_and_only_with_its_key(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id);
        $this->actingAs($clerk);
        config(['abos.dashboards_v2' => true]);

        foreach ([
            [CustomerDashboard::class, 'customer', 'customer.ageing', 'customer.report'],
            [SupplierDashboard::class, 'supplier', 'supplier.ageing', 'supplier.report'],
        ] as [$dashboard, $module, $report, $key]) {
            $label = __($module.'::dashboard.ageing');
            $this->assertNull(collect($dashboard::dashboard()->panels)->firstWhere('label', $label), "⛔ {$key} ছাড়াই {$module}-এর বয়সের চার্ট।");

            Permission::findOrCreate($key, 'web');
            CompanyContext::forCompany($company->id, fn () => $clerk->givePermissionTo($key));
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            $clerk = $clerk->fresh();
            $this->actingAs($clerk);

            $panel = collect($dashboard::dashboard()->panels)->firstWhere('label', $label);
            $this->assertNotNull($panel, "{$key} থাকা সত্ত্বেও {$module}-এর বয়সের চার্ট নেই।");

            $totals = app(ReportEngine::class)->run($report, ['to' => now()->toDateString()], 1, 1)->totals;
            $this->assertSame(
                array_map(fn ($b) => Money::format($totals[$b] ?? '0'), ['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90']),
                array_column($panel->parts, 'value'),
                "⛔ {$module}: চার্টের চার ভাগ বয়সের রিপোর্টের যোগফলের সাথে মেলে না।",
            );
        }

        // ⭐ গ্রাহক বৃদ্ধি — এ মাসের "নতুন যোগ" দণ্ড উপরের "এ মাসে নতুন" সংখ্যার সমান; একজন বন্ধ হলে "এখনো চালু" এক কম
        $thisMonth = function () {
            $definition = CustomerDashboard::dashboard();
            $growth = collect($definition->panels)->firstWhere('label', __('customer::dashboard.growth'));
            $this->assertNotNull($growth, 'গ্রাহক বৃদ্ধির চার্ট নেই।');

            return [$growth->points[array_key_last($growth->points)],
                collect($definition->stats)->firstWhere('label', __('customer::dashboard.new_this_month'))->value];
        };

        $someone = \App\Modules\Customer\Models\Customer::query()->where('is_active', true)->firstOrFail();
        $someone->forceFill(['created_at' => now()])->saveQuietly();
        [$before, $newThisMonth] = $thisMonth();
        $this->assertSame($newThisMonth, $before['first'], '⛔ এ মাসের দণ্ড আর "এ মাসে নতুন" দুই কথা বলে।');

        $someone->forceFill(['is_active' => false])->saveQuietly();
        [$after] = $thisMonth();
        $this->assertSame($before['first'], $after['first'], 'বন্ধ করায় "নতুন যোগ" বদলে গেল।');
        $this->assertSame((string) ((int) $before['second'] - 1), $after['second'], '⛔ বন্ধ গ্রাহকও "এখনো চালু"-তে গোনা।');

        config(['abos.dashboards_v2' => false]);
        $this->assertSame([], CustomerDashboard::dashboard()->panels, '⛔ সুইচ বন্ধ, তবু গ্রাহকের চার্ট।');
    }
}
