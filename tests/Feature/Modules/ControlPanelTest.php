<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
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
     * প্রতিটা ঘোষিত সুইচ কোনো না কোনো ট্যাবে আছে।
     *
     * তালিকাটা রেজিস্ট্রি থেকে নেওয়া, হাতে লেখা তালিকা থেকে নয় — নতুন
     * মডিউল বা নতুন সুইচ যোগ হলে সেটাও এই পরীক্ষায় ধরা পড়বে, আর
     * পরীক্ষাটা হালনাগাদ করতে হবে না।
     *
     * ── ট্যাব আসার পর, ৩০ আগস্ট ২০২৬ ────────────────────────────────
     * আগে দাবিটা ছিল "সবগুলো **এই পাতায়** আছে"। এখন প্রতিটা মডিউলের
     * নিজের ট্যাব, তাই পরীক্ষাটাও ট্যাব ধরে হাঁটে।
     *
     * আর এভাবেই দাবিটা বরং **শক্ত হলো**: ট্যাবের তালিকাটা যদি কোনো
     * মডিউল বাদ দেয়, তার সুইচগুলো কোনো ট্যাবেই মিলবে না — অর্থাৎ
     * এটা এখন "কিছু হারায়নি" আর "ট্যাবগুলো সবটা ঢাকে" দুইটাই পাহারা
     * দেয়।
     */
    public function test_every_switch_any_module_declares_appears_here(): void
    {
        $declared = app(SettingsService::class)->definitions();

        $this->assertGreaterThan(0, count($declared), 'No module declares any setting at all.');

        $first = $this->get(route('system_admin.control-panel'))->assertOk();

        $pages = [(string) $first->getContent()];

        foreach ($first->viewData('tabs') as $tab) {
            $pages[] = (string) $this->get(route('system_admin.control-panel', ['tab' => $tab['key']]))
                ->assertOk()->getContent();
        }

        $everything = implode('', $pages);

        foreach ($declared as $key => $definition) {
            /*
             * মেনুর সুইচগুলো নিয়ম ধরে তৈরি, ঘোষিত নয় — ওদের নিজের
             * ছক আছে আর নিজের পরীক্ষা
             * ([[EveryMenuRowHasItsOwnSwitchTest]])।
             */
            if ($definition['menu'] ?? false) {
                continue;
            }

            $this->assertStringContainsString("settings[{$key}]", $everything,
                "ঘোষিত সুইচ {$key} কোনো ট্যাবেই নেই।");

            // লেবেলটা escape করেই যাচাই: কিছু লেবেলে উদ্ধৃতি চিহ্ন আছে
            // ("Powered by ABOS"), আর Blade সেটা &quot; করে দেয় — কাঁচা
            // স্ট্রিং খুঁজলে ওই একটাই বাদ পড়ত
            $this->assertStringContainsString(e(__($definition['label'])), $everything,
                "ঘোষিত সুইচ {$key}-এর লেবেল কোনো ট্যাবেই নেই।");
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

        /*
         * `scope[]` — ফর্ম কোন সুইচগুলো বহন করছে, ৩০ আগস্ট ২০২৬।
         *
         * ট্যাব আসার আগে সব সুইচ এক পাতায় থাকত, তাই "অনুপস্থিত" মানে
         * নিশ্চিতভাবেই "টিক তুলে দেওয়া হয়েছে"। এখন একটা পাঠানোয় কেবল
         * একটা ট্যাবের ঘর থাকে, তাই ফর্মকে বলে দিতে হয় সে কী বহন
         * করছে — নাহলে অন্য ট্যাবের সুইচগুলোও "অনুপস্থিত" পড়ে বন্ধ
         * হয়ে যেত। একবার সত্যিই ৩৪টা বন্ধ হয়েছিল।
         *
         * ক্রেডিট লিমিট scope-এ আছে কিন্তু settings-এ নেই — সেটাই
         * "টিক তুলে দেওয়া হয়েছে"।
         */
        $this->put(route('system_admin.control-panel.update'), [
            'scope' => [
                'customer.credit_limit_enabled',
                'accounts.backdate_days',
                'master_data.region_enabled',
            ],
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
     * পর্দার সুইচ বন্ধ করলে সারিটা মেনু থেকেই উধাও।
     *
     * শুধু সেটিংসটা সেভ হওয়া যথেষ্ট নয় — সেভ হয়েও মেনুতে সারিটা থেকে
     * গেলে ব্যবহারকারীর কাছে সুইচটার কোনো মানে থাকত না।
     */
    public function test_turning_a_screen_off_takes_it_out_of_the_menu(): void
    {
        $menu = app(MenuBuilder::class);

        $names = fn () => collect($menu->forUser($this->user->fresh()))
            ->flatMap(fn ($module) => collect($module['groups'] ?? [])->flatten(1))
            ->pluck('route')
            ->all();

        /*
         * সরাসরি বিক্রয়ের পর্দাটা বেছে নেওয়া হল, অর্ডারের নয়।
         *
         * অর্ডারের পর্দা নিজের কাগজ ধরে রাখে, তাই ডেমো ডেটায় সেটা বন্ধই
         * করা যায় না (নিচের পরীক্ষাটা ঠিক সেটাই দেখে)। সরাসরি বিক্রয়ের
         * নিজের কোনো কাগজ নেই — সে চালান আর বিল বানায়, যেগুলোর নিজের
         * পর্দা আলাদা।
         */
        $this->assertContains('sales.direct.create', $names());

        $this->put(route('system_admin.control-panel.update'), [
            'scope' => ['sales.screen_direct'],
            'settings' => ['sales.screen_direct' => '0'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        app(SettingsService::class)->flush();

        $this->assertNotContains('sales.direct.create', $names());
    }

    /**
     * কাগজ ধরে থাকা পর্দা আড়াল করা যায় না।
     *
     * ── কেন এই পাহারা ──────────────────────────────────────────────
     * সুইচ বন্ধ মানে মেনু থেকে সারিটা উধাও। দশটা অর্ডার ঝুলে থাকা
     * অবস্থায় কেউ অর্ডার-পর্দা বন্ধ করলে ওই দশটা কাগজের আর কোনো দরজা
     * থাকত না — অথচ সেগুলো বাতিলও হয়নি, শেষও হয়নি।
     */
    public function test_a_screen_that_holds_documents_refuses_to_hide(): void
    {
        $settings = app(SettingsService::class);

        /*
         * একটা অর্ডার নিজেরাই বানানো — ডেমো ডেটার উপর ভরসা করে নয়।
         *
         * প্রথমে ধরে নেওয়া হয়েছিল ডেমোতে অর্ডার আছে; ছিল না, আর তখন
         * পরীক্ষাটা পাহারাটার বদলে ডেমো ডেটার গঠন যাচাই করছিল।
         */
        app(SalesOrderService::class)->create(
            [
                'customer_id' => Customer::query()->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => Product::query()->firstOrFail()->id,
                'ordered_qty' => '10',
                'rate' => '200',
            ]],
        );

        $this->assertTrue(SalesOrder::query()->exists());

        $this->put(route('system_admin.control-panel.update'), [
            'scope' => ['sales.screen_orders'],
            'settings' => ['sales.screen_orders' => '0'],
        ])->assertSessionHasErrors('settings');

        $settings->flush();

        // সুইচটা যেমন ছিল তেমনই — আর সারিটাও মেনুতে আছে
        $this->assertTrue($settings->enabled('sales.screen_orders'));
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
            'scope' => ['accounts.backdate_days'],
            'settings' => ['accounts.backdate_days' => 45],
        ])->assertRedirect();

        $this->get(route('accounts.settings'))
            ->assertOk()
            ->assertSee('value="45"', false);
    }

    public function test_one_company_never_changes_anothers_switches(): void
    {
        $this->put(route('system_admin.control-panel.update'), [
            'scope' => ['accounts.backdate_days'],
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
