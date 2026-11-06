<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\SettingsService;
use App\Core\Services\SignedSettings;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\SettingChange;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ৭ — "অনুমোদন, বাকির সীমা আর বেতনের সেটিং বদলালে দ্বিতীয় সই লাগে (আপনার আগের সিদ্ধান্ত)";
 * মালিক, ৭ নভেম্বর ২০২৬: "baki kaj gulo kore rako"।
 *
 * দাবি, আসল ফর্ম দিয়ে:
 *  - জরুরি সেটিং (অনুমোদনের নিজের সীমা) বদলালে সঙ্গে সঙ্গে বসে না — অপেক্ষায় যায়; সাধারণ সেটিং আগের মতোই সঙ্গে সঙ্গে বসে।
 *  - অন্য একজন অনুমোদন দিলে তবেই বসে; ফেরত দিলে আগের মতো থাকে।
 *  - যিনি চাইলেন তিনি নিজে অনুমোদন দিতে পারেন না — মালিকও নন।
 *  - অনুমোদন দেওয়ার মতো আর কেউ না থাকলে বদল আটকে যায়, কারণসহ; কিছুই লেখা হয় না।
 *  - তিনটা সেটিং-পর্দাতেই পাহারা (প্রতিষ্ঠানের সেটিংস, কন্ট্রোল প্যানেল, হিসাবের সেটিংস) — বাকির সীমা আর বেতনের চাবিও আওতায়।
 */
final class AKeySettingWaitsForASecondHandTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'approval.self_limit';

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_a_key_setting_waits_and_another_hand_puts_it_in_place(): void
    {
        $second = $this->secondAdmin();
        $before = (string) SignedSettings::stored(app(SettingsService::class)->get(self::KEY));
        $wanted = (string) ((int) $before + 5000);

        $this->actingAs($this->owner)->put(route('system_admin.settings.update'), ['settings' => [self::KEY => $wanted]])->assertRedirect();

        $this->assertSame($before, (string) SignedSettings::stored(app(SettingsService::class)->get(self::KEY)), '⛔ জরুরি সেটিং এক হাতেই বসে গেল — দ্বিতীয় সই ছাড়া।');
        $change = SettingChange::query()->where('key', self::KEY)->where('status', SettingChange::PENDING)->first();
        $this->assertNotNull($change, '⛔ বদলটা অপেক্ষায় লেখা হয়নি।');
        $this->assertSame($wanted, $change->new_value, '⛔ অপেক্ষার সারিতে চাওয়া মান নেই।');
        $this->assertSame((int) $this->owner->id, (int) $change->requested_by, '⛔ কে চাইলেন তা লেখা নেই।');

        // ⛔ নিজে নয় — মালিকও নন
        $this->actingAs($this->owner)->post(route('system_admin.setting-change.sign', $change->public_id));
        $this->assertSame($before, (string) SignedSettings::stored(app(SettingsService::class)->get(self::KEY)), '⛔ যিনি চাইলেন তিনি নিজেই অনুমোদন দিয়ে বসালেন।');

        // ⓘ পাতায় অন্যজনের জন্য বোতাম, নিজের জন্য নয়
        $mine = (string) $this->actingAs($this->owner)->get(route('system_admin.control-panel'))->assertOk()->getContent();
        $this->assertStringContainsString('data-setting-change="'.self::KEY.'"', $mine, '⛔ অপেক্ষার বদল কন্ট্রোল প্যানেলে দেখা যায় না।');
        $this->assertStringNotContainsString('data-setting-change-sign', $mine, '⛔ যিনি চাইলেন তাঁর সামনেই "অনুমোদন দিন" বোতাম।');
        $theirs = (string) $this->actingAs($second)->get(route('system_admin.control-panel'))->assertOk()->getContent();
        $this->assertStringContainsString('data-setting-change-sign', $theirs, '⛔ দ্বিতীয়জনের সামনে "অনুমোদন দিন" বোতাম নেই।');

        $this->actingAs($second)->post(route('system_admin.setting-change.sign', $change->public_id))->assertRedirect();
        $this->assertSame($wanted, (string) SignedSettings::stored(app(SettingsService::class)->get(self::KEY)), '⛔ দ্বিতীয় হাতের অনুমোদনেও সেটিং বসেনি।');
        $this->assertSame(SettingChange::SIGNED, $change->fresh()->status, '⛔ অনুমোদনের পরেও বদলটা "অপেক্ষায়"।');
        $this->assertSame((int) $second->id, (int) $change->fresh()->decided_by, '⛔ কে অনুমোদন দিলেন তা লেখা নেই।');
        $this->assertTrue(AuditTrail::query()->forRecord(SettingChange::class, (int) $change->id)->exists(), '⛔ বদলের চাওয়া আর অনুমোদন নিরীক্ষায় নেই।');
    }

    public function test_a_sent_back_change_leaves_the_setting_as_it_was(): void
    {
        $second = $this->secondAdmin();
        $before = (string) SignedSettings::stored(app(SettingsService::class)->get(self::KEY));

        $this->actingAs($this->owner)->put(route('system_admin.settings.update'), ['settings' => [self::KEY => (string) ((int) $before + 100)]]);
        $change = SettingChange::query()->where('key', self::KEY)->where('status', SettingChange::PENDING)->firstOrFail();

        $this->actingAs($second)->post(route('system_admin.setting-change.reject', $change->public_id), ['note' => 'না'])->assertRedirect();

        $this->assertSame(SettingChange::REJECTED, $change->fresh()->status, '⛔ ফেরতের পরেও বদলটা অপেক্ষায়।');
        $this->assertSame($before, (string) SignedSettings::stored(app(SettingsService::class)->get(self::KEY)), '⛔ ফেরত দেওয়া বদলও বসে গেল।');
    }

    public function test_with_nobody_else_to_approve_the_change_is_refused_and_nothing_is_written(): void
    {
        $before = (string) SignedSettings::stored(app(SettingsService::class)->get(self::KEY));
        // ⓘ ডেমোর অন্য মানুষদের বন্ধ রাখা — এই কোম্পানিতে সেটিংসের চাবিওয়ালা কেবল মালিক
        User::query()->whereKeyNot($this->owner->id)->update(['is_active' => false]);

        $response = $this->actingAs($this->owner)->put(route('system_admin.settings.update'), ['settings' => [self::KEY => (string) ((int) $before + 9)]]);

        $this->assertSame($before, (string) SignedSettings::stored(app(SettingsService::class)->get(self::KEY)), '⛔ দ্বিতীয় কেউ নেই, তবু এক হাতে বসে গেল।');
        $this->assertSame(0, SettingChange::query()->count(), '⛔ অনুমোদনের কেউ নেই, তবু অপেক্ষার সারি লেখা হলো — কেউ কখনো ছাড়বে না।');
        $said = implode(' ', (array) $response->getSession()?->get('setting_waits', []));
        $this->assertStringContainsString(trim(explode(':', __('system_admin::settings.change_blocked'))[0]), $said, '⛔ কেন আটকাল তা বলা হলো না।');
    }

    public function test_an_ordinary_setting_still_saves_at_once(): void
    {
        $this->secondAdmin();
        $key = 'hr.show_left_employees';
        $was = (bool) app(SettingsService::class)->get($key);

        $this->actingAs($this->owner)->put(route('system_admin.settings.update'), ['settings' => array_filter([$key => $was ? null : '1'])]);

        $this->assertSame(! $was, (bool) app(SettingsService::class)->get($key), '⛔ সাধারণ সেটিংও আটকে গেল — কেবল জরুরিগুলো দ্বিতীয় হাত চায়।');
        $this->assertSame(0, SettingChange::query()->count(), '⛔ সাধারণ সেটিংয়ের জন্য অপেক্ষার সারি।');
    }

    public function test_credit_limit_and_salary_settings_are_guarded_too(): void
    {
        $signed = app(SignedSettings::class);

        foreach (['approval.self_limit', 'customer.credit_limit_enabled', 'customer.zero_limit_blocks', 'credit_control.risk_points_block', 'hr.attendance_affects_salary'] as $key) {
            $this->assertTrue($signed->guards($key), "⛔ জরুরি সেটিং পাহারার বাইরে: {$key}");
        }
        $this->assertFalse($signed->guards('hr.show_left_employees'), '⛔ সাধারণ সেটিংও পাহারায় — সব বদল দুই হাতে গেলে কাজ থেমে যায়।');

        foreach (['SystemAdmin/Http/Controllers/SettingsController.php', 'SystemAdmin/Http/Controllers/ControlPanelController.php', 'Accounts/Http/Controllers/AccountsSettingsController.php'] as $screen) {
            $this->assertStringContainsString('$signed->guards($key)', (string) file_get_contents(app_path('Modules/'.$screen)),
                "⛔ এই সেটিং-পর্দায় পাহারা নেই — জরুরি সেটিং এখান দিয়ে এক হাতে বদলানো যায়: {$screen}");
        }
    }

    private function secondAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, function () use ($user): void {
            $user->givePermissionTo(Permission::findOrCreate(SignedSettings::KEY, 'web'));
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
