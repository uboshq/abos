<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ প্রতিটা স্তরের আলাদা তালিকা, আলাদা বোতাম — আর খালি উপরের স্তরে সৎ কথা।
 *
 * ── মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Locations & routes e point creat hoyna… 1st e hobe Country Create,
 * Divi Create, ei vabe… ei sob alada alada create hobe alada list hobe,
 * Tree hobe"*।
 *
 * ── ⚠️ দুইটা আলাদা অভিযোগ ছিল এক বাক্যে ─────────────────────────────
 * **এক** — গাছটা ছিল, কিন্তু সব স্তর এক জায়গায় মেশানো আর তৈরির বোতাম
 * একটাই। "কোথা থেকে শুরু করব" প্রশ্নের উত্তর পর্দায় ছিল না।
 *
 * **দুই** — পয়েন্টের বাবা টেরিটরি। একটাও টেরিটরি না থাকলে ফর্মে একটা
 * **খালি অথচ required** ড্রপডাউন বসত। ⛔ ব্রাউজার জমা আটকাত একটা ছোট
 * ভাসমান লেখা দিয়ে, আর মানুষ বুঝতেন না কেন — ড্রপডাউন খুললে তো কিছুই
 * নেই। ⓘ মালিকের "point creat hoyna" ঠিক এটাই হতে পারে।
 *
 * ⚠️ দুইটাই আসল Chrome-এ চালিয়ে দেখা; এই ফাইল ঐ দেখাটাকে স্থায়ী করে।
 */
final class EachLevelHasItsOwnListAndItsOwnButtonTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Location> */
    private array $chain = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        /* ⓘ প্রতিটা চালু স্তরে একটা, আলাদা করে চেনা যায় এমন নামে —
           "Stepname-L3", যাতে তালিকায় কোনটা এল তা নাম দেখেই বলা যায়। */
        $parent = null;

        foreach (Location::activeLadder() as $index => $level) {
            $parent = Location::query()->create([
                'company_id' => CompanyContext::id(),
                'parent_id' => $parent?->id,
                'code' => 'STEP'.$index,
                'name_en' => 'Stepname-'.$level,
                'level' => $level,
                'is_active' => true,
            ]);

            $this->chain[$level] = $parent;
        }
    }

    /**
     * ⛔ একটা স্তরের পাতায় কেবল ঐ স্তরের সারি।
     *
     * ⚠️ পয়েন্টের পাতায় টেরিটরির **নাম** থাকবেই — প্রতিটা পয়েন্টের পুরো
     * পথে ওটা লেখা ("… › Stepname-territory › Stepname-point")। ⓘ তাই মাপা
     * হয় **নিচের** স্তরটা দিয়ে: রুট কখনো পয়েন্টের পথে আসে না, তাই রুটের
     * নাম পয়েন্টের পাতায় দেখা গেলে মানে ছাঁকনিটাই কাজ করছে না।
     */
    public function test_a_level_page_lists_only_that_level(): void
    {
        $page = $this->get(route('master_data.location.level', ['level' => Location::POINT]))->assertOk();

        $page->assertSee('Stepname-point');
        $page->assertDontSee('Stepname-route');
    }

    /**
     * ⭐ প্রতিটা চালু স্তরের একটা ট্যাব আছে, আর গাছেরও।
     */
    public function test_every_active_level_has_a_tab(): void
    {
        $page = $this->get(route('master_data.location.index'))->assertOk();

        $page->assertSee(__('master_data::message.tree_tab'));

        foreach (Location::activeLadder() as $level) {
            $page->assertSee(route('master_data.location.level', ['level' => $level]), escape: false);
        }
    }

    /**
     * ⭐ প্রতিটা স্তরের পাতায় বোতামটা ঐ স্তরেরই — "নতুন পয়েন্ট", কেবল "নতুন" নয়।
     */
    public function test_each_level_page_offers_a_button_for_its_own_level(): void
    {
        foreach (Location::activeLadder() as $level) {
            $page = $this->get(route('master_data.location.level', ['level' => $level]))->assertOk();

            $page->assertSee(route('master_data.location.create', ['level' => $level]), escape: false);
            $page->assertSee(__('master_data::action.new_level', ['level' => __('master_data::level.'.$level)]));
        }
    }

    /**
     * ⛔ উপরের স্তর খালি — তালিকা আর ফর্ম দুইটাই বলে আগে কী বানাতে হবে।
     *
     * ⚠️ ফর্মে খালি বাবার ড্রপডাউন **থাকবে না** — ওটাই ছিল অন্ধ গলি।
     */
    public function test_an_empty_level_above_says_what_to_make_first(): void
    {
        $parentLevel = Location::parentLevelOf(Location::POINT);
        $this->assertNotNull($parentLevel, 'পয়েন্টের উপরে কোনো স্তরই নেই — পরীক্ষাটা অর্থহীন।');

        /* ⓘ উপরের স্তরের সবাইকে নিষ্ক্রিয় — ফর্মের তালিকা কেবল সক্রিয়দের নেয়। */
        Location::query()->atLevel($parentLevel)->update(['is_active' => false]);

        $list = $this->get(route('master_data.location.level', ['level' => Location::POINT]))->assertOk();

        $list->assertSee(__('master_data::message.need_parent_first', [
            'parent' => __('master_data::level.'.$parentLevel),
            'level' => __('master_data::level.'.Location::POINT),
        ]));

        /* পরের ধাপের বোতামটা আছে, আর অকেজো "নতুন পয়েন্ট" নেই। */
        $list->assertSee(route('master_data.location.create', ['level' => $parentLevel]), escape: false);
        $list->assertDontSee(route('master_data.location.create', ['level' => Location::POINT]), escape: false);

        $form = $this->get(route('master_data.location.create', ['level' => Location::POINT]))->assertOk();

        $form->assertDontSee('name="parent_id"', escape: false);
        $form->assertSee(route('master_data.location.create', ['level' => $parentLevel]), escape: false);
    }

    /**
     * ⛔ মই-এর বাইরের স্তর — সোজা ৪০৪, খালি একটা পাতা নয়।
     */
    public function test_a_level_outside_the_ladder_is_not_found(): void
    {
        $this->get('/master-data/locations/level/zilla')->assertNotFound();
    }

    /**
     * ⚠️ স্তরটা পথে, `?level=`-এ নয় — কাঁচা ইংরেজি চিপ যেন না ফেরে।
     *
     * ⓘ টুলবার ঠিকানার প্রতিটা অচেনা ঘরকে ছাঁকনির চিপ বানায়। স্তরটা
     * `?level=point` হলে বাংলা পর্দায় কাঁচা "point ×" বসত।
     */
    public function test_the_level_is_in_the_path_not_the_query(): void
    {
        $this->assertStringNotContainsString(
            '?level=',
            route('master_data.location.level', ['level' => Location::POINT]),
        );
    }
}
