<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backup;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Backup\Dashboard\BackupDashboard;
use App\Modules\Backup\Models\BackupDestination;
use App\Modules\Backup\Models\BackupPolicy;
use App\Modules\Backup\Models\BackupRun;
use App\Modules\Backup\Models\BackupVerification;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ব্যাকআপের ড্যাশবোর্ড — নকশার বাকিটা (৬ অক্টোবর ২০২৬): শেষ সফল ব্যাকআপের মাপ, গত ৩০ দিনের রান, গন্তব্যের অবস্থা,
 * সময়সূচি।
 *
 * ⓘ রানের প্রতিটা ভাগ আগে-পরে মাপা, বাড়তিটা ঠিক যে রানগুলো গোনার কথা; প্রতিটা রান ঠিক একটা ভাগে।
 * ⛔ ৪০ দিন আগের রান আর চলমান রান গোনায় নেই; ফিরিয়ে এনে দেখা হয়নি এমন সফল রান "যাচাই-করা"-য় নেই।
 * ⛔ মাপ কেবল সফল রানের — পরে চলা ব্যর্থ রানের মাপ নয়। ⛔ সুইচ বন্ধে নতুন কিছু নেই।
 */
final class TheBackupDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    public function test_size_runs_destinations_and_schedule_follow_their_rows(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        config(['abos.dashboards_v2' => false]);
        $old = BackupDashboard::dashboard();
        $this->assertSame([], $old->panels, '⛔ সুইচ বন্ধ, তবু চার্ট।');
        $this->assertSame([], $old->listings, '⛔ সুইচ বন্ধ, তবু তালিকা।');
        $this->assertNull(collect($old->stats)->firstWhere('label', __('backup::dashboard.latest_size')), '⛔ সুইচ বন্ধে মাপের ঘর।');

        config(['abos.dashboards_v2' => true]);
        $before = $this->runs(BackupDashboard::dashboard());

        $run = fn (string $status, Carbon $at, ?int $bytes = null) => BackupRun::query()->forceCreate([
            'company_id' => $company->id, 'started_at' => $at, 'finished_at' => $at, 'status' => $status, 'bytes' => $bytes,
            'backup_type' => 'full', 'scope' => 'all', 'triggered_by' => 'schedule',
        ]);
        $passed = $run('success', now()->subDays(10), 5 * 1048576);
        BackupVerification::query()->forceCreate(['run_id' => $passed->id, 'kind' => 'test_restore', 'status' => 'passed', 'verified_at' => now()->subDays(10)]);
        $broke = $run('success', now()->subDays(5), 4096);
        BackupVerification::query()->forceCreate(['run_id' => $broke->id, 'kind' => 'test_restore', 'status' => 'failed', 'verified_at' => now()->subDays(5)]);
        $run('success', now()->addMinute(), 2048);           // সবশেষ সফল — মাপ এটার
        $run('partial', now()->subDays(3));
        $run('local_only', now()->subDays(2));
        $run('failed', now()->addMinutes(2), 999999999);     // পরে চলেছে, কিন্তু ব্যর্থ — মাপ এটার নয়
        $run('success', now()->subDays(40), 77);             // ৩০ দিনের বাইরে
        $run('running', now());                              // চলমান

        $afterDash = BackupDashboard::dashboard();
        $after = $this->runs($afterDash);
        $grew = fn (string $key) => (int) $after[__('backup::dashboard.'.$key)] - (int) $before[__('backup::dashboard.'.$key)];

        $this->assertSame([1, 2, 1, 1, 1], [$grew('run_verified'), $grew('run_success'), $grew('run_partial'), $grew('run_local_only'), $grew('run_failed')],
            '⛔ রানের ভাগ ভুল — পুরনো বা চলমান রান গোনা, বা যাচাই-না-হওয়া রান "যাচাই-করা"-য়।');
        $donut = collect($afterDash->panels)->firstWhere('label', __('backup::dashboard.last_30_days'));
        $this->assertSame('donut', $donut->chart);
        $this->assertNotNull($donut->range, '⛔ সময়ের চার্টে তারিখের পরিসর নেই।');

        $size = collect($afterDash->stats)->firstWhere('label', __('backup::dashboard.latest_size'));
        $this->assertSame('2 KB', $size?->value, '⛔ মাপ শেষ সফল রানের নয়।');

        // ⭐ প্রথম চার্ট আগের জায়গাতেই, এখন তারিখের পরিসরসহ
        $this->assertSame(__('backup::dashboard.months_of_copies'), $afterDash->panels[0]->label);
        $this->assertNotNull($afterDash->panels[0]->range);

        // গন্তব্য — শেষ চেষ্টায় ভুল, আর বন্ধ
        BackupDestination::query()->forceCreate([
            'company_id' => $company->id, 'name' => 'Dash SFTP', 'driver' => 'sftp', 'kind' => 'secondary', 'is_active' => true,
            'last_ok_at' => now()->subDays(3), 'last_checked_at' => now()->subHour(), 'last_error' => 'timeout',
        ]);
        BackupDestination::query()->forceCreate([
            'company_id' => $company->id, 'name' => 'Dash Pen', 'driver' => 'local', 'kind' => 'secondary', 'is_active' => false,
        ]);
        BackupPolicy::query()->forceCreate([
            'company_id' => $company->id, 'name' => 'Dash weekly', 'frequency' => 'weekly', 'run_at' => '03:00',
            'backup_type' => 'full', 'scope' => 'all', 'is_active' => true,
        ]);

        $dash = BackupDashboard::dashboard();
        $state = $this->cells($dash, 'destination_list', 'state');
        $this->assertSame(__('backup::dashboard.state_failing'), $state['Dash SFTP'], '⛔ শেষ চেষ্টায় ভুল, তবু "ঠিক আছে"।');
        $this->assertSame(__('backup::dashboard.state_off'), $state['Dash Pen']);
        $this->assertSame(__('backup::screen.days_old', ['days' => 3]), $this->cells($dash, 'destination_list', 'last')['Dash SFTP']);
        $this->assertSame(__('backup::dashboard.every_weekly').' · 03:00', $this->cells($dash, 'schedule', 'when')['Dash weekly']);

        config(['abos.dashboards_v2' => false]);
        $off = BackupDashboard::dashboard();
        $this->assertSame([], $off->panels, '⛔ সুইচ বন্ধ, তবু চার্ট।');
        $this->assertSame([], $off->listings, '⛔ সুইচ বন্ধ, তবু তালিকা।');
        $this->assertCount(count($old->stats), $off->stats);
    }

    /** @return array<string, string> */
    private function runs(DashboardDefinition $d): array
    {
        $panel = collect($d->panels)->firstWhere('label', __('backup::dashboard.last_30_days'));
        $this->assertInstanceOf(Breakdown::class, $panel, 'গত ৩০ দিনের রানের চার্ট নেই।');

        return array_column($panel->parts, 'value', 'label');
    }

    /** @return array<string, string> নাম → ঘরের লেখা */
    private function cells(DashboardDefinition $d, string $listing, string $key): array
    {
        $list = collect($d->listings)->firstWhere('label', __('backup::dashboard.'.$listing));
        $this->assertInstanceOf(Listing::class, $list, "'{$listing}' তালিকা নেই।");
        $column = collect($list->columns)->firstWhere('key', $key);

        return $list->rows->mapWithKeys(fn ($row) => [$row->name => ($column['render'])($row)])->all();
    }
}
