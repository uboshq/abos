<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backup;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Modules\Backup\Dashboard\BackupDashboard;
use App\Modules\Backup\Models\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাসে মাসে ব্যাকআপ — নিরাপদ বনাম সমস্যা (নতুন ড্যাশবোর্ড, ৩ অক্টোবর ২০২৬)।
 *
 * ⭐ কেবল `success` নিরাপদ; `partial`, `local_only`, `failed` সমস্যা; চলমান রান কোনো দিকে নয়।
 * ⛔ সুইচ বন্ধে চার্টই নেই — পুরনো ড্যাশবোর্ড যেমন ছিল।
 */
final class TheDashboardCountsSafeCopiesByMonthTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_copy_that_reached_everywhere_counts_as_safe(): void
    {
        $company = Company::create(['code' => 'BAK', 'name_en' => 'Backup Co']);
        $branch = Branch::query()->create(['company_id' => $company->id, 'code' => 'B1', 'name_en' => 'Main', 'is_active' => true]);
        CompanyContext::set($company->id, $branch->id);

        foreach (['success', 'success', 'partial', 'local_only', 'failed', 'running'] as $status) {
            BackupRun::query()->create([
                'company_id' => $company->id, 'started_at' => now(), 'status' => $status,
                'backup_type' => 'full', 'scope' => 'company', 'triggered_by' => 'schedule',
            ]);
        }

        config(['abos.dashboards_v2' => true]);
        $panel = collect(BackupDashboard::dashboard()->panels)->firstWhere('label', __('backup::dashboard.months_of_copies'));
        $this->assertNotNull($panel, 'মাসে মাসে ব্যাকআপের চার্ট নেই।');
        $this->assertCount(6, $panel->points);

        $now = $panel->points[array_key_last($panel->points)];
        $this->assertSame('2', $now['first'], '⛔ নিরাপদ কপির গোনা ভুল — কেবল success নিরাপদ।');
        $this->assertSame('3', $now['second'], '⛔ সমস্যার গোনা ভুল — partial, local_only, failed তিনটাই; চলমান রান বাদ।');

        config(['abos.dashboards_v2' => false]);
        $this->assertSame([], BackupDashboard::dashboard()->panels, '⛔ সুইচ বন্ধ, তবু চার্ট — পুরনো ড্যাশবোর্ড বদলে গেছে।');
    }
}
