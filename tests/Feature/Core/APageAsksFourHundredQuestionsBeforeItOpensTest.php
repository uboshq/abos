<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ একটা পাতা খোলার আগেই ৪৪৪টা প্রশ্ন — ২০ সেপ্টেম্বর ২০২৬, অডিটে ধরা।
 *
 * ── কোথা থেকে আসত ─────────────────────────────────────────────────────
 * · `Gate::before` প্রতিটা অনুমতির জন্য একটা করে কোয়েরি করত, আর মেনু
 *   আঁকতে ১৯২টা অনুমতি দেখা হয়।
 * · [[MenuSwitches::itemIsOn()]] প্রতি মেনু সারিতে **তিনটা** সেটিং দেখে
 *   (মডিউল · দল · সারি) — মোট ২৪৭টা।
 *
 * ⚠️ আর তার প্রায় সবগুলোই **কিছু না পেয়ে** ফিরত: ব্যতিক্রম বলে কিছু
 * থাকেই না, আর মেনুর সুইচগুলোও টেবিলে নেই বলে ডিফল্টে গিয়ে পড়ে।
 * অর্থাৎ শত শত বার ডাটাবেসে গিয়ে কিছু না জেনে ফেরা।
 *
 * ── ⭐ কেন সংখ্যা গুনে সীমা বাঁধা হয় ──────────────────────────────────
 * ⓘ "দ্রুত কি না" মাপা যায় না — মেশিনভেদে সময় বদলায়। কিন্তু **কয়টা
 * কোয়েরি** হলো সেটা নির্দিষ্ট, আর সেটাই আসল কারণ। ⛔ সীমাটা না বাঁধলে
 * পরের জন একটা `->first()` লুপে ঢুকিয়ে দিলে কেউ টের পেত না — ঠিক যেভাবে
 * এটা ঢুকেছিল।
 */
final class APageAsksFourHundredQuestionsBeforeItOpensTest extends TestCase
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

    /**
     * ⭐ দুইশো অনুমতি দেখলেও ব্যতিক্রমের কোয়েরি একটাই।
     */
    public function test_two_hundred_permission_checks_cost_one_query(): void
    {
        $this->actingAs($this->owner);

        // ⓘ গেটটা একবার গরম করে নেওয়া, যাতে গোনায় বুট-সময়ের কোয়েরি না ঢোকে
        $this->owner->can('inventory.stock.view');

        $asked = $this->countQueries(function (): void {
            foreach (range(1, 200) as $i) {
                $this->owner->can('some.permission.'.$i);
            }
        });

        $this->assertLessThanOrEqual(
            1,
            $asked,
            "দুইশো অনুমতি দেখতে {$asked}টা কোয়েরি হয়েছে — ব্যতিক্রমগুলো আবার এক এক করে খোঁজা হচ্ছে।",
        );
    }

    /**
     * ⭐ একশো সেটিং পড়লেও কোয়েরি একটাই।
     *
     * ⓘ সেটিং অল্প কয়েকশো সারি, তাই সবগুলো একবারে তুলে নেওয়াই সস্তা।
     */
    public function test_a_hundred_settings_cost_one_query(): void
    {
        $settings = app(SettingsService::class);
        $settings->flush();

        $keys = array_slice(array_keys($settings->definitions()), 0, 100);

        $this->assertGreaterThan(20, count($keys), 'এত কম সেটিং যে দাবিটা কিছুই মাপছে না।');

        $asked = $this->countQueries(function () use ($settings, $keys): void {
            foreach ($keys as $key) {
                $settings->get($key);
            }
        });

        $this->assertLessThanOrEqual(
            1,
            $asked,
            count($keys)."টা সেটিং পড়তে {$asked}টা কোয়েরি হয়েছে — এক এক করে খোঁজা হচ্ছে।",
        );
    }

    /**
     * ⛔ যে চাবি টেবিলে নেই, সেটাও দ্বিতীয়বার খোঁজা হয় না।
     *
     * ⚠️ আগের কোডে ডিফল্টে পড়া চাবিগুলোর একটা বড় অংশ **প্রতিবারই**
     * কোয়েরি করত, আর মেনুর সুইচগুলোর প্রায় সবই ঐ দলে পড়ে।
     */
    public function test_a_missing_setting_is_not_looked_for_twice(): void
    {
        $settings = app(SettingsService::class);
        $settings->flush();

        $settings->get('nobody.declared.this', 'fallback');

        $asked = $this->countQueries(function () use ($settings): void {
            foreach (range(1, 20) as $ignored) {
                $settings->get('nobody.declared.this', 'fallback');
            }
        });

        $this->assertSame(0, $asked, "না-থাকা চাবিটা আবার {$asked}বার খোঁজা হয়েছে।");
    }

    /**
     * ⭐ আর গোটা পাতাটা — মেনু সহ — একশোর নিচে।
     *
     * ⓘ সংখ্যাটা উদার, ইচ্ছাকৃতভাবে: এটা কর্মক্ষমতার সীমা নয়, **অসীমে
     * যাওয়ার পাহারা**। ⛔ আগে এই পাতাটাই চারশোর বেশি চাইত।
     */
    public function test_a_whole_page_stays_under_a_hundred_queries(): void
    {
        $this->actingAs($this->owner);

        // প্রথম ডাকে ভিউ কম্পাইল ও বুটের কোয়েরি মিশে যায়, তাই একবার গরম
        $this->get(route('inventory.stock.index'))->assertOk();

        $asked = $this->countQueries(function (): void {
            $this->get(route('inventory.stock.index'))->assertOk();
        });

        $this->assertLessThan(
            100,
            $asked,
            "মজুদের পাতা খুলতে {$asked}টা কোয়েরি লেগেছে — কোথাও আবার সারি ধরে খোঁজা শুরু হয়েছে।",
        );
    }

    /**
     * ⛔ একবারে তুলে রাখা মানেই "পরে লেখা সারি আর দেখা যায় না" — নয়।
     *
     * ── ⚠️ এই দাবিটা একটা আসল বাগ থেকে এসেছে, ২০ সেপ্টেম্বর ২০২৬ ──────
     * প্রাইমিং বসানোর পর ক্রয়ের ফর্মে প্যাক আসা বন্ধ হয়ে গেল। কারণ:
     * ডেমো সিডার সেটিং লেখার **আগেই** কেউ একটা সেটিং পড়ে ফেলত, স্মৃতিটা
     * তখনকার (ফাঁকা) অবস্থায় জমে যেত, আর পরে লেখা সারিগুলো কেউ আর
     * দেখত না। ⓘ পাতা ২০০ দিত, কোনো ত্রুটি হত না — কেবল প্যাকের ঘরটা
     * নীরবে খালি।
     *
     * ⭐ সারাইটা সারিটার নিজের কাছে ([[Setting::booted()]]): যে-ই লিখুক,
     * পড়ার স্মৃতি তখনই ফেলে দেওয়া হয়।
     */
    public function test_a_setting_written_after_the_first_read_is_still_seen(): void
    {
        $settings = app(SettingsService::class);

        // প্রথম পড়া — এখানেই সব সারি একবারে উঠে আসে
        $before = $settings->get('inventory.pack_entry_enabled');

        $settings->set('inventory.pack_entry_enabled', ! $before);

        $this->assertSame(
            ! $before,
            $settings->get('inventory.pack_entry_enabled'),
            'লেখার পরেও পুরনো মানটাই ফিরছে — স্মৃতিটা বাসি।',
        );

        /*
         * ⚠️ আর সেবার বাইরে থেকে লেখা হলেও — সিডার, কমান্ড আর ইমপোর্ট
         * সরাসরি মডেল ব্যবহার করে, আর আসল বাগটা ঠিক সেভাবেই এসেছিল।
         */
        Setting::query()
            ->where('company_id', CompanyContext::id())
            ->where('key', 'inventory.pack_entry_enabled')
            ->get()
            ->each(fn (Setting $row) => $row->forceFill(['value' => $before ? '1' : '0'])->save());

        $this->assertSame(
            $before,
            $settings->get('inventory.pack_entry_enabled'),
            'মডেল দিয়ে লেখা হয়েছে, অথচ পড়ার স্মৃতি আগেরটাই ধরে আছে।',
        );
    }

    /** একটা কাজে কয়টা কোয়েরি গেল। */
    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }
}
