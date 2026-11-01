<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
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

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_each_part_sits_in_its_own_tab_and_the_identity_tab_opens_first(): void
    {
        $other = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $html = (string) $this->actingAs($this->owner)->get(route('system_admin.user.edit', $other))->assertOk()->getContent();

        foreach (['identity', 'access', 'security', 'power'] as $tab) {
            $this->assertMatchesRegularExpression('/data-user-tab-button="'.$tab.'"/', $html, "⛔ \"{$tab}\" ট্যাবের বোতাম নেই।");
            $this->assertMatchesRegularExpression('/data-user-tab="'.$tab.'" x-show="tab === \''.$tab.'\'"/', $html, "⛔ \"{$tab}\"-এর অংশ নিজের ট্যাবে নয়।");
        }
        $this->assertMatchesRegularExpression('/data-user-tab="security"(?:(?!<\/section>).)*name="password"/s', $html, '⛔ পাসওয়ার্ড নিরাপত্তার ট্যাবে নেই।');
        $this->assertMatchesRegularExpression('/data-user-tab="access"(?:(?!<\/section>).)*name="roles\[\]"/s', $html, '⛔ রোল প্রবেশাধিকারের ট্যাবে নেই।');
        $this->assertStringContainsString('x-data="{ tab: \'identity\' }"', $html, '⛔ পাতা খুললে পরিচয়ের ট্যাব খোলা নয়।');
    }

    public function test_a_password_refusal_opens_the_security_tab(): void
    {
        $other = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->actingAs($this->owner)->from(route('system_admin.user.edit', $other))
            ->put(route('system_admin.user.update', $other), [
                'name' => $other->name, 'email' => $other->email, 'locale' => 'bn', 'is_active' => '1',
                'password' => 'Abos-Strong-1', 'password_confirmation' => 'Something-else-2',
                'roles' => $other->roles->pluck('name')->all(), 'companies' => $other->companies->pluck('id')->all(),
            ])->assertSessionHasErrors('password');

        $html = (string) $this->get(route('system_admin.user.edit', $other))->getContent();
        $this->assertStringContainsString('x-data="{ tab: \'security\' }"', $html, '⛔ পাসওয়ার্ডের ভুল আরেক ট্যাবের পেছনে লুকিয়ে থাকে।');
    }
}
