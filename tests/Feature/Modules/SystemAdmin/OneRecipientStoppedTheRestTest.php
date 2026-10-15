<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ReportRun;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * একজন প্রাপক রিপোর্টটা না পেলে বাকিরা পান (ec, রিপোর্ট লাইন-নিরীক্ষা ⚠️৭, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে: মালিক "ক্রেতা ধরে বিক্রয়, শাখা ১" দুই বিক্রয়কর্মীকে পাঠান — প্রথমজন শাখা ২-তে সীমিত, দ্বিতীয়জন শাখা ১-এ। প্রথমজনের
 * ফাইলে "শাখা নাগালের বাইরে" (ValidationException) লুপটাই ভাঙত — দ্বিতীয়জন রোজ কিছুই পেতেন না, আর সূচি লিখত "error"।
 *
 * দাবি: দ্বিতীয়জন নিজের "ok" ফাইল পান; প্রথমজনের নামে কেবল একটা ফাইলহীন "error" রান; সূচির শেষ অবস্থা "ok"।
 */
final class OneRecipientStoppedTheRestTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_recipient_out_of_reach_does_not_stop_the_next(): void
    {
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $one = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $two = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $person = function (Branch $branch) use ($company): User {
            $user = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true, 'locale' => 'en']);
            $user->companies()->attach($company->id, ['is_active' => true]);
            UserDataScope::query()->withoutGlobalScopes()->create([
                'company_id' => $company->id, 'user_id' => $user->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $branch->id,
            ]);
            CompanyContext::forCompany($company->id, fn () => $user->givePermissionTo(Permission::findOrCreate('sales.report', 'web')));

            return $user;
        };
        $outside = $person($two);
        $inside = $person($one);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        CompanyContext::set($company->id, null);
        $this->actingAs($owner->fresh());
        $schedule = app(ScheduleService::class)->create([
            'report_key' => 'sales.by_customer', 'frequency' => 'daily', 'format' => 'csv',
            'filters' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(), 'branch_id' => $one->id],
            'recipients' => [$outside->id, $inside->id],
        ]);
        Auth::logout();
        app(DataScope::class)->forget();

        $schedule->forceFill(['next_run_at' => now()->subMinute()])->save();
        app(ScheduledReportRunner::class)->runDue();

        $runs = ReportRun::query()->withoutGlobalScopes()->where('report_schedule_id', $schedule->id)->get();
        $for = fn (User $user) => $runs->first(fn (ReportRun $run) => array_map('intval', (array) $run->recipients) === [(int) $user->id]);

        $this->assertNotNull($for($inside), '⛔ প্রথম প্রাপকের ব্যর্থতায় দ্বিতীয়জন ফাইল পাননি।');
        $this->assertContains($for($inside)->status, ['ok', 'empty'], '⛔ দ্বিতীয়জনের ফাইল তৈরি হয়নি।');
        $this->assertNotNull($for($outside), 'নাগালের বাইরের প্রাপকের ব্যর্থতা কোথাও লেখা নেই।');
        $this->assertSame('error', $for($outside)->status);
        $this->assertNull($for($outside)->file_path, '⛔ নাগালের বাইরের প্রাপক কোনো ফাইল পেলেন।');
        $this->assertContains($schedule->fresh()->last_status, ['ok', 'empty'], '⛔ একজনের ব্যর্থতায় গোটা সূচি "error"।');
    }
}
