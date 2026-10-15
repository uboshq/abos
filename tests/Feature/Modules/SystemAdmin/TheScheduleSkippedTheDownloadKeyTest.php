<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\ReportRun;
use App\Models\User;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * নির্ধারিত ফাইল রিপোর্টের আলাদা "ফাইলে নেওয়ার" চাবি মানে (ec, রিপোর্ট লাইন-নিরীক্ষা ⚠️১০, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে সূচি, প্রাপক বাছা আর নামানো কেবল দেখার চাবি দেখত: `notification.reports` আছে, `notification.reports.export` নেই — এমন মানুষও
 * রোজ প্রাপকের নাম আর প্রদানকারীর রেফারেন্সের CSV পেতেন, যা ওয়েব আর ফোনের রপ্তানি তাঁকে দেয় না।
 *
 * দাবি: চাবি ছাড়া প্রাপক বাছা যায় না; চাবি কেড়ে নিলে পরের রানে তিনি নেই, আর আগের ফাইল নামাতে ৪০৩।
 */
final class TheScheduleSkippedTheDownloadKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_file_needs_the_reports_own_export_key(): void
    {
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $reader = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id, 'is_active' => true]);
        $reader->companies()->attach($company->id, ['is_active' => true]);
        $key = function (string $what, string $name) use ($company, $reader): void {
            CompanyContext::forCompany($company->id, fn () => $what === 'give'
                ? $reader->givePermissionTo(Permission::findOrCreate($name, 'web'))
                : $reader->revokePermissionTo($name));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        };
        $key('give', 'notification.reports');

        $this->actingAs($owner);
        $data = ['report_key' => 'notification.attempt_history', 'frequency' => 'daily', 'format' => 'csv', 'recipients' => [$reader->id]];

        try {
            app(ScheduleService::class)->create($data);
            $this->fail('⛔ ফাইলের চাবি ছাড়া মানুষকে প্রাপক বাছা গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recipients', $e->errors());
        }

        $key('give', 'notification.reports.export');
        $schedule = app(ScheduleService::class)->create($data);
        Auth::logout();
        CompanyContext::clear();

        $first = app(ScheduledReportRunner::class)->runOne($schedule->fresh());
        $this->assertContains((int) $reader->id, array_map('intval', (array) $first->recipients), 'চাবিসহ প্রাপকই ফাইল পাননি — দাবি অন্ধ।');

        $key('revoke', 'notification.reports.export');
        $second = app(ScheduledReportRunner::class)->runOne($schedule->fresh());
        foreach (ReportRun::query()->withoutGlobalScopes()->whereKey($second->id)->get() as $run) {
            $this->assertNotContains((int) $reader->id, array_map('intval', (array) $run->recipients), '⛔ ফাইলের চাবি কেড়ে নেওয়ার পরও ফাইল পেলেন।');
        }

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($reader->fresh())->get(route('system_admin.reports.download', $first))->assertForbidden();
    }
}
