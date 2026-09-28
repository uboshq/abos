<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লট ধরা বন্ধ ছিল, যতক্ষণ না কেউ সুইচটা খুঁজে পান।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"সব বাই ডিফল্ট।"* ⓘ অর্থাৎ `inventory.batch_enabled` প্রতিটা
 * কোম্পানিতে চালু থাকবে, কেউ কিছু না বসালেও।
 *
 * ── ⛔ কেন এটা দরকার হলো ──────────────────────────────────────────────
 * সুইচটার ডিফল্ট ছিল `false`। ⚠️ লাইভে কোনো কোম্পানির জন্য সারিটাই নেই,
 * তাই তিনটা কোম্পানিতেই ডিফল্টটা খাটত — আর লটের দুইটা রিপোর্ট
 * (মেয়াদ, লট-ভিত্তিক মজুদ) মেনুতে **ছিলই না**, রুটও ৪০৪ দিত।
 *
 * ⓘ কোডে ওগুলো অনেক আগেই লেখা, আর আজ লট-ভিত্তিক রিপোর্টে *"কত দিন
 * ধরে"* কলামও যোগ হয়েছে (f61266ce)। ⛔ কিন্তু সুইচ বন্ধ থাকায় কাজটা
 * মালিকের চোখে পড়ার কোনো পথই ছিল না — এই প্রকল্পের সবচেয়ে চেনা ফাঁদ:
 * তিনটা অংশই আছে, জোড়াটা নেই, আর কিছুই ভাঙে না।
 *
 * ── ⓘ সুইচটা কী নিয়ন্ত্রণ করে, আর কী করে না ──────────────────────────
 * ⭐ করে: দুইটা মেনু সারি, আর তাদের রুট (বন্ধ থাকলে ৪০৪)।
 * ⛔ করে না: নথিতে লট ধরা। ⓘ ওটা প্রতিটা পণ্যের নিজের `track_batch`
 * ঘরে, আর মেয়াদের বাধা বা MRP সিলিং সুইচ বন্ধ থাকলেও খাটে —
 * নাহলে সুইচ নামিয়ে মেয়াদোত্তীর্ণ মাল বেচা যেত।
 */
final class LotsWereOffUntilSomebodyFoundTheSwitchTest extends TestCase
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
        $this->actingAs($this->user);

        /*
         * ⛔ কোনো সারি বসানো **হয় না**, আর সেটাই দাবির কেন্দ্র: প্রশ্নটা
         * *"সুইচ চালু করলে কাজ করে কি"* নয় — সেটা আগেও করত। প্রশ্নটা
         * *"কেউ কিছু না বসালে কী হয়"*।
         */
        $this->assertSame(
            0,
            Setting::query()->where('key', 'inventory.batch_enabled')->count(),
            'পরীক্ষাটা শুরুই হচ্ছে একটা বসানো সারি নিয়ে — তাহলে ডিফল্ট মাপা হচ্ছে না।',
        );
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ আসল দাবি ───────────────────────────────────────────────────

    public function test_a_company_that_set_nothing_still_has_lots_on(): void
    {
        $this->assertTrue(
            app(SettingsService::class)->enabled('inventory.batch_enabled'),
            'কেউ কিছু বসায়নি, তবু লট ধরা বন্ধ — মালিকের "সব বাই ডিফল্ট" মানা হয়নি।',
        );
    }

    public function test_the_two_lot_rows_are_in_the_menu_with_no_setting(): void
    {
        $labels = $this->menuLabels();

        foreach (['inventory::menu.expiring', 'inventory::menu.stock_by_batch'] as $key) {
            $this->assertContains(__($key), $labels,
                "মেনুতে `{$key}` সারিটা নেই — কোড থাকলেও কেউ পাতাটা খুঁজে পাবেন না।");
        }
    }

    public function test_the_two_lot_pages_open_with_no_setting(): void
    {
        /*
         * ⚠️ মেনুতে থাকা যথেষ্ট নয়। ⓘ সুইচটা রুটও আটকায়, তাই সারিটা
         * দেখা গিয়ে পাতাটা ৪০৪ দিলে সেটা আরও খারাপ — ব্যবহারকারী
         * ক্লিক করে ভাঙা দরজা পান।
         */
        foreach (['expiring', 'stock-by-batch'] as $slug) {
            $this->get(route('inventory.report.show', ['slug' => $slug]))
                ->assertOk();
        }
    }

    // ── ⓘ সুইচটা তবু সুইচই ───────────────────────────────────────────

    public function test_a_company_that_says_no_still_gets_no(): void
    {
        /*
         * ⛔ পাল্টা-দাবি, আর এটা ছাড়া সারাইটা "সুইচটা তুলে দাও" হয়ে
         * যেতে পারত। ⓘ যে ব্যবসায় লট ধরা হয় না, তার কাছে ঐ দুইটা পাতা
         * খালি — আর মালিকের নির্দেশ ছিল ডিফল্ট বদলানো, সুইচ মোছা নয়।
         *
         * ⭐ একই কোম্পানি, একই ব্যবহারকারী — কেবল সেটিংটা আলাদা।
         * ⚠️ দুইটা আলাদা কোম্পানি নিলে ফারাকটা সদস্যপদ বা অনুমতির
         * কারণেও হতে পারত, আর দাবিটা কিছুই প্রমাণ করত না।
         */
        app(SettingsService::class)->set('inventory.batch_enabled', false);

        $this->assertNotContains(__('inventory::menu.stock_by_batch'), $this->menuLabels());

        $this->get(route('inventory.report.show', ['slug' => 'stock-by-batch']))
            ->assertNotFound();
    }

    public function test_switching_it_off_does_not_take_the_other_stock_reports_with_it(): void
    {
        app(SettingsService::class)->set('inventory.batch_enabled', false);

        $this->get(route('inventory.report.show', ['slug' => 'stock-summary']))->assertOk();
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * মেনুর প্রতিটা সারির লেখা, যত গভীরেই থাকুক।
     *
     * ⓘ গঠনটা বাসা বাঁধা, তাই পুরোটা সমতল করা হয় — কেবল উপরের স্তর
     * দেখলে রিপোর্টের সারিগুলো কোনোদিন চোখে পড়ত না, আর দাবিটা
     * সবসময় সবুজ থাকত।
     *
     * @return list<string>
     */
    private function menuLabels(): array
    {
        $labels = [];

        $walk = function (array $rows) use (&$walk, &$labels): void {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    if (isset($row['label']) && is_string($row['label'])) {
                        $labels[] = $row['label'];
                    }

                    $walk($row);
                }
            }
        };

        $walk(app(MenuBuilder::class)->forUser($this->user->fresh()));

        return $labels;
    }
}
