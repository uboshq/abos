<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LoginAttempt;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ লগইন নিরাপত্তা এক পাতায় — কার দুই-ধাপ চালু, গত ৭ দিনের ভুল লগইন, ফোনে লগইন, আপনাআপনি লগআউট (কন্ট্রোল প্যানেলের পরিকল্পনা,
 * মালিকের অনুমোদিত, ২১ অক্টোবর ২০২৬, দফা ৯)। ⛔ আগে: দুই-ধাপ ব্যবহারকারীর তালিকায়, লগইনের খাতা নিরীক্ষায়, লগআউট সেটিংসে — ছড়ানো।
 */
final class LoginSecurityStandsOnOnePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shows_two_step_and_only_real_wrong_logins_of_this_companys_people(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $accountant->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()])->save();
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $sales->forceFill(['mfa_secret' => null, 'mfa_confirmed_at' => null, 'two_step_required' => true])->save();
        $stranger = User::factory()->create(['email' => 'stranger-elsewhere@abos.test']);

        $try = fn (?int $user, string $who, string $reason, int $daysAgo) => LoginAttempt::query()->forceCreate([
            'user_id' => $user, 'identifier' => $who, 'succeeded' => false, 'reason' => $reason,
            'ip_address' => '10.0.0.9', 'created_at' => now()->subDays($daysAgo),
        ]);
        $try($accountant->id, 'wrong-password-here@abos.test', LoginAttempt::WRONG_PASSWORD, 1);
        $try($accountant->id, 'only-asked-for-code@abos.test', LoginAttempt::NEEDS_CODE, 1);
        $try($accountant->id, 'eight-days-old@abos.test', LoginAttempt::WRONG_CODE, 8);
        $try($stranger->id, 'stranger-elsewhere@abos.test', LoginAttempt::WRONG_PASSWORD, 1);
        $try(null, 'typed-unknown-name@abos.test', LoginAttempt::UNKNOWN, 1);

        $html = (string) $this->actingAs($owner)->get(route('system_admin.login-security'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-security-person="'.$accountant->id.'"(?:(?!<\/tr>).)*data-two-step="on"/s', $html, '⛔ দুই-ধাপ চালু মানুষটা চালু দেখায় না।');
        $this->assertMatchesRegularExpression('/data-security-person="'.$sales->id.'"(?:(?!<\/tr>).)*data-two-step="required"/s', $html, '⛔ বাধ্যতামূলক অথচ বসানো হয়নি — পাতা চুপ।');
        $this->assertStringNotContainsString('data-security-person="'.$stranger->id.'"', $html, '⛔ আরেক কোম্পানির মানুষ এই পাতায়।');

        $this->assertStringContainsString('wrong-password-here@abos.test', $html, '⛔ ভুল পাসওয়ার্ড পাতায় নেই।');
        $this->assertStringNotContainsString('only-asked-for-code@abos.test', $html, '⛔ কোড চাওয়ার ধাপ ভুল লগইন হিসেবে গোনা হলো।');
        $this->assertStringNotContainsString('eight-days-old@abos.test', $html, '⛔ ৭ দিনের পুরনো চেষ্টা এখনো দেখায়।');
        $this->assertStringNotContainsString('stranger-elsewhere@abos.test', $html, '⛔ আরেক কোম্পানির মানুষের ভুল লগইন ফাঁস হলো।');
        $this->assertStringNotContainsString('typed-unknown-name@abos.test', $html, '⛔ অচেনা নামের চেষ্টা কোম্পানির পাতায় ফাঁস হলো।');
        $this->assertMatchesRegularExpression('/data-security-card="failed"(?:(?!<\/div>).)*>\s*1\s*</s', $html, '⛔ ভুল লগইনের সংখ্যা তালিকার সাথে মেলে না।');
    }

    public function test_it_sits_in_the_people_section_behind_the_user_key(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $module = require base_path('app/Modules/SystemAdmin/module.php');
        $row = collect($module['menu'])->flatten(1)->firstWhere('route', 'system_admin.login-security');
        $this->assertNotNull($row, '⛔ মেনুতে লগইন নিরাপত্তার সারি নেই।');
        $this->assertSame('people', $row['section'] ?? null, '⛔ সারিটা মানুষ ও প্রবেশাধিকার ভাগে নয়।');
        $this->assertSame('system_admin.user.manage', $row['permission'] ?? null, '⛔ সারিটা ব্যবহারকারীর চাবির পেছনে নয়।');

        $this->assertFalse($sales->can('system_admin.user.manage'), 'ⓘ পরীক্ষার ভিত্তি: বিক্রয়কর্মীর ব্যবহারকারীর চাবি নেই।');
        $this->assertSame(403, $this->actingAs($sales)->get(route('system_admin.login-security'))->status(), '⛔ চাবি ছাড়া মানুষও লগইন নিরাপত্তা দেখে।');
        $this->actingAs($owner)->get(route('system_admin.login-security'))->assertOk();
    }
}
