<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Restaurant;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * রান্নাঘরটা একটা ডিপোর মেনুতে বসে ছিল।
 *
 * ── ⓘ মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"রেস্টুরেন্ট ডিফল্ট বন্ধ করে রাখো — POS-এর মতো।"*
 *
 * ⓘ ABOS-এর ব্যবহারকারী একটা **ডিপো** — মাল কেনে আর বেচে, বানায় না।
 * ⚠️ রেস্তোরাঁর রান্নাঘর, টিকিট, রেসিপি কোনোটাই তার কাজে লাগে না, অথচ
 * মেনুতে বসে থাকত।
 *
 * ── ⭐ আর সিদ্ধান্তটা নতুন নয় ────────────────────────────────────────
 * `sales.screen_pos` ঠিক এই কাজটাই আগে থেকেই করে, আর কারণটাও একই লেখা:
 * *"কাউন্টার POS দোকানের জিনিস, পরিবেশকের নয়।"* ⓘ অর্থাৎ নীতিটা ছিল,
 * কেবল রেস্তোরাঁয় পৌঁছায়নি।
 *
 * ── ⚠️ বন্ধ, মুছে ফেলা নয় ───────────────────────────────────────────
 * কোড, রুট, কাগজ — সব অক্ষত। ⓘ সুইচ খুললেই সব ফিরে আসে, আর সেটাও
 * এখানে দাবি করা হয়েছে; নাহলে "বন্ধ করা" আর "ভেঙে ফেলা" এক হয়ে যেত।
 */
final class TheKitchenSatInADepotsMenuTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /**
     * ⭐ কিছু না বললে রেস্তোরাঁ মেনুতে নেই।
     */
    public function test_a_depot_never_sees_the_kitchen(): void
    {
        $this->assertNotContains(
            'restaurant',
            $this->modulesOnScreen(),
            'রেস্তোরাঁ এখনো ডিফল্টে মেনুতে বসে আছে।',
        );
    }

    /**
     * ⛔ আর সুইচ খুললে পুরোটা ফিরে আসে।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * উপরেরটা একা থাকলে **মডিউলটা ভেঙে ফেললেও** সবুজ থাকত — মেনুতে
     * না থাকার একটা সহজ উপায় হলো জিনিসটা আর কাজ না করা।
     *
     * ⓘ মালিক বলেছেন *"বন্ধ করে রাখো"*, *"মুছে ফেলো"* নয়। যেদিন কেউ
     * সত্যিই রান্নাঘর চালাবেন, সেদিন সুইচটাই যথেষ্ট হওয়া চাই।
     */
    public function test_the_switch_brings_it_all_back(): void
    {
        app(SettingsService::class)->set('restaurant.enabled', true);

        $this->assertContains(
            'restaurant',
            $this->modulesOnScreen(),
            'সুইচ খোলার পরেও রেস্তোরাঁ ফিরে আসছে না — বন্ধ নয়, ভাঙা।',
        );
    }

    /**
     * ⓘ আর এটা রেস্তোরাঁর একার নিয়ম নয় — POS-ও একইভাবে বন্ধ।
     *
     * ⚠️ দাবিটা এখানে, কারণ দুইটা একই সিদ্ধান্তের দুই প্রয়োগ: **ডিপোর
     * কাজে লাগে না এমন পর্দা ডিফল্টে থাকবে না**। ⓘ কেউ একটা ফেরালে
     * এই ফাইলটা মনে করিয়ে দেবে অন্যটাও কেন বন্ধ।
     */
    public function test_the_counter_screen_is_off_for_the_same_reason(): void
    {
        $this->assertFalse(
            (bool) app(SettingsService::class)->get('sales.screen_pos'),
            'কাউন্টারের পর্দা ডিফল্টে চালু হয়ে গেছে — ডিপোতে ওটা লাগে না।',
        );
    }

    /**
     * মেনুতে আজ কোন মডিউলগুলো আছে।
     *
     * @return list<string>
     */
    private function modulesOnScreen(): array
    {
        app()->forgetInstance(SettingsService::class);

        /*
         * ⚠️ চাবিটা `codes`, `module` নয় — আর এটা মেপে ধরা পড়েছে।
         *
         * ⛔ প্রথমে `pluck('module')` লেখা ছিল, আর ঐ চাবিটা **নেই**।
         * ⓘ ফলে তালিকাটা সবসময় খালি আসত, আর "রেস্তোরাঁ মেনুতে নেই"
         * দাবিটা সবুজ থাকত — মডিউলটা মেনুতে থাকুক বা না থাকুক।
         *
         * ⓘ একটা টাইল অন্য মডিউলের রুটও ঢেকে রাখতে পারে (`under`), তাই
         * নামগুলো `codes`-এ তালিকা হয়ে বসে, একটা নাম নয়।
         */
        return collect(app(MenuBuilder::class)->forUser($this->user->fresh()))
            ->flatMap(fn (array $tile) => $tile['codes'] ?? [])
            ->unique()
            ->values()
            ->all();
    }
}
