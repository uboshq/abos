<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NoticeLifecycle;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\Notice;
use App\Models\NoticeCategory;
use App\Models\NoticeTemplate;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * পর্দাগুলো জোড়া লাগানো ছিল, কিন্তু কেউ তাকায়নি।
 *
 * ── ⚠️ কেন এই ফাইলটা আলাদা করে লাগল ─────────────────────────────────
 * ⓘ নোটিশ সেন্টারের সেবা-স্তরের ৬১টা দাবি সবুজ হওয়ার পরেও চারটা জিনিস
 * **একবারও খোলা হয়নি**: টেমপ্লেটের পর্দা, দুইটা রিপোর্ট, হিসাবের পাতা,
 * আর API।
 *
 * ⛔ আর ABOS-এ বাগটা প্রায় সবসময় ঠিক এই আকারের — **অসম্পূর্ণ জোড়া**,
 * ভাঙা যুক্তি নয়। ⚠️ একটা ভুল রুটের নাম, একটা অনুপস্থিত অনুবাদের চাবি,
 * একটা ভিউয়ের ভুল পথ — সেবাটা নিখুঁত থেকেও **কেউ পৌঁছাতে পারত না**,
 * আর কোথাও কিছু লাল হত না।
 *
 * ⓘ তাই এখানকার প্রতিটা দাবি একটাই প্রশ্ন করে: *"পাতাটা খোলে তো?"*
 */
final class TheNoticeScreensWereWiredButNobodyLookedTest extends TestCase
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

        $settings = app(SettingsService::class);
        $settings->set('notice.creator_cannot_approve', false);
        $settings->flush();
    }

    /**
     * ⭐ হিসাবের পাতাটা খোলে, আর সংখ্যাগুলো সত্যিই থাকে।
     *
     * ── ⚠️ কেন কেবল ২০০ যথেষ্ট নয় ───────────────────────────────────
     * ⛔ একটা খালি পাতাও ২০০ দেয়। ⓘ তাই দাবিটা নোটিশের নম্বরটা খোঁজে —
     * সংখ্যাটা সত্যিই এসেছে কি না।
     */
    public function test_the_analytics_page_opens_and_shows_a_real_notice(): void
    {
        $notice = $this->published(ack: true);

        $html = (string) $this->get(route('system_admin.notice.analytics'))->assertOk()->getContent();

        $this->assertStringContainsString((string) $notice->document_no, $html,
            'হিসাবের পাতাটা খুলল, কিন্তু সই-অপেক্ষায় থাকা নোটিশটা ওখানে নেই।');
    }

    /**
     * ⭐ টেমপ্লেটের পর্দা খোলে, আর ছাঁচটা তালিকায় আসে।
     */
    public function test_the_templates_screen_opens_and_lists_what_is_there(): void
    {
        $template = $this->aTemplate();

        $html = (string) $this->get(route('system_admin.notice.template.index'))->assertOk()->getContent();

        $this->assertStringContainsString((string) $template->code, $html,
            'টেমপ্লেটের পর্দাটা খুলল, কিন্তু ছাঁচটা তালিকায় নেই।');
    }

    /**
     * ⭐ আর পর্দা থেকে ছাঁচ বসানো যায়।
     *
     * ⓘ উপরেরটা কেবল পড়ার দিক মাপে। ⛔ লেখার দরজাটা ভাঙা থাকলেও ওটা
     * সবুজ থাকত, আর কেউ কোনোদিন একটা ছাঁচও বানাতে পারত না।
     */
    public function test_a_template_can_be_saved_from_the_screen(): void
    {
        $this->post(route('system_admin.notice.template.store'), [
            'code' => 'HOLIDAY',
            'name_en' => 'Holiday',
            'name_bn' => 'ছুটি',
            'title' => 'অফিস বন্ধ',
            'body' => 'শুক্রবার',
            'priority' => NoticePriority::IMPORTANT->value,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, NoticeTemplate::query()->where('code', 'HOLIDAY')->count(),
            'ছাঁচটা সেভ হয়নি।');
    }

    /**
     * ⭐ ছাঁচ থেকে লেখা শুরু করলে সম্পাদনার পর্দায় পৌঁছানো যায়।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * ⓘ ছাঁচ বানানো আর ছাঁচ **ব্যবহার করা** দুইটা আলাদা জোড়া। ⛔ শেষেরটা
     * না থাকলে ছাঁচগুলো কেবল একটা তালিকা হয়ে পড়ে থাকত — লেখা হয়েছে,
     * জোড়া লাগেনি।
     */
    public function test_a_draft_can_be_started_from_a_template(): void
    {
        $template = $this->aTemplate();

        $before = Notice::query()->count();

        $this->post(route('system_admin.notice.template.use', $template->id))
            ->assertRedirect();

        $this->assertSame($before + 1, Notice::query()->count(),
            'ছাঁচ থেকে কোনো খসড়াই জন্মায়নি।');
    }

    /**
     * ⭐ দুইটা রিপোর্টই খোলে।
     *
     * ⓘ রিপোর্টের slug ভুল হলে ৪০৪ — আর মেনুতে ঐ slug-ই লেখা।
     */
    public function test_both_reports_open(): void
    {
        $this->published(ack: true);

        foreach (['notice-register', 'notice-signatures'] as $slug) {
            $this->get(route('system_admin.report.show', ['slug' => $slug]))
                ->assertOk();
        }
    }

    /**
     * ⛔ আর অচেনা নামের রিপোর্ট ৪০৪।
     *
     * ── ⚠️ কেন এই দাবিটা ছাড়া উপরেরটা কিছুই মাপে না ──────────────────
     * ⓘ কন্ট্রোলারটা যদি **যেকোনো** slug মেনে নিত, উপরের দাবিটা সবুজ
     * থাকত — ⛔ আর ঠিকানায় যা খুশি লিখে একটা ভাঙা পাতা পাওয়া যেত।
     */
    public function test_an_unknown_report_is_not_found(): void
    {
        $this->get(route('system_admin.report.show', ['slug' => 'এমন-কিছু-নেই']))
            ->assertNotFound();
    }

    /**
     * ⭐ API-তে নিজের নোটিশগুলো আসে, আর অবস্থাসহ।
     *
     * ── ⚠️ কেন `public_id`, `id` নয় ─────────────────────────────────
     * ⓘ বাইরের কেউ ক্রমিক সংখ্যা দেখে না — ⛔ গোনা গেলে *"আমার আগে
     * কয়টা নোটিশ ছিল"* প্রশ্নেরও উত্তর পাওয়া যায়।
     */
    public function test_the_api_lists_my_notices(): void
    {
        $notice = $this->published(ack: true);

        $this->asTheApp();

        $this->getJson('/api/v1/notices')
            ->assertOk()
            ->assertJsonPath('data.0.id', $notice->public_id)
            ->assertJsonPath('data.0.standing', 'unread');
    }

    /**
     * ⭐ আর API থেকে সই দেওয়া যায়, আর অবস্থাটা বদলায়।
     */
    public function test_the_api_takes_a_signature(): void
    {
        $notice = $this->published(ack: true);

        $this->asTheApp();

        $this->postJson('/api/v1/notices/'.$notice->public_id.'/acknowledge')
            ->assertOk()
            ->assertJsonPath('data.standing', 'acknowledged');

        $this->assertSame(1, $notice->signatures()->count(), 'সইটা খাতায় বসেনি।');
    }

    /**
     * ⛔ আর যে নোটিশ এই মানুষটার নয়, API তার হদিস দেয় না।
     *
     * ── ⚠️ কেন ৪০৪, ৪০৩ নয় ──────────────────────────────────────────
     * ⓘ ৪০৩ বলে *"আছে, কিন্তু তোমার নয়"* — আর ওটুকুই যথেষ্ট খবর: ⛔
     * নম্বর ধরে ধরে ডাকলে কোন নোটিশগুলো সত্যিই আছে তা গোনা যেত।
     */
    public function test_the_api_hides_a_notice_aimed_elsewhere(): void
    {
        $notice = $this->published(ack: true);

        app(\App\Core\Services\NoticeAudience::class)->aimAt($notice, ['role:কেউ-নয়']);

        $this->asTheApp();

        $this->postJson('/api/v1/notices/'.$notice->public_id.'/acknowledge')
            ->assertNotFound();
    }

    /**
     * ফোনের দরজায় ঢোকা — সেশন নয়, টোকেন।
     *
     * ── ⚠️ কেন `actingAs()` এখানে চলত না ────────────────────
     * ⓘ ওয়েব চলে কুকিতে, ফোন চলে টোকেনে — আর `/api/v1` দরজাগুলো
     * `abilities:app` চায়। ⛔ সেশন দিয়ে ডাকলে ৪০১ ফিরত, আর দাবিটা
     * মাপত দরজার তালা, নোটিশের নিয়ম নয়।
     *
     * ⓘ চাবির নামটা [[AuthController]] থেকে নেওয়া, হাতে লেখা নয় —
     * ⚠️ লিখলে কাল নাম বদলালে পরীক্ষাটা সবুজ থেকে যেত, আর ফোনটা
     * বন্ধ দরজায় দাঁড়াত।
     */
    private function asTheApp(): void
    {
        Sanctum::actingAs($this->me, [AuthController::APP]);
    }

    /**
     * ⭐ ধরনের পর্দা খোলে, আর পর্দা থেকে ধরন বসানো যায়।
     *
     * ── ⚠️ কেন এটা লাগল ────────────────────────────────
     * ⓘ ক্যাটাগরির টেবিল আর মডেল আগেই ছিল, কিন্তু **বানানোর
     * কোনো পথ ছিল না**। ⛔ ফলে `notice_category_id` চিরকাল খালি
     * থাকত, আর ধরনের ডিফল্ট অগ্রাধিকার কোনোদিন কাজেই লাগত না।
     */
    public function test_the_kinds_screen_opens_and_takes_one(): void
    {
        $this->get(route('system_admin.notice.category.index'))->assertOk();

        $this->post(route('system_admin.notice.category.store'), [
            'code' => 'SECURITY',
            'name_en' => 'Security alert',
            'name_bn' => 'নিরাপত্তা সতর্কতা',
            'default_priority' => NoticePriority::CRITICAL->value,
            'needs_approval' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, NoticeCategory::query()->where('code', 'SECURITY')->count(),
            'ধরনটা সেভ হয়নি।');
    }

    /**
     * ⭐ আর ধরন বাছলে অগ্রাধিকার নিজে থেকেই বসে।
     *
     * ⓘ এটাই ক্যাটাগরি থাকার মূল কারণ — ⚠️ না বসলে ক্যাটাগরি
     * কেবল একটা লেবেল, সিদ্ধান্ত নয়।
     */
    public function test_a_kind_lends_its_priority_to_a_new_notice(): void
    {
        $kind = NoticeCategory::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'SEC',
            'name_en' => 'Security',
            'default_priority' => NoticePriority::CRITICAL->value,
            'is_active' => true,
        ]);

        $notice = app(NoticeLifecycle::class)->draft([
            'title' => 'সতর্কতা',
            'body' => 'লেখা',
            'notice_category_id' => $kind->id,
        ]);

        $this->assertSame(NoticePriority::CRITICAL, $notice->priority,
            'ধরনের ডিফল্ট অগ্রাধিকারটা বসেনি।');
    }

    /**
     * ⛔ কিন্তু হাতে দেওয়া অগ্রাধিকার ধরনের উপরে বসে।
     *
     * ⓘ উপরেরটা একা থাকলে *"ধরনের সিদ্ধান্তই শেষ কথা"* এমন
     * একটা যন্ত্রও সবুজ পেত — ⚠️ আর তখন মানুষ হাতে বাছার পরেও
     * ধরনেরটাই জিতত, নীরবে।
     */
    public function test_what_the_writer_picks_beats_the_kind(): void
    {
        $kind = NoticeCategory::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'SEC2',
            'name_en' => 'Security',
            'default_priority' => NoticePriority::CRITICAL->value,
            'is_active' => true,
        ]);

        $notice = app(NoticeLifecycle::class)->draft([
            'title' => 'ছুটি',
            'body' => 'লেখা',
            'notice_category_id' => $kind->id,
            'priority' => NoticePriority::LOW->value,
        ]);

        $this->assertSame(NoticePriority::LOW, $notice->priority,
            'ধরনটা মানুষের বাছাইয়ের উপরে বসে গেছে।');
    }

    /** একটা ছাঁচ। */
    private function aTemplate(): NoticeTemplate
    {
        return NoticeTemplate::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'MAINT',
            'name_en' => 'Maintenance',
            'name_bn' => 'রক্ষণাবেক্ষণ',
            'title' => 'আজ রাতে সার্ভার বন্ধ',
            'body' => 'রাত ১১টা থেকে',
            'priority' => NoticePriority::IMPORTANT->value,
            'is_active' => true,
        ]);
    }

    /** একটা প্রকাশিত নোটিশ। */
    private function published(bool $ack): Notice
    {
        $life = app(NoticeLifecycle::class);

        $notice = $life->draft([
            'title' => 'নতুন নিয়ম',
            'body' => 'আজ থেকে',
            'priority' => NoticePriority::IMPORTANT->value,
            'ack_required' => $ack,
        ]);

        $notice = $life->submit($notice);
        $notice = $life->approve($notice);

        return $life->publish($notice);
    }
}
