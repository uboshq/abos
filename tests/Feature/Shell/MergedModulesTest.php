<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Module\ModuleRegistry;
use App\Core\Navigation\MenuLook;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInPastTheSecondStep;
use Tests\TestCase;

/**
 * ⭐ তিনটা এক করা মডিউল — মালিক, ২০ নভেম্বর ২০২৬ (SAP S/4 Finance · D365 · Oracle · NetSuite: এক মডিউলে কয়েক ভাগ):
 *   হিসাব ও অর্থ = হিসাব + অর্থ + স্থায়ী সম্পদ · বিক্রয় = বিক্রয় + গ্রাহক + বাকি নিয়ন্ত্রণ + অফার · ক্রয় = ক্রয় + সরবরাহকারী।
 *
 * দাবি:
 *   ১. প্রতিটা সদস্যের প্রতিটা মেনু সারি মালিকের এক করা মডিউলে, একই ঠিকানায় — একটাও হারায়নি
 *   ২. রুট আর চাবি বদলায়নি — সারির রুট সদস্যের নিজের, চাবিও module.php-র; ঠিকানা আগের মতোই খোলে
 *   ৩. অতিথিরা আর আলাদা মডিউল নয় — মেনু, রেল আর মডিউল-বাছাইয়ে নেই
 *   ৪. এক করা ড্যাশবোর্ডে সদস্যদের ট্যাব, ঠিক যে পাতায় আছি সেটা চিহ্নিত
 *   ৫. কোনো সদস্য বন্ধ হলে তার সারি আর ট্যাব নেই; আশ্রয়দাতা বন্ধ হলে অতিথি নিজের টাইলে ফেরে
 *   ৬. ফোনের মেনুও একই — অতিথির সারি আশ্রয়দাতার ভিতরে, ফোনে বন্ধ সদস্যের সারি নেই
 *   ৭. হিসাব ও অর্থের নয়টা ভাগ মালিকের ক্রমে
 */
final class MergedModulesTest extends TestCase
{
    use RefreshDatabase;
    use SignsInPastTheSecondStep;

    /** আশ্রয়দাতা → অতিথি, আর ড্যাশবোর্ডের ট্যাবের নাম (bn) মালিকের ক্রমে */
    private const FAMILIES = [
        'accounts' => ['guests' => ['finance', 'fixed_assets'], 'tabs' => ['হিসাব', 'অর্থ / ট্রেজারি', 'স্থায়ী সম্পদ'], 'label' => 'হিসাব ও অর্থ'],
        'sales' => ['guests' => ['customer', 'credit_control', 'promotion'], 'tabs' => ['বিক্রয়', 'গ্রাহক', 'বাকি নিয়ন্ত্রণ', 'অফার'], 'label' => 'বিক্রয়'],
        'purchase' => ['guests' => ['supplier'], 'tabs' => ['ক্রয়', 'সরবরাহকারী'], 'label' => 'ক্রয়'],
    ];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        app()->setLocale('bn');
        $this->actingAs($this->owner);
    }

    public function test_every_member_row_is_in_the_merged_module_at_the_same_address(): void
    {
        $this->owner->forceFill(['ui' => 'rose'])->save();
        $menu = $this->menu();

        /*
         * ⓘ মাপকাঠি = এক না করা মেনু (SAP/Dynamic-এর মতো, [[MenuBuilder::forUser()]] `merged: false`) — প্রতিটা সদস্য একা থাকলে
         *   ঠিক যে সারিগুলো দেখাত। ⛔ module.php-র সব সারি নয়: সুইচে বন্ধ পর্দা (যেমন কাউন্টার, `sales.screen_pos` ডিফল্ট বন্ধ)
         *   একা থাকলেও দেখায় না, তাই এক করার পরেও না থাকাটাই ঠিক (Mac run, ২১ নভেম্বর ২০২৬: sales › sales::menu.pos)।
         */
        $apart = app(MenuBuilder::class)->forUser($this->owner->fresh(), merged: false);
        $checked = 0;

        foreach (self::FAMILIES as $host => $family) {
            $entry = $this->entry($menu, $host);
            $this->assertSame($family['label'], $entry['label'], "⛔ {$host}: এক করা মডিউলের নাম");
            $urls = collect($entry['groups'])->flatten(1)->pluck('url')->filter()->all();

            foreach ([$host, ...$family['guests']] as $member) {
                $this->assertContains($member, $entry['codes'], "⛔ {$member} {$host}-এর ভিতরে নেই");

                foreach (collect($this->entry($apart, $member)['groups'])->flatten(1) as $row) {
                    if (($row['url'] ?? null) === null) {
                        continue;
                    }

                    $this->assertContains($row['url'], $urls, "⛔ {$member} › {$row['key']} ({$row['url']}) — {$host}-এর মেনুতে নেই, সারিটা হারিয়েছে");
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(200, $checked, '⛔ দাবিটা প্রায় কিছুই দেখল না');
    }

    public function test_routes_and_keys_did_not_change(): void
    {
        $menu = $this->menu();
        $registry = app(ModuleRegistry::class);

        foreach (self::FAMILIES as $host => $family) {
            foreach (collect($this->entry($menu, $host)['groups'])->flatten(1) as $row) {
                $own = $registry->get((string) $row['module']);
                $this->assertNotNull($own, "⛔ সারি \"{$row['label']}\" কোন মডিউলের তা জানা নেই");

                $declared = collect($own->menu)->flatten(1)->first(fn (array $i): bool => $i['label'] === $row['key'] && $i['route'] === $row['route']);
                $this->assertNotNull($declared, "⛔ {$row['module']} › {$row['key']}: সারিটা নিজের module.php-তে এই রুটে ঘোষিত নয় — রুট বদলেছে");
            }
        }

        // ⓘ পুরনো ঠিকানাগুলো আগের মতোই খোলে — বুকমার্ক ভাঙেনি
        foreach (['finance', 'customer', 'credit_control', 'promotion', 'supplier'] as $code) {
            $this->assertStringEndsWith('/dashboard/'.$code, route('module.dashboard', ['module' => $code]));
            $this->get(route('module.dashboard', ['module' => $code]))->assertOk();
        }
        $this->get(route('accounts.asset.dashboard'))->assertOk();
        $this->get(route('finance.budget.index'))->assertOk();
        $this->get(route('customer.index'))->assertOk();
        $this->get(route('supplier.index'))->assertOk();
    }

    public function test_the_guests_are_no_longer_modules_of_their_own(): void
    {
        $this->owner->forceFill(['ui' => 'rose'])->save();
        $codes = array_column($this->menu(), 'code');

        foreach (self::FAMILIES as $host => $family) {
            $this->assertContains($host, $codes);
            foreach ($family['guests'] as $guest) {
                $this->assertNotContains($guest, $codes, "⛔ {$guest} এখনো আলাদা মডিউল — {$host}-এর ভিতরে বসার কথা");
            }
        }

        // ⓘ রেল (ABOS Navy / Blue) আর ABOS-এর মডিউল-বাছাই — দুইটাই মেনু থেকে আঁকা
        // ⛔ কেবল এক করা রূপগুলো — SAP (tiles) আর Dynamic আগের মতো আলাদা (মালিক, ২০ নভেম্বর ২০২৬: "100% অরিজিনাল"),
        //   ওদের দাবি [[test_sap_and_dynamic_keep_the_modules_apart_as_before()]]-এ
        foreach (['navy', 'blue', 'rose'] as $look) {
            $this->owner->forceFill(['ui' => $look])->save();
            $html = (string) $this->actingAs($this->owner->fresh())->get(route('module.dashboard', ['module' => 'accounts']))->assertOk()->getContent();

            foreach (['finance', 'fixed_assets', 'customer', 'credit_control', 'promotion', 'supplier'] as $guest) {
                $this->assertStringNotContainsString('data-rail-module="'.$guest.'"', $html, "⛔ {$look}: {$guest}-এর আলাদা টাইল রেলে");
            }

            $this->assertStringContainsString('হিসাব ও অর্থ', $html, "⛔ {$look}: এক করা মডিউলের নাম পাতায় নেই");
        }
    }

    public function test_a_guest_page_belongs_to_its_host(): void
    {
        $this->owner->forceFill(['ui' => 'rose'])->save();

        foreach (['finance.budget.index' => 'accounts', 'customer.index' => 'sales', 'supplier.index' => 'purchase'] as $route => $host) {
            $xpath = $this->xpath((string) $this->actingAs($this->owner->fresh())->get(route($route))->assertOk()->getContent());
            $panel = $xpath->query('//*[@data-site-map]')->item(0);
            $this->assertNotNull($panel);
            $this->assertSame($host, $panel->getAttribute('data-active-module'), "⛔ {$route}: পাশের মেনু {$host}-এর নয়");
            $this->assertSame(1, $xpath->query('//*[@data-site-map]//a[@aria-current="page"]')->length, "⛔ {$route}: মেনুতে এই পাতার সারি জ্বলেনি");
        }
    }

    public function test_the_merged_dashboard_opens_on_tabs_with_every_member_dashboard_intact(): void
    {
        $this->owner->forceFill(['ui' => 'rose'])->save();

        $pages = [
            'accounts' => [route('module.dashboard', ['module' => 'accounts']), route('module.dashboard', ['module' => 'finance']), route('accounts.asset.dashboard')],
            'sales' => [route('module.dashboard', ['module' => 'sales']), route('module.dashboard', ['module' => 'customer']),
                route('module.dashboard', ['module' => 'credit_control']), route('module.dashboard', ['module' => 'promotion'])],
            'purchase' => [route('module.dashboard', ['module' => 'purchase']), route('module.dashboard', ['module' => 'supplier'])],
        ];

        foreach ($pages as $host => $urls) {
            foreach ($urls as $at => $url) {
                $xpath = $this->xpath((string) $this->actingAs($this->owner->fresh())->get($url)->assertOk()->getContent());
                $tabs = $xpath->query('//nav[@data-merged-tabs]//a');

                $this->assertSame(count($urls), $tabs->length, "⛔ {$url}: ট্যাবের সংখ্যা");
                foreach ($tabs as $i => $tab) {
                    $this->assertSame(self::FAMILIES[$host]['tabs'][$i], trim($tab->textContent), "⛔ {$url}: ট্যাব {$i}-এর নাম");
                    $this->assertSame($urls[$i], $tab->getAttribute('href'), "⛔ {$url}: ট্যাব {$i} সদস্যের নিজের ড্যাশবোর্ডে যায় না");
                    $this->assertSame($i === $at, $tab->getAttribute('aria-current') === 'page', "⛔ {$url}: চিহ্নিত ট্যাব ভুল");
                }
            }
        }

        // ⓘ ড্যাশবোর্ড নয় এমন পাতায় ট্যাব নেই
        $this->assertStringNotContainsString('data-merged-tabs', (string) $this->get(route('customer.index'))->getContent());
    }

    public function test_a_switched_off_member_hides_its_rows_and_its_tab(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('finance.enabled', false);
        $settings->flush();

        $entry = $this->entry($this->menu(), 'accounts');
        $this->assertNotContains('finance', $entry['codes']);
        $this->assertSame([], collect($entry['groups'])->flatten(1)->where('module', 'finance')->all(), '⛔ বন্ধ অর্থের সারি এখনো দেখা যায়');
        $this->assertNotContains('finance', array_column($entry['members'], 'code'), '⛔ বন্ধ অর্থের ট্যাব এখনো আছে');
        $this->assertContains('fixed_assets', array_column($entry['members'], 'code'));
    }

    public function test_a_switched_off_host_lets_its_guests_stand_alone(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('sales.enabled', false);
        $settings->flush();

        $codes = array_column($this->menu(), 'code');
        $this->assertNotContains('sales', $codes);

        foreach (['customer', 'credit_control', 'promotion'] as $guest) {
            $this->assertContains($guest, $codes, "⛔ বিক্রয় বন্ধ, তবু {$guest}-এর পাতাগুলো আর কোথাও নেই");
        }
    }

    public function test_the_phone_menu_follows_the_merge_and_each_rows_own_switch(): void
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'owner@abos.test',
            'password' => 'password',
            'code' => $this->secondStepCode('owner@abos.test'),
            'deviceId' => 'handset-merged',
            'appVersion' => '0.4.58',
            'platform' => 'android',
        ])->assertOk()->json('accessToken');

        $menu = $this->phoneMenu($token);
        $codes = array_column($menu, 'code');

        foreach (self::FAMILIES as $host => $family) {
            foreach ($family['guests'] as $guest) {
                $this->assertNotContains($guest, $codes, "⛔ ফোনে {$guest} আলাদা মডিউল");
            }
        }

        $accounts = collect($menu)->firstWhere('code', 'accounts');
        $this->assertSame('হিসাব ও অর্থ', $accounts['label']);
        $this->assertNotEmpty(collect($accounts['groups'])->flatten(1)->where('module', 'finance')->all(), '⛔ ফোনে হিসাব ও অর্থের ভিতরে অর্থের সারি নেই');

        // ⓘ ফোনে অর্থ বন্ধ — হিসাব ও অর্থ থাকে, কেবল অর্থের সারি যায়
        // ⓘ কন্ট্রোল প্যানেলের পথেই, ফোনের দাবির মতো ([[ThePhoneShowedEveryModuleTest]]) — মালিকের নিজের কোম্পানিতে বসে
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner)->put(route('system_admin.control-panel.update'), [
            'scope' => ['mobile.modules.finance'],
            'settings' => ['mobile.modules.finance' => '0'],
        ])->assertRedirect();
        app(SettingsService::class)->flush();

        $accounts = collect($this->phoneMenu($token))->firstWhere('code', 'accounts');
        $this->assertNotNull($accounts);
        $this->assertSame([], collect($accounts['groups'])->flatten(1)->where('module', 'finance')->all(), '⛔ ফোনে বন্ধ অর্থের সারি এখনো আসে');
        $this->assertNotEmpty(collect($accounts['groups'])->flatten(1)->where('module', 'accounts')->all());
    }

    public function test_accounts_and_finance_stand_in_the_owners_nine_parts(): void
    {
        $this->assertSame(
            ['start', 'daily', 'receivable', 'payable', 'bank_cash', 'loans_invest', 'fixed_assets', 'budget', 'reports_close'],
            MenuLook::data()['order']['accounts'],
        );

        $entry = $this->entry($this->menu(), 'accounts');
        $rows = MenuLook::arrange('accounts', collect($entry['groups'])->flatten(1)->values()->all());
        $looks = MenuLook::forRows('accounts', $rows);

        $kinds = [];
        foreach ($looks as $look) {
            $this->assertNotNull($look['kind'], '⛔ হিসাব ও অর্থের একটা সারি কোনো ভাগে নেই');
            if ($look['starts']) {
                $kinds[] = $look['kind'];
            }
        }

        $this->assertSame(MenuLook::data()['order']['accounts'], $kinds, '⛔ নয়টা ভাগ মালিকের ক্রমে নয়, বা একটা ভাগ দুইবার');

        foreach (['sales' => ['customers', 'credit', 'promo'], 'purchase' => ['suppliers']] as $host => $guestKinds) {
            $rows = MenuLook::arrange($host, collect($this->entry($this->menu(), $host)['groups'])->flatten(1)->values()->all());
            $tail = array_values(array_unique(array_filter(array_map(fn (array $l) => $l['kind'], array_slice(MenuLook::forRows($host, $rows), -40)),
                fn (?string $k) => in_array($k, $guestKinds, true))));
            $this->assertSame($guestKinds, $tail, "⛔ {$host}: অতিথিদের দল শেষে, এই ক্রমে নয়");
        }
    }

    /**
     * ⛔ মালিক, ২০ নভেম্বর ২০২৬: "সাপ এবং ডাইনামিকের রূপ 100% মানে 100% অরিজিনাল" — ওই দুই রূপে মডিউলগুলো আগের মতো আলাদা,
     *   নাম আগের, অর্থের ড্যাশবোর্ড-সারির নাম আগের, রঙের তালিকা এক করার আগেরটা, ট্যাব নেই। ফোন তবু এক করা।
     */
    public function test_sap_and_dynamic_keep_the_modules_apart_as_before(): void
    {
        foreach (['tiles', 'dynamic'] as $look) {
            $this->owner->forceFill(['ui' => $look])->save();
            $this->actingAs($this->owner->fresh());
            $menu = $this->menu();
            $codes = array_column($menu, 'code');

            foreach (self::FAMILIES as $host => $family) {
                foreach ($family['guests'] as $guest) {
                    $this->assertContains($guest, $codes, "⛔ {$look}: {$guest} নিজের মডিউল নয় — এক করা মেনু এই রূপে ঢুকেছে");
                }
                $this->assertSame([], $this->entry($menu, $host)['members'], "⛔ {$look}: {$host}-এ ড্যাশবোর্ডের ট্যাব");
            }

            $this->assertNotSame('হিসাব ও অর্থ', $this->entry($menu, 'accounts')['label'], "⛔ {$look}: হিসাবের নাম বদলেছে");
            $this->assertSame(__('finance::dashboard.title'), collect($this->entry($menu, 'finance')['groups']['dashboard'])->first()['label'], "⛔ {$look}: অর্থের ড্যাশবোর্ড-সারির নাম বদলেছে");
            $this->assertArrayNotHasKey('order', MenuLook::data(), "⛔ {$look}: এক করা মেনুর রঙের তালিকা পড়ছে");
        }

        $this->assertNotContains('finance', array_column(app(MenuBuilder::class)->forUser($this->owner->fresh(), merged: true), 'code'), '⛔ ফোনের মেনু এক করা নয়');
    }

    public function test_search_finds_a_merged_in_module_by_its_old_name(): void
    {
        $this->owner->forceFill(['ui' => 'rose'])->save();

        $body = (string) $this->actingAs($this->owner->fresh())->getJson(route('search', ['q' => 'গ্রাহক']))->assertOk()->getContent();
        $this->assertStringContainsString('বিক্রয় › গ্রাহক', json_encode(json_decode($body, true), JSON_UNESCAPED_UNICODE),
            '⛔ "গ্রাহক" লিখে মডিউলটা পাওয়া গেল না — এক করার পর নিজের টাইল নেই, খোঁজার ঘরও ভুলে গেল');
    }

    /** @return list<array<string, mixed>> */
    private function menu(): array
    {
        return app(MenuBuilder::class)->forUser($this->owner->fresh());
    }

    /**
     * @param  list<array<string, mixed>>  $menu
     * @return array<string, mixed>
     */
    private function entry(array $menu, string $code): array
    {
        $entry = collect($menu)->firstWhere('code', $code);
        $this->assertNotNull($entry, "⛔ মেনুতে {$code} নেই");

        return $entry;
    }

    /** @return list<array<string, mixed>> */
    private function phoneMenu(string $token): array
    {
        $this->app['auth']->forgetGuards();

        return (array) $this->withToken($token)->getJson('/api/v1/me')->assertOk()->json('menu');
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
