<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NoticeAudience;
use App\Core\Services\NoticeBoard;
use App\Core\Services\NoticeLifecycle;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Models\Company;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বারটা সব দেখাত, আর কোনো ক্রম ছাড়াই।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ১২ ও ১৩ ───────────────
 * অগ্রাধিকার ধরে ক্রম, সর্বোচ্চ কয়টা দেখাবে তার সীমা, আর *"Dismiss
 * যেখানে অনুমোদিত"* — CRITICAL-এ নয়।
 *
 * ── ⚠️ কেন সীমা আর ক্রম দুইটাই লাগে ─────────────────────────────────
 * ⓘ একটা অফিসে যেকোনো দিন পাঁচ-ছয়টা নোটিশ সক্রিয় থাকে। ⛔ সবগুলো
 * একসাথে বারে দিলে জরুরি কথাটা ভিড়ে হারায়, আর তখন বারটা মানুষ পড়াই
 * বন্ধ করে দেয়।
 *
 * ⚠️ আর ঠিক সেদিনই আগুন লাগার নোটিশটা ওখানে থাকে।
 */
final class TheBarShowedEverythingInNoParticularOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->me = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->me);

        /*
         * ⓘ নিজের নোটিশ নিজে অনুমোদনের নিয়মটা এখানে বন্ধ।
         *
         * ⚠️ এই ফাইলের প্রতিটা দাবি বারের কথা বলে, সইয়ের নয় — আর
         * একজন মানুষের পরীক্ষায় দ্বিতীয় সইকারী আনা মানে দাবিগুলোর
         * মাঝখানে একটা অপ্রাসঙ্গিক সুতো জুড়ে দেওয়া।
         *
         * ⓘ নিয়মটার নিজের দাবি আছে — [[ANoticeCouldJumpAnyStateItLikedTest]]।
         */
        $settings = app(SettingsService::class);
        $settings->set('notice.creator_cannot_approve', false);
        $settings->flush();

        Notice::query()->update(['in_ticker' => false]);
    }

    /**
     * ⭐ জরুরিটা আগে, সাধারণটা পরে।
     *
     * ── ⚠️ কেন ক্রমটা আলাদা করে মাপা ────────────────────────────────
     * ⓘ অগ্রাধিকারের ক্রম লেখার বর্ণমালায় নয় — `critical` < `low` <
     * `normal`। ⛔ কেউ যদি কোনোদিন `orderBy('priority')` লিখে ফেলেন,
     * তালিকাটা সাজানোই দেখাত, কেবল ভুল ক্রমে।
     */
    public function test_the_urgent_one_comes_first(): void
    {
        $this->published('সাধারণ খবর', NoticePriority::IMPORTANT);
        $this->published('আগুন লেগেছে', NoticePriority::EMERGENCY);

        $bar = app(NoticeBoard::class)->forTicker($this->me);

        $this->assertSame('আগুন লেগেছে', (string) $bar->first()?->title,
            'জরুরি নোটিশটা বারের প্রথমে নেই।');
    }

    /**
     * ⛔ আর বারে সর্বোচ্চ যতগুলো বলা আছে, ততগুলোই।
     */
    public function test_the_bar_holds_no_more_than_the_setting_says(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('notice.bar_max', 2);
        $settings->flush();

        foreach (['এক', 'দুই', 'তিন', 'চার'] as $title) {
            $this->published($title, NoticePriority::IMPORTANT);
        }

        $this->assertCount(2, app(NoticeBoard::class)->forTicker($this->me),
            'বারের সীমাটা মানা হচ্ছে না।');
    }

    /**
     * ⓘ সাধারণ নোটিশ বারে ওঠেই না।
     *
     * ── ⚠️ কেন এটা আলাদা দাবি ───────────────────────────────────────
     * ⛔ উপরের সীমাটা মানা হলেও **কোনগুলো** বাদ পড়ছে সেটা অন্য প্রশ্ন।
     * ⓘ ছুটির খবর রোজ বারে উঠলে বারটা রোজই ভরা থাকত, আর ভরা বার মানে
     * না-পড়া বার।
     */
    public function test_an_ordinary_notice_never_reaches_the_bar(): void
    {
        $plain = $this->published('ছুটির খবর', NoticePriority::LOW);

        $this->assertFalse((bool) $plain->in_ticker, 'সাধারণ নোটিশও বারে চলে যাচ্ছে।');
    }

    /**
     * ⭐ সরিয়ে দিলে বারে আর থাকে না।
     */
    public function test_a_dismissed_notice_leaves_the_bar(): void
    {
        $notice = $this->published('সরিয়ে দেব', NoticePriority::IMPORTANT);

        $this->assertTrue(app(NoticeBoard::class)->pushAside($notice, $this->me),
            'সরানো গেল না।');

        $this->assertCount(0, app(NoticeBoard::class)->forTicker($this->me),
            'সরানোর পরেও নোটিশটা বারে বসে আছে।');
    }

    /**
     * ⛔ কিন্তু অত্যন্ত জরুরি নোটিশ সরানো যায় না।
     *
     * ── ⚠️ কেন এই দাবিটা ছাড়া উপরেরটা বিপজ্জনক ──────────────────────
     * ⓘ উপরেরটা একা থাকলে *"সবকিছু সরানো যায়"* একটা যন্ত্রও সবুজ পেত।
     * ⛔ আর তখন মানুষ সবার আগে **সবচেয়ে বড় করে দেখা** নোটিশটাই
     * সরাতেন — কারণ ওটাই সবচেয়ে বিরক্ত করে।
     */
    public function test_a_critical_notice_cannot_be_pushed_aside(): void
    {
        $notice = $this->published('আগুন', NoticePriority::CRITICAL);

        $this->assertFalse(app(NoticeBoard::class)->pushAside($notice, $this->me),
            'অত্যন্ত জরুরি নোটিশটাও সরানো যাচ্ছে।');

        $this->assertCount(1, app(NoticeBoard::class)->forTicker($this->me),
            'সরানো যায় না বলার পরেও নোটিশটা বার থেকে নেমে গেছে।');
    }

    /**
     * ⛔ আর অন্যের দিকে তাক করা নোটিশ কারও বারে ওঠে না।
     *
     * ⓘ বারটা [[NoticeAudience]]-এর একই উত্তরই মানে — তালিকা, পাতা আর
     * বার, তিনটাই এক জায়গা থেকে উত্তর নেয়।
     */
    public function test_a_notice_aimed_elsewhere_stays_off_my_bar(): void
    {
        $notice = $this->published('অন্যের জন্য', NoticePriority::CRITICAL);

        app(NoticeAudience::class)->aimAt($notice, ['role:কেউ-নয়']);

        $this->assertCount(0, app(NoticeBoard::class)->forTicker($this->me),
            'অন্যের নোটিশটা আমার বারে উঠেছে।');
    }

    /** একটা প্রকাশিত নোটিশ, দেওয়া অগ্রাধিকারে। */
    private function published(string $title, NoticePriority $priority): Notice
    {
        $life = app(NoticeLifecycle::class);

        $notice = $life->draft(['title' => $title, 'body' => $title, 'priority' => $priority->value]);
        $notice = $life->submit($notice);
        $notice = $life->approve($notice);

        return $life->publish($notice);
    }
}
