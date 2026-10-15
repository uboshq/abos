<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
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
 * নির্ধারিত রিপোর্ট কাউকে "মনে রাখুন" সেশন থেকে বের করে না (ec, রিপোর্ট লাইন-নিরীক্ষা ⚠️৯, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে রানার মালিকের নামে `Auth::login()` করে শেষে `Auth::logout()` করত — logout remember_token বদলায়, তাই প্রতিদিন রানের পর
 * মালিকের (আর নিজের ফাইল পাওয়া প্রতিটা প্রাপকের) ব্রাউজার আবার লগইন চাইত।
 *
 * দাবি: রানের পর মালিক আর দেয়ালের প্রাপকের remember_token অক্ষত; রানের পর কোনো পরিচয় আটকে নেই।
 */
final class TheScheduleLoggedTheOwnerOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_run_keeps_every_remember_token(): void
    {
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $branch = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $clerk = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true, 'locale' => 'en']);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $branch->id,
        ]);
        CompanyContext::forCompany($company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('sales.report', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        CompanyContext::set($company->id, null);
        $this->actingAs($owner->fresh());
        $schedule = app(ScheduleService::class)->create([
            'report_key' => 'sales.by_customer', 'frequency' => 'daily', 'format' => 'csv', 'recipients' => [$clerk->id],
        ]);
        Auth::logout();
        CompanyContext::clear();
        app(DataScope::class)->forget();

        $owner->forceFill(['remember_token' => 'owner-remembered'])->save();
        $clerk->forceFill(['remember_token' => 'clerk-remembered'])->save();

        app(ScheduledReportRunner::class)->runOne($schedule->fresh());

        $this->assertSame('owner-remembered', $owner->fresh()->remember_token, '⛔ রানের পর মালিকের "মনে রাখুন" সেশন মরে গেল।');
        $this->assertSame('clerk-remembered', $clerk->fresh()->remember_token, '⛔ রানের পর প্রাপকের "মনে রাখুন" সেশন মরে গেল।');
        $this->assertFalse(Auth::check(), '⛔ রানের পর কারও পরিচয় আটকে রইল।');
    }
}
