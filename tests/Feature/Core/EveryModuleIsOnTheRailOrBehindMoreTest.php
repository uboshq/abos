<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ বাঁ মডিউল-রেল — প্রতিটা মডিউল হয় রেলে, নয় "⋯ আরও"-তে; পিন আর নিজের ক্রম নিজের সারিতে (মালিক, ২৪ অক্টোবর ২০২৬:
 * "বাঁ মডিউল বারে ১০০% জুমে সব মডিউলের আইকন আসে না")।
 *
 * রেল নিজে মাপে ([[rail-fit.js]], vitest-এ দাবি; আসল ব্রাউজারে ১৩৬৬×৭৬৮-এ ১১ + ৮)। এখানে সার্ভারের দিক: পাতায় রেল ঠিক মেনুর
 * মডিউলগুলো আঁকে (চাবিহীন একটাও নয়) আর "আরও"-র ঘর থাকে; শুরুর ক্রম খোলা পাতার অনুপাতে (মালিকের আগের মতোই); নিজের সাজ রাখা
 * যায়, তাতে অন্যের মডিউল ঢোকে না, নিরীক্ষায় যায় না, আর "আগের মতো করুন" মুছে দেয়; উপরের খোঁজায় মডিউলের নাম।
 */
class EveryModuleIsOnTheRailOrBehindMoreTest extends TestCase
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
        $this->actingAs($this->owner);
    }

    /** @return list<string> রেলের মডিউল কোড, পাতার ক্রমে */
    private function rail(string $html): array
    {
        preg_match_all('/data-rail-module="([a-z_]+)"/', $html, $m);

        return $m[1];
    }

    /** @return list<string> */
    private function menuCodes(User $user): array
    {
        return array_column(app(MenuBuilder::class)->forUser($user), 'code');
    }

    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->owner->current_company_id]);
        $user->companies()->attach($this->owner->current_company_id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->owner->current_company_id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => \Spatie\Permission\Models\Permission::findOrCreate($k, 'web'), $keys)));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_the_rail_draws_exactly_the_menus_modules_with_a_place_for_more(): void
    {
        $page = (string) $this->get(route('customer.index'))->assertOk()->getContent();
        $rail = $this->rail($page);

        $this->assertSame($this->menuCodes($this->owner), $rail, '⛔ মালিকের রেল মেনুর মডিউলগুলোই নয়, বা আগের ক্রমে নয় — মালিক সব খোলেন, ক্রম বদলানোর কথা নয়');
        $this->assertGreaterThan(12, count($rail), 'প্রস্তুতিটাই ভুল — এত কম মডিউলে রেল ভরে না');
        $this->assertStringContainsString('data-rail-more-button', $page, '⛔ রেলে "⋯ আরও" নেই — যা ধরে না তা কাটা যেত');
        $this->assertStringContainsString('aria-haspopup="menu"', $page, '⛔ "আরও" বোতাম পর্দা-পাঠকে মেনু-বোতাম নয়');
        $this->assertMatchesRegularExpression('/data-rail-sep=/', $page, '⛔ আগের দল-ক্রমে দলের রেখা থাকার কথা');
    }

    public function test_a_module_without_a_key_appears_nowhere_and_a_limited_persons_rail_follows_what_they_can_open(): void
    {
        $sr = $this->person(['customer.view', 'sales.order.view', 'sales.order.create']);
        $page = (string) $this->actingAs($sr)->get(route('customer.index'))->assertOk()->getContent();
        $rail = $this->rail($page);
        $mine = $this->menuCodes($sr);

        $this->assertEqualsCanonicalizing($mine, $rail, '⛔ রেলে চাবিহীন মডিউল এল, বা নিজের একটা হারাল');
        $this->assertNotSame($mine, $rail, '⛔ বিক্রয়কর্মীর রেল মেনুর আগের ক্রমেই — যে মডিউলের পাতা তিনি খুলতে পারেন তা উপরে আসার কথা');
        $this->assertNotContains('accounts', $rail, '⛔ হিসাবের চাবি নেই, তবু রেলে হিসাব');

        $reach = array_column(app(MenuBuilder::class)->forUser($sr), 'reach', 'code');
        $ordered = array_map(fn (string $c) => $reach[$c], $rail);
        $sorted = $ordered;
        rsort($sorted);
        $this->assertSame($sorted, $ordered, '⛔ নিজের সাজ নেই, তবু রেল খোলা পাতার অনুপাতে সাজানো নয় — বিক্রয়কর্মীর কাজের মডিউল উপরে আসার কথা');
    }

    public function test_a_person_keeps_their_own_pins_and_order_unaudited_and_reset_brings_the_default_back(): void
    {
        $before = $this->rail((string) $this->get(route('customer.index'))->getContent());
        $last = end($before);
        $trails = AuditTrail::query()->where('auditable_type', User::class)->count();

        $sr = $this->person(['customer.view']);
        $saved = $this->actingAs($this->owner)->postJson(route('rail.layout'), ['order' => [$last, 'not_a_module'], 'pinned' => [$last, 'not_a_module']])
            ->assertOk()->json('nav_layout');
        $this->assertSame(['pinned' => [$last], 'order' => [$last]], $saved, '⛔ রাখা সাজে মেনুর বাইরের কোড ঢুকল, বা নিজেরটা হারাল');

        $page = (string) $this->get(route('customer.index'))->getContent();
        $rail = $this->rail($page);
        $this->assertSame($last, $rail[0] ?? null, '⛔ নিজের ক্রম রাখার পরেও রেলের প্রথম মডিউল সেটা নয়');
        $this->assertMatchesRegularExpression('/data-rail-module="'.$last.'"[^>]*data-pinned="1"/s', $page, '⛔ পিন করা মডিউল পিন চিহ্নিত নয় — জায়গা কম হলে "আরও"-তে সরত');
        $this->assertDoesNotMatchRegularExpression('/data-rail-sep=/', $page, '⛔ নিজের ক্রমে দল মিশে যায়, তবু দলের রেখা আঁকা হলো');
        $this->assertSame($trails, AuditTrail::query()->where('auditable_type', User::class)->count(), '⛔ রেলের নিজের সাজ নিরীক্ষায় গেল — এটা নিজের পছন্দ, ব্যবসার তথ্য নয়');

        // ⓘ অন্যের সারিতে লেখা হয় না — বিক্রয়কর্মী নিজের পাঠালে মালিকেরটা অটুট
        $theirs = $this->actingAs($sr)->postJson(route('rail.layout'), ['order' => ['accounts'], 'pinned' => ['accounts']])->assertOk()->json('nav_layout');
        $this->assertSame(['pinned' => [], 'order' => []], $theirs, '⛔ চাবিহীন মডিউল (হিসাব) বিক্রয়কর্মীর রাখা সাজে ঢুকল');
        $this->assertSame([$last], $this->owner->fresh()->nav_layout['order'] ?? null, '⛔ একজনের সাজ আরেকজনের সারিতে পড়ল');

        $this->actingAs($this->owner)->postJson(route('rail.layout'), ['reset' => true])->assertOk();
        $this->assertNull($this->owner->fresh()->nav_layout, '⛔ "আগের মতো করুন" নিজের সাজ মোছেনি');
        $this->assertSame($before, $this->rail((string) $this->get(route('customer.index'))->getContent()), '⛔ "আগের মতো করুন"-এর পরে রেল শুরুর ক্রমে ফেরেনি');
    }

    public function test_the_top_search_finds_a_module_by_its_name_and_opens_its_first_page(): void
    {
        $menu = app(MenuBuilder::class)->forUser($this->owner);
        $module = collect($menu)->firstWhere('code', 'fixed_assets') ?? $menu[array_key_last($menu)];
        $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null);

        $hits = $this->getJson(route('search', ['q' => mb_substr((string) $module['label'], 0, 4)]))->assertOk()->json('hits');
        $this->assertContains(['type' => __('core.search.module'), 'no' => '', 'label' => (string) $module['label'], 'url' => (string) $first['url']], $hits,
            '⛔ উপরের খোঁজায় মডিউলের নাম লিখলে মডিউলটা আসে না — "আরও"-তে থাকা মডিউলে পৌঁছানোর সোজা পথ নেই');

        /*
         * ⭐ শব্দের শুরুতে মিল আগে, মাঝখানের মিল পরে (fe, ২৫ অক্টোবর ২০২৬): "স্থায়ী" লিখলে প্রথম ফল "মডিউল · স্থায়ী সম্পদ" — "অস্থায়ী"-ওয়ালা
         * পাতা (বাকি নিয়ন্ত্রণ · অস্থায়ী সীমা) তার পরে। ⓘ খোঁজার শব্দ মডিউলের নামের প্রথম শব্দ, পরীক্ষার ভাষা যা-ই হোক।
         */
        $word = mb_strtolower((string) preg_split('/\s+/u', trim((string) $module['label']))[0]);
        $midWord = collect($menu)->flatMap(fn (array $m) => collect($m['groups'] ?? [])->flatten(1))
            ->filter(fn (array $i) => ($i['url'] ?? null) !== null)
            ->contains(fn (array $i) => preg_match('/\p{L}'.preg_quote($word, '/').'/u', mb_strtolower((string) ($i['label'] ?? ''))) === 1);
        $this->assertTrue($midWord, "প্রস্তুতিটাই ভুল — মেনুতে \"{$word}\" শব্দের মাঝখানে মেলে এমন পাতা নেই, ক্রমের দাবি অর্থহীন");
        $ranked = $this->getJson(route('search', ['q' => $word]))->assertOk()->json('hits');
        $this->assertSame(['type' => __('core.search.module'), 'label' => (string) $module['label']], ['type' => $ranked[0]['type'] ?? null, 'label' => $ranked[0]['label'] ?? null],
            "⛔ \"{$word}\" লিখলে প্রথম ফল মডিউলটা নয় — শব্দের মাঝখানের মিল (\"অস্থায়ী\") আগে বসেছে");

        $sr = $this->person(['customer.view']);
        $this->assertNotContains('fixed_assets', $this->menuCodes($sr), 'প্রস্তুতিটাই ভুল — বিক্রয়কর্মীর স্থায়ী সম্পদের চাবি আছে');
        $theirs = $this->actingAs($sr)->getJson(route('search', ['q' => mb_substr((string) $module['label'], 0, 4)]))->assertOk()->json('hits');
        $this->assertNotContains((string) $first['url'], array_column($theirs, 'url'), '⛔ চাবিহীন মডিউল খোঁজায় এল');
    }
}
