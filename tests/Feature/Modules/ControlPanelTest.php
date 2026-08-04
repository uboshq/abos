<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Control Panel — সব মডিউলের সুইচ এক জায়গায়।
 *
 * এই পর্দাটার একমাত্র দাবি: মডিউল যা ঘোষণা করে, তার সবই এখানে আসে।
 * একটা বাদ পড়লে সেটা কোথাও দেখা যেত না, আর ব্যবহারকারী ভাবত সুইচটা
 * নেই — অথচ কোডে সেটা আছে আর নীরবে ডিফল্ট মানে চলছে।
 */
class ControlPanelTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    /**
     * প্রতিটা ঘোষিত সুইচ এখানে আছে।
     *
     * তালিকাটা রেজিস্ট্রি থেকে নেওয়া, হাতে লেখা তালিকা থেকে নয় — নতুন
     * মডিউল বা নতুন সুইচ যোগ হলে সেটাও এই পরীক্ষায় ধরা পড়বে, আর
     * পরীক্ষাটা হালনাগাদ করতে হবে না।
     */
    public function test_every_switch_any_module_declares_appears_here(): void
    {
        $declared = app(SettingsService::class)->definitions();

        $this->assertGreaterThan(0, count($declared), 'No module declares any setting at all.');

        $response = $this->get(route('system_admin.control-panel'))->assertOk();

        foreach ($declared as $key => $definition) {
            $response->assertSee("settings[{$key}]", false);

            // লেবেলটা escape করেই যাচাই: কিছু লেবেলে উদ্ধৃতি চিহ্ন আছে
            // ("Powered by ABOS"), আর Blade সেটা &quot; করে দেয় — কাঁচা
            // স্ট্রিং খুঁজলে ওই একটাই বাদ পড়ত
            $response->assertSee(__($definition['label']));
        }
    }

    public function test_switches_are_grouped_under_the_module_that_declared_them(): void
    {
        $response = $this->get(route('system_admin.control-panel'))->assertOk();

        foreach (app(ModuleRegistry::class)->all() as $module) {
            $hasSettings = collect(app(SettingsService::class)->definitions())
                ->contains(fn (array $d) => ($d['module'] ?? null) === $module->code);

            if ($hasSettings) {
                $response->assertSee($module->label(), false);
            }
        }
    }

    public function test_saving_from_here_changes_what_the_modules_read(): void
    {
        $settings = app(SettingsService::class);

        $this->assertTrue($settings->enabled('customer.credit_limit_enabled'));
        $this->assertSame(7, $settings->get('accounts.backdate_days'));

        $this->put(route('system_admin.control-panel.update'), [
            'settings' => [
                // ক্রেডিট লিমিটের চেকবক্স অনুপস্থিত — মানে বন্ধ
                'accounts.backdate_days' => 21,
                'master_data.region_enabled' => '1',
            ],
        ])->assertRedirect();

        $settings->flush();

        $this->assertFalse($settings->enabled('customer.credit_limit_enabled'));
        $this->assertSame(21, $settings->get('accounts.backdate_days'));
        $this->assertTrue($settings->enabled('master_data.region_enabled'));
    }

    /**
     * এখানে বদলালে মডিউলের নিজের পর্দাতেও সেটাই দেখায়।
     *
     * দুইটা পর্দা একই সেটিংসে লেখে, তাই একটায় বদলে অন্যটায় পুরনো মান
     * দেখানোটা সবচেয়ে সহজ ভুল — আর সেটা ধরা পড়ত তখনই, যখন কেউ দুইবার
     * একই জিনিস বদলাতে গিয়ে বিভ্রান্ত হত।
     */
    public function test_the_two_screens_never_disagree(): void
    {
        $this->put(route('system_admin.control-panel.update'), [
            'settings' => ['accounts.backdate_days' => 45],
        ])->assertRedirect();

        $this->get(route('accounts.settings'))
            ->assertOk()
            ->assertSee('value="45"', false);
    }

    public function test_one_company_never_changes_anothers_switches(): void
    {
        $this->put(route('system_admin.control-panel.update'), [
            'settings' => ['accounts.backdate_days' => 60],
        ])->assertRedirect();

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $settings = app(SettingsService::class);
        $settings->flush();

        // অন্য কোম্পানিতে ঘোষিত ডিফল্টটাই থাকে
        $this->assertSame(7, $settings->get('accounts.backdate_days'));
    }

    public function test_only_someone_who_can_manage_settings_may_open_it(): void
    {
        $clerk = User::factory()->create();
        $clerk->companies()->attach($this->company, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();
        $clerk->givePermissionTo(Permission::findOrCreate('accounts.view', 'web'));

        $this->actingAs($clerk);

        $this->get(route('system_admin.control-panel'))->assertForbidden();
        $this->put(route('system_admin.control-panel.update'), ['settings' => []])->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        auth()->logout();

        $this->get(route('system_admin.control-panel'))->assertRedirect(route('login'));
    }
}
