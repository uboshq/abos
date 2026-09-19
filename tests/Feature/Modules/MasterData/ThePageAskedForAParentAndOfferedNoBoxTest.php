<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পর্দা বাবার নাম চাইল, অথচ বাছার ঘরটাই দিল না।
 *
 * ── ⛔ মালিকের অভিযোগ, ১৮ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"আমি রুট তৈরি করলে তার প্যারেন্ট পয়েন্ট লিখতে হবে, পয়েন্ট তৈরি
 * করলে টেরিটরি — এটা ঠিক করো।"*
 *
 * ⚠️ পর্দায় লাল অক্ষরে লেখা ছিল **"উপরের টেরিটরি বাছতে হবে"**, আর ঐ
 * একই পর্দায় টেরিটরি বাছার কোনো ঘর ছিল না। ⛔ ঐ অবস্থা থেকে বেরোনোর
 * কোনো পথও ছিল না।
 *
 * ── দুইটা আলাদা বাগ, একটার পিছনে আরেকটা লুকানো ──────────────────────
 * ⓘ **এক** — জমা ব্যর্থ হলে `back()` ফেরে `…/create`-এ, ঠিকানায়
 * `?level=point` ছাড়া। স্তরটা তখন আবার **দেশ**, আর দেশের কোনো বাবা
 * নেই, তাই ফর্ম বাবার ঘরটাই আঁকেনি।
 *
 * ⓘ **দুই** — সরাসরি `?level=point` খুললেও পাতাটা **৫০০** দিত:
 * প্রতিটা বিকল্পে [[Location::path()]] ডাকা হয়, আর সে `parent` ধরে
 * উপরে হাঁটে — শিকলটা তোলা ছিল না।
 *
 * ⚠️ দ্বিতীয়টা প্রথমটার আড়ালে ছিল: বাবার তালিকা **খালি থাকলে** লুপটা
 * চলত না, তাই দেশ ও বিভাগের ফর্ম দিব্যি খুলত। ⛔ অর্থাৎ ভাঙা ছিল ঠিক
 * সেই পর্দাগুলোই যেখানে সত্যিই বাছার কিছু আছে।
 *
 * ⭐ তাই এই ফাইলের প্রতিটা দাবির আগে **পুরো মইটা ভরা হয়** — নাহলে
 * পাহারাটা খালি তালিকার উপর সবুজ থাকত, আর ঠিক ঐ বাগটাই আবার ফিরত।
 */
final class ThePageAskedForAParentAndOfferedNoBoxTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Location> */
    private array $chain = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        /*
         * ⛔ মইয়ের প্রতিটা ধাপে অন্তত একটা এলাকা — এটাই এই ফাইলের
         * মেরুদণ্ড। ⚠️ একটা ধাপ খালি থাকলে তার নিচের পর্দার বাবার
         * তালিকাও খালি হত, আর তখন ৫০০-টা আর ঘটতই না।
         */
        $parent = null;

        foreach (Location::activeLadder() as $index => $level) {
            $parent = Location::query()->create([
                'company_id' => CompanyContext::id(),
                'parent_id' => $parent?->id,
                'code' => 'LAD'.$index,
                'name_en' => ucfirst($level).' One',
                'level' => $level,
                'is_active' => true,
            ]);

            $this->chain[$level] = $parent;
        }
    }

    /**
     * ⭐ এই ফাইলের ভেতরে আলগা সম্পর্ক ব্যতিক্রম হয়ে ওঠে।
     *
     * ── ⛔ ছাড়া উপায় ছিল না ─────────────────────────────────────────
     * ৫০০-টার আসল কারণ `LazyLoadingViolationException`, আর ওটা
     * [[AppServiceProvider]]-এ **কেবল `local`**-এ চালু:
     *
     *     Model::preventLazyLoading($this->app->environment('local'));
     *
     * ⓘ সিদ্ধান্তটা ইচ্ছাকৃত, আর কারণটা ওখানেই লেখা — পুরো স্যুট একবার
     * শান্ত মেশিনে সবুজ না দেখে `'testing'` যোগ করলে বহু পরীক্ষা
     * একসাথে লাল হত, আর আসল সমস্যাটা হারিয়ে যেত।
     *
     * ⚠️ কিন্তু ওটা বন্ধ রেখে এই পাহারাটা **অন্ধ** ছিল: eager-load
     * সরিয়েও তিনটা দাবিই সবুজ থাকত। ⛔ যে পাহারা নিজের বাগটাই ধরে না,
     * সে পাহারা নয় — সে কেবল একটা সবুজ বাতি।
     *
     * ⭐ তাই সুইচটা এখানে, এই ফাইলের সীমানায়। ⓘ বিশ্বব্যাপী সিদ্ধান্তটা
     * অক্ষত থাকে, আর tearDown-এ আগের অবস্থাই ফিরে যায় — পাশের কোনো
     * পরীক্ষা এর আঁচ পায় না।
     */
    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    /**
     * ⭐ প্রতিটা স্তরের ফর্ম খোলে, আর বাবার ঘরটা সেখানেই থাকে।
     *
     * ⓘ সবচেয়ে উপরের স্তরটা বাদ — দেশের কোনো বাবা নেই, আর ওখানে
     * ঘরটা **না থাকাটাই** সঠিক।
     */
    public function test_every_level_below_the_top_offers_a_parent_box(): void
    {
        Model::preventLazyLoading(true);

        $ladder = Location::activeLadder();

        $this->assertGreaterThan(2, count($ladder),
            'মইটাই ছোট হয়ে গেছে — পাহারাটা তখন কিছুই মাপে না।');

        foreach ($ladder as $level) {
            $parentLevel = Location::parentLevelOf($level);

            $page = $this->get(route('master_data.location.create', ['level' => $level]));

            /*
             * ⛔ এই একটা লাইনই ৫০০-টা ধরে। ⓘ ব্যতিক্রমটা আসত
             * `path()` থেকে, তাই দাবিটা "পাতাটা খোলে" — "নিয়মটা ঠিক"
             * নয়।
             */
            $page->assertOk();

            if ($parentLevel === null) {
                $page->assertDontSee('name="parent_id"', escape: false);

                continue;
            }

            $page->assertSee('name="parent_id"', escape: false);

            /*
             * ⭐ আর ঘরটা খালি নয়: ঠিক উপরের স্তরের এলাকাটা বিকল্প
             * হিসেবে থাকতে হবে। ⚠️ নাহলে ঘরটা থেকেও কাজে আসত না।
             */
            $page->assertSee('value="'.$this->chain[$parentLevel]->id.'"', escape: false);
        }
    }

    /**
     * ⭐ ভুলের বার্তা যে স্তরের কথা বলে, ফেরত পাতাটাও সেই স্তরেই থাকে।
     *
     * ── ⛔ এটাই মালিকের পর্দার হুবহু ঘটনা ────────────────────────────
     * ⚠️ `back()`-এর ঠিকানায় `?level=` নেই — তাই Referer-টাও ইচ্ছে
     * করে সাদা `…/create`। ⓘ ঠিক এভাবেই স্তরটা দেশে ফিরে যেত।
     */
    public function test_a_failed_save_comes_back_to_the_same_level(): void
    {
        $this->from(route('master_data.location.create'))
            ->post(route('master_data.location.store'), [
                'level' => Location::POINT,
                'name_en' => 'Mymensing city',
                'parent_id' => '',
            ])
            ->assertSessionHasErrors('parent_id');

        $page = $this->get(route('master_data.location.create'));

        $page->assertOk();

        /*
         * ⛔ তিনটা দাবি একসাথে, আর তিনটাই ঐ পর্দায় ভাঙা ছিল:
         * স্তরটা পয়েন্ট আছে, বাবার ঘরটা আছে, আর লেখা নামটাও আছে।
         */
        $page->assertSee('value="'.Location::POINT.'" selected', escape: false);
        $page->assertSee('name="parent_id"', escape: false);
        $page->assertSee('Mymensing city', escape: false);
    }

    /**
     * ⭐ মালিকের নিজের বাক্যটাই দাবিতে: রুটের বাবা পয়েন্ট,
     * পয়েন্টের বাবা টেরিটরি — আর দুইটাই সত্যিই সংরক্ষণ হয়।
     *
     * ⚠️ কেবল পর্দা মাপলে যথেষ্ট হত না: ঘরটা থেকেও জমা না হতে পারে।
     * ⓘ তাই সারিটা ডেটাবেস থেকে পড়ে বাবার **স্তরটা** মেলানো হয়।
     */
    public function test_a_point_lands_under_a_territory_and_a_route_under_a_point(): void
    {
        foreach ([Location::POINT, Location::ROUTE] as $level) {
            $parentLevel = Location::parentLevelOf($level);

            $this->post(route('master_data.location.store'), [
                'level' => $level,
                'name_en' => 'Owner '.$level,
                'parent_id' => $this->chain[$parentLevel]->id,
            ])->assertSessionHasNoErrors();

            $saved = Location::query()
                ->where('name_en', 'Owner '.$level)
                ->firstOrFail();

            $this->assertSame($this->chain[$parentLevel]->id, $saved->parent_id,
                $level.'-এর বাবা ভুল জায়গায় বসেছে।');
        }
    }
}
