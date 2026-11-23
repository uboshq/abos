<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\DataScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ সাপ্তাহিক ছুটির এক ঘর — ছুটির দিনের পর্দার কার্ডে কোম্পানির ডিফল্ট আর নিচে প্রতিটা শাখার বদল (fe-র উত্তর ৪, ২১ অক্টোবর ২০২৬;
 * [[HolidayController::storeWeeklyOff()]], `owned_by`; a6)।
 *
 * ── দাবি ───────────────────────────────────────────────────────────────
 *  · সেটিংসের পাতা আর কন্ট্রোল প্যানেলে ঘরটা নেই; হাতে পাঠালে পুরো অনুরোধ ফেরে, কোথায় বদলায় তা বাংলায় লিখে, আর কিছুই বসে না।
 *  · সাধারণ চাবি (ছেড়ে যাওয়া কর্মী দেখানো) দুই পাতাতেই আগের মতো বসে।
 *  · কার্ড থেকে কোম্পানির ডিফল্ট বদলায় (hr.attendance.manage); শাখায় সীমিত মানুষ পারেন না; দেখার চাবিতে ৪০৩।
 */
final class TheWeeklyOffHasOneHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_company_weekly_off_changes_only_on_the_holiday_card(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($owner->fresh());

        $value = function (): string {
            // ⓘ SettingsService cache — a6/settings-cache-fix
            app(SettingsService::class)->flush();

            return (string) app(SettingsService::class)->get('hr.weekly_off');
        };

        $this->get(route('system_admin.settings'))->assertOk()->assertDontSee('settings[hr.weekly_off]', false);
        // ⛔ হাতে বানানো ফর্মে চাবিটা — নীরবে বাদ নয়, স্পষ্ট "না", কোথায় বদলায় তা লিখে; সেই অনুরোধ থেকে কিছুই বসে না
        $where = __('hr::settings.weekly_off_owned');
        $this->put(route('system_admin.settings.update'), ['settings' => ['hr.weekly_off' => 'sunday', 'hr.show_left_employees' => '1']])
            ->assertSessionHasErrors('settings');
        $this->assertStringContainsString($where, (string) session('errors')->first('settings'), '⛔ ফেরার কারণে জায়গার নাম নেই');
        $this->assertSame('friday', $value(), '⛔ সেটিংসের পাতা থেকে সাপ্তাহিক ছুটি বদলাল');
        $this->assertFalse((bool) app(SettingsService::class)->get('hr.show_left_employees'), '⛔ ফেরানো অনুরোধের অন্য ঘর বসল');

        // ⓘ সাধারণ চাবি আগের মতোই বসে — একই অনুরোধের আকারে
        $this->put(route('system_admin.settings.update'), ['settings' => ['hr.show_left_employees' => '1']])->assertSessionHasNoErrors();
        $this->assertSame('friday', $value());
        $this->assertTrue((bool) app(SettingsService::class)->get('hr.show_left_employees'), '⛔ সেটিংসের পাতায় সাধারণ চাবি বসল না');

        $this->get(route('system_admin.control-panel', ['tab' => 'hr']))->assertOk()->assertDontSee('settings[hr.weekly_off]', false);
        $this->put(route('system_admin.control-panel.update'), ['scope' => ['hr.weekly_off', 'hr.show_left_employees'],
            'settings' => ['hr.weekly_off' => 'sunday']])->assertSessionHasErrors('settings');
        $this->assertStringContainsString($where, (string) session('errors')->first('settings'));
        $this->assertSame('friday', $value(), '⛔ কন্ট্রোল প্যানেল থেকে সাপ্তাহিক ছুটি বদলাল');
        $this->assertTrue((bool) app(SettingsService::class)->get('hr.show_left_employees'), '⛔ ফেরানো অনুরোধে অন্য ঘর বন্ধ হল');

        // ⓘ কেবল settings[]-এ এলেও ফেরে
        $this->put(route('system_admin.control-panel.update'), ['scope' => ['hr.show_left_employees'],
            'settings' => ['hr.weekly_off' => 'sunday']])->assertSessionHasErrors('settings');

        // ⓘ সাধারণ চাবি আগের মতোই — scope-এ আছে, ঘর নেই = বন্ধ
        $this->put(route('system_admin.control-panel.update'), ['scope' => ['hr.show_left_employees'], 'settings' => []])->assertSessionHasNoErrors();
        $this->assertSame('friday', $value());
        $this->assertFalse((bool) app(SettingsService::class)->get('hr.show_left_employees'), '⛔ কন্ট্রোল প্যানেলে সাধারণ চাবি বসল না');

        $this->get(route('hr.holiday.index'))->assertOk()->assertSee('data-weekly-off-company', false);
        $this->post(route('hr.holiday.weekly_off.store'), ['branch_id' => '', 'weekly_off' => 'saturday'])->assertSessionHasNoErrors();
        $this->assertSame('saturday', $value(), '⛔ কার্ড থেকে কোম্পানির ডিফল্ট বদলায়নি');

        // ⛔ শাখায় সীমিত — কোম্পানির নিয়ম নয়; দেখার চাবি — ৪০৩
        $limited = $this->person($company, ['hr.attendance.view', 'hr.attendance.manage']);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $limited->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $company->defaultBranch()?->id]);
        app(DataScope::class)->forget();
        $this->actingAs($limited)->post(route('hr.holiday.weekly_off.store'), ['branch_id' => '', 'weekly_off' => 'none'])
            ->assertSessionHasErrors('branch_id');

        $viewer = $this->person($company, ['hr.attendance.view']);
        $this->actingAs($viewer)->post(route('hr.holiday.weekly_off.store'), ['branch_id' => '', 'weekly_off' => 'none'])->assertForbidden();
        $this->assertSame('saturday', $value());
    }

    /** @param  list<string>  $keys */
    private function person(Company $company, array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $user->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
