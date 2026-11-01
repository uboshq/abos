<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\SystemAdmin\Support\UserFormTabs;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ব্যবহারকারীর ফর্ম ট্যাবে — পরিচয় · প্রবেশাধিকার (রোল · কোম্পানি · শাখা) · নিরাপত্তা (পাসওয়ার্ড) · আসল ক্ষমতা (কন্ট্রোল প্যানেলের
 * পরিকল্পনা, মালিকের অনুমোদিত, ২১ অক্টোবর ২০২৬, দফা ৮)। একই ফর্ম, একবারে সংরক্ষণ; ভুল যে ট্যাবে, সেটাই খুলে থাকে।
 * ⛔ আগে: এক লম্বা পাতা — পাসওয়ার্ড, রোল আর কোম্পানি সব একসাথে।
 */
final class TheUserFormStandsInTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_part_sits_in_its_own_tab_and_the_identity_tab_opens_first(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $other = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        $html = (string) $this->actingAs($owner)->get(route('system_admin.user.edit', $other))->assertOk()->getContent();

        foreach (['identity', 'access', 'security', 'power'] as $tab) {
            $this->assertMatchesRegularExpression('/data-user-tab-button="'.$tab.'"/', $html, "⛔ \"{$tab}\" ট্যাবের বোতাম নেই।");
            $this->assertMatchesRegularExpression('/data-user-tab="'.$tab.'" x-show="tab === \''.$tab.'\'"/', $html, "⛔ \"{$tab}\"-এর অংশ নিজের ট্যাবে নয়।");
        }
        $this->assertMatchesRegularExpression('/data-user-tab="security"(?:(?!<\/section>).)*name="password"/s', $html, '⛔ পাসওয়ার্ড নিরাপত্তার ট্যাবে নেই।');
        $this->assertMatchesRegularExpression('/data-user-tab="access"(?:(?!<\/section>).)*name="roles\[\]"/s', $html, '⛔ রোল প্রবেশাধিকারের ট্যাবে নেই।');
        $this->assertStringContainsString('x-data="{ tab: \'identity\' }"', $html, '⛔ পাতা খুললে পরিচয়ের ট্যাব খোলা নয়।');
    }

    /** ⓘ পরীক্ষার সেশনে ভুলের ব্যাগ পরের পাতায় পৌঁছায় না (JSON সেশন), তাই নিয়মটা সোজা মাপা — আর পাতা এটাই ডাকে */
    public function test_the_tab_holding_an_error_opens_in_tab_order(): void
    {
        $this->assertSame('security', UserFormTabs::open(['password']), '⛔ পাসওয়ার্ডের ভুল আরেক ট্যাবের পেছনে লুকিয়ে থাকে।');
        $this->assertSame('access', UserFormTabs::open(['password', 'roles']), '⛔ প্রবেশাধিকারের ভুল থাকতেও পরের ট্যাব খুলল।');
        $this->assertSame('identity', UserFormTabs::open(['password_confirmation', 'email']), '⛔ পরিচয়ের ভুল থাকতেও পরের ট্যাব খুলল।');
        $this->assertSame('access', UserFormTabs::open(['customer_scope.4']), '⛔ শাখা-গুদামের ভুল প্রবেশাধিকারে খোলে না।');
        $this->assertSame('identity', UserFormTabs::open([]), '⛔ ভুল না থাকলে পরিচয়ের ট্যাব খোলা নয়।');

        $view = (string) file_get_contents(base_path('app/Modules/SystemAdmin/Resources/views/user/form.blade.php'));
        $this->assertStringContainsString('UserFormTabs::open(array_keys($errors->getMessages()))', $view, '⛔ পাতা ট্যাবের নিয়ম পড়ে না।');
    }
}
