<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ফুটারের নোটিশটা চলে।
 *
 * ── কেন এই পরীক্ষাটা লেখা হলো ───────────────────────────────────────
 * মালিকের নির্দেশ ছিল স্পষ্ট: *"footer e notice cholbe dms er moto"*।
 * নোটিশটা বানানো হয়েছিল, কিন্তু **স্থির** — আর কারণটা কোডের মন্তব্যে
 * লিখে সিদ্ধান্তটা নিজেই নেওয়া হয়েছিল। সেটা ভুল ছিল দুইভাবে:
 * সিদ্ধান্তটা মালিকের, আর স্থির বারে দ্বিতীয় নোটিশটা কেটে গিয়ে কেউ
 * কোনোদিন দেখত না।
 *
 * তাই এই পরীক্ষাটা "নোটিশ আছে কি না" দেখে না — দেখে **চলে কি না**।
 */
class TheNoticeRunsTest extends TestCase
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

    /** প্রতিষ্ঠানের নিজের একটা নোটিশ বসানো। */
    private function notice(string $text): void
    {
        app(SettingsService::class)->set('system.notice', $text);

        // নোটিশগুলো ক্যাশ হয়, তাই পরের অনুরোধে নতুনটাই যেন আসে
        Cache::flush();
    }

    private function page(): string
    {
        return $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();
    }

    // ── চলাটা ───────────────────────────────────────────────────────

    /**
     * লেখাটা সত্যিই চলে।
     *
     * এটাই মূল দাবি, আর আগের রূপে এটা মিথ্যা ছিল।
     */
    public function test_the_notice_actually_moves(): void
    {
        $this->notice('রবিবার থেকে দাম বাড়বে');

        $html = $this->page();

        $this->assertStringContainsString('abos-ticker', $html,
            'নোটিশটা স্থির — মালিক বলেছিলেন এটা চলবে।');

        $this->assertStringContainsString('@keyframes abos-ticker', $html,
            'চলার নিয়মটাই পাতায় নেই, তাই কিছু নড়বে না।');
    }

    /**
     * লুপটা নির্বিঘ্ন — দুইটা কপি, এক কপির সমান সরে।
     *
     * এক কপিতে লেখাটা বাঁ দিকে বেরিয়ে গিয়ে ডান দিক থেকে ফেরার আগে
     * একটা ফাঁকা বার দেখা যেত, আর প্রতি চক্রে ওই ফাঁকটাই চোখে পড়ত।
     */
    public function test_the_loop_has_no_gap(): void
    {
        $this->notice('বগুড়ার রুট আজ বন্ধ');

        /*
         * কেবল চলন্ত বারের ভেতরটা গোনা।
         *
         * পুরো পাতায় গুনলে সংখ্যাটা ৩ আসে, কারণ নোটিশের ঘণ্টাও একই
         * তালিকা দেখায় (নতুন কোনো অনুসন্ধান ছাড়াই) — আর সেটা ঠিকই
         * আছে। প্রথমে পুরো পাতায় গুনে ফেলেছিলাম, আর পরীক্ষাটা ভুল
         * কারণে লাল হয়েছিল।
         */
        $html = $this->page();

        $start = strpos($html, 'abos-ticker');
        $ticker = substr($html, $start, strpos($html, '@keyframes') - $start);

        $this->assertSame(2, substr_count($ticker, 'বগুড়ার রুট আজ বন্ধ'),
            'একটাই কপি — লুপে ফাঁক থাকবে।');

        $this->assertStringContainsString('translateX(-50%)', $html);
    }

    /**
     * একই বার্তা একসাথে দুইবার চোখে পড়ে না।
     *
     * ---- HP-র রিপোর্ট, ২৫ আগস্ট ২০২৬ ----
     * *"ফুটারে ব্যাকআপ সতর্কবার্তা দুইবার ওভারল্যাপ করে দেখাচ্ছে"* --
     * স্ক্রিনশট সহ। কথাটা সত্যি ছিল, যদিও কারণটা রেন্ডারিং-এর ভুল নয়।
     *
     * লুপ নির্বিঘ্ন করতে বারটা ইচ্ছাকৃতভাবে **দুইটা কপি** আঁকে
     * (উপরের পরীক্ষাটা সেটাই পাহারা দেয়)। কিন্তু নোটিশ যখন একটা বা
     * দুইটা, কপি দুইটা এত ছোট হত যে দুইটাই একসাথে পর্দায় থাকত --
     * অর্থাৎ পাঠক একই বাক্য পাশাপাশি দুইবার দেখতেন।
     *
     * পাঠকের কাছে ওটা লুপের কৌশল নয়, একটা ভুল -- আর ভুল দেখানো বার
     * থেকে মানুষ চোখ সরিয়ে নেয়, তারপর আসল সতর্কবার্তাটাও আর পড়ে না।
     *
     * ---- কেন `min-w-full` ----
     * প্রতিটা কপি অন্তত পর্দার সমান চওড়া হলে দ্বিতীয়টা শুরুতেই বাইরে
     * থাকে, আর প্রথমটা বেরিয়ে যেতে যেতে ভেতরে আসে -- যেভাবে চলন্ত
     * লেখা পড়ার কথা।
     */
    public function test_one_notice_is_never_shown_twice_side_by_side(): void
    {
        $this->notice('বগুড়ার রুট আজ বন্ধ');

        $html = $this->page();

        $start = strpos($html, 'abos-ticker');
        $ticker = substr($html, $start, strpos($html, '@keyframes') - $start);

        $this->assertSame(2, substr_count($ticker, 'min-w-full'),
            'কপিগুলো পর্দার সমান চওড়া নয় — একটা নোটিশ থাকলে দুইটা কপিই '
            .'একসাথে চোখে পড়বে, আর পাঠক ভাববেন বার্তাটা দুইবার লেখা হয়েছে।');
    }

    /**
     * মাউস রাখলে থামে।
     *
     * যে সংখ্যা কার্সারের নিচ থেকে সরে যাচ্ছে, সেটা কেউ ক্লিক করতে
     * পারে না — আর নোটিশগুলো ক্লিকযোগ্য বলেই বানানো (নিয়ম ১)।
     */
    public function test_it_stops_under_the_pointer(): void
    {
        $this->notice('রবিবার থেকে দাম বাড়বে');

        $this->assertStringContainsString('animation-play-state:paused', $this->page(),
            'মাউস রাখলে থামে না — চলন্ত নোটিশে ক্লিক করা যাবে না।');
    }

    /**
     * যিনি চলন্ত জিনিস বন্ধ রেখেছেন, তাঁর পর্দায় নড়ে না।
     *
     * স্ক্রল করা লেখা ঠিক সেই জিনিসগুলোর একটা যার জন্য
     * `prefers-reduced-motion` সেটিংটা আছে। তখনও তালিকাটা থাকে, শুধু
     * স্থির — তথ্যটা হারায় না।
     */
    public function test_it_respects_reduced_motion(): void
    {
        $this->notice('রবিবার থেকে দাম বাড়বে');

        $this->assertStringContainsString('motion-safe:animate-', $this->page(),
            'চলন্ত জিনিস বন্ধ রাখা পাঠকের পর্দাতেও নড়বে।');
    }

    // ── কী দেখায়, আর কখন চুপ থাকে ───────────────────────────────────

    /** প্রতিষ্ঠানের নিজের নোটিশটা সত্যিই পর্দায় আসে। */
    public function test_the_company_notice_reaches_the_bar(): void
    {
        $this->notice('বাকি দেওয়া আজ বন্ধ');

        $this->assertStringContainsString('বাকি দেওয়া আজ বন্ধ', $this->page());
    }

    /**
     * কিছু না থাকলে বারটা চুপ।
     *
     * "সব ঠিক আছে" ঘুরতে থাকলে দুই সপ্তাহে মানুষ তাকানো বন্ধ করে দেয়,
     * আর যেদিন সত্যিই কিছু ঘটে সেদিনও দেখে না।
     */
    public function test_it_says_nothing_when_there_is_nothing_to_say(): void
    {
        /*
         * তালিকাটা খালি করে দেখা — সেটিং মুছে নয়।
         *
         * `system.notice` মুছলেও ডেমো কোম্পানিতে সত্যিকারের নোটিশ থেকে
         * যায় (অপেক্ষমাণ অনুমোদন, খসড়া) — আর সেগুলো থাকাই ঠিক। দাবিটা
         * সেটিং নিয়ে নয়, পর্দাটা নিয়ে: **কিছু না থাকলে বার চুপ**।
         * তাই ক্যাশের চাবিটাই আগে থেকে খালি বসিয়ে দেওয়া হয় — আসল
         * কোডপথ ধরেই, কারণ `all()` ওই চাবিটাই পড়ে।
         */
        Cache::flush();

        Cache::put(
            'abos.notice.'.$this->owner->current_company_id.'.'.$this->owner->id,
            [],
            60,
        );

        $html = $this->page();

        /*
         * চলার নিয়মটা পাতায় থাকতেই পারে (`@once`), কিন্তু ঘোরার মতো
         * কোনো সারি থাকা চলবে না। তাই খোঁজা হয় ডটটা — প্রতিটা নোটিশের
         * সামনে যেটা বসে।
         */
        $this->assertStringNotContainsString('size-1.5 rounded-full bg-current', $html,
            'কোনো নোটিশ নেই, তবু বারে সারি ঘুরছে।');
    }

    /**
     * পুনরাবৃত্ত কপিটা পর্দা-পাঠকের কাছে লুকানো।
     *
     * দুইটা কপি কেবল চোখের জন্য। না লুকালে পর্দা-পাঠক প্রতিটা নোটিশ
     * দুইবার পড়ত, আর কী-বোর্ডে Tab চাপলে একই লিংকে দুইবার থামত।
     */
    public function test_the_second_copy_is_hidden_from_screen_readers(): void
    {
        $this->notice('রবিবার থেকে দাম বাড়বে');

        $html = $this->page();

        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html,
            'দ্বিতীয় কপির লিংকগুলো Tab-এ ধরা পড়বে।');
    }
}
