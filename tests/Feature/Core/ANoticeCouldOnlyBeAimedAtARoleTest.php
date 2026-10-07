<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NoticeAudience;
use App\Core\Services\NoticeLifecycle;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নোটিশ কেবল ভূমিকা ধরে লক্ষ্য করা যেত।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ৯ ও ১০ ────────────────
 * ছয় স্তরে লক্ষ্য, আর *"Company A-এর Notice Company B-এর User দেখতে
 * পারবে না"*।
 *
 * ── ⚠️ এই ফাইলের সবচেয়ে জরুরি দাবিটা কোনটা ──────────────────────────
 * ⓘ লক্ষ্যে থাকা মানুষ নোটিশটা **পান** — ওটা এমনিতেই ধরা পড়ে, কেউ
 * অভিযোগ করেন। ⛔ যেটা চুপ থাকে সেটা উল্টো দিক: লক্ষ্যের **বাইরের**
 * মানুষও পেয়ে যাচ্ছেন। ⚠️ কেউ অভিযোগ করেন না, কারণ বাড়তি একটা নোটিশ
 * দেখা কারও চোখে ভুল নয় — যতক্ষণ না সেটা অন্য কোম্পানির বেতনের খবর।
 */
final class ANoticeCouldOnlyBeAimedAtARoleTest extends TestCase
{
    use RefreshDatabase;

    private Company $mine;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->mine = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->mine->id, $this->mine->defaultBranch()?->id);

        $this->me = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->me);
    }

    /**
     * ⭐ লক্ষ্য না বসালে নোটিশটা সবাই দেখেন।
     *
     * ── ⚠️ কেন উল্টোটা নয় ───────────────────────────────────────────
     * ⓘ লক্ষ্য বসাতে ভুলে যাওয়া নোটিশটা **কেউই** দেখত না, আর লেখক
     * ভাবতেন পাঠানো হয়ে গেছে। ⛔ নীরবে না-পৌঁছানো নোটিশের চেয়ে বেশি
     * মানুষের কাছে পৌঁছানো নিরাপদ।
     */
    public function test_a_notice_with_no_target_reaches_everyone(): void
    {
        $notice = $this->aNotice();

        $this->assertTrue(app(NoticeAudience::class)->reaches($notice, $this->me),
            'লক্ষ্যহীন নোটিশটা কারও কাছেই পৌঁছাচ্ছে না।');
    }

    /**
     * ⛔ আর লক্ষ্যের বাইরের মানুষ পান না — এটাই নীরব দিকটা।
     */
    public function test_someone_outside_the_target_does_not_get_it(): void
    {
        $notice = $this->aNotice();

        /* ⓘ এমন একটা শাখা যেখানে এই মানুষটা নেই */
        $elsewhere = Branch::query()->create([
            'company_id' => $this->mine->id,
            'code' => 'FARAWAY',
            'name_en' => 'Far away branch',
            'name_bn' => 'দূরের শাখা',
            'is_active' => true,
        ]);

        app(NoticeAudience::class)->aimAt($notice, ['branch:'.$elsewhere->id]);

        $this->assertFalse(app(NoticeAudience::class)->reaches($notice->fresh(), $this->me),
            'অন্য শাখার নোটিশটাও এই মানুষটার কাছে পৌঁছে যাচ্ছে।');
    }

    /**
     * ⭐ আর লক্ষ্যের ভিতরের মানুষ পান।
     *
     * ── ⚠️ কেন এই দাবিটা ছাড়া উপরেরটা কিছুই মাপে না ──────────────────
     * ⛔ `reaches()` যদি **সবসময়** `false` ফেরত দিত, উপরের দাবিটা সবুজ
     * থাকত — আর একটাও নোটিশ কারও কাছে পৌঁছাত না।
     */
    public function test_someone_inside_the_target_does_get_it(): void
    {
        $notice = $this->aNotice();

        app(NoticeAudience::class)->aimAt($notice, ['user:'.$this->me->getKey()]);

        $this->assertTrue(app(NoticeAudience::class)->reaches($notice->fresh(), $this->me),
            'নিজের নামে লেখা নোটিশটাও পৌঁছাচ্ছে না।');
    }

    /**
     * ⛔ অন্য কোম্পানির নোটিশ এই কোম্পানির তালিকায় আসে না।
     *
     * ── ⚠️ কেন সত্যিই একটা বানানো হয়, ধরে নেওয়া হয় না ───────────────
     * ⓘ *"গ্লোবাল স্কোপ আছে, তাই ঠিক আছে"* — এই ধরে নেওয়াটাই বিপজ্জনক।
     * ⛔ স্কোপটা `DB::table()`-এ চলে না, আর কোনো একদিন কেউ কাঁচা
     * কোয়েরি লিখবেন। ⚠️ তাই দাবিটা সত্যিই একটা ভিনদেশি নোটিশ বানিয়ে
     * তারপর খোঁজে।
     */
    public function test_another_companys_notice_is_not_in_the_list(): void
    {
        $theirs = Company::query()->where('id', '!=', $this->mine->id)->first();

        $this->assertNotNull($theirs, 'ডেমোতে দ্বিতীয় কোম্পানিই নেই — দাবিটা কিছু মাপছে না।');

        $stranger = CompanyContext::forCompany($theirs->id, fn () => app(NoticeLifecycle::class)
            ->draft(['title' => 'ওদের কথা', 'body' => 'ওদের কর্মীদের জন্য']));

        $mine = Notice::query()->pluck('id');

        $this->assertNotContains($stranger->id, $mine->all(),
            'অন্য কোম্পানির নোটিশ এই কোম্পানির তালিকায় বসে আছে।');
    }

    /**
     * ⭐ তালিকার ছাঁকনিটাও একই উত্তর মানে।
     *
     * ── ⚠️ কেন তালিকা আর একক পাতা দুইটাই মাপা ───────────────────────
     * ⓘ ABOS-এ এই আকারের ফাঁক আগেও হয়েছে: তালিকা ছেঁকে রাখা আর পাতা
     * পাহারা দেওয়া দুইটা আলাদা কাজ, আর দ্বিতীয়টা ভুলে যাওয়া সহজ।
     * ⛔ দুইটা আলাদা উত্তর দিলে তালিকায় না থাকা নোটিশটা ঠিকানা লিখে
     * খোলা যেত।
     */
    public function test_the_list_filter_gives_the_same_answer_as_the_page(): void
    {
        $audience = app(NoticeAudience::class);

        $mine = $this->aNotice();
        $notMine = $this->aNotice();

        $audience->aimAt($mine, ['user:'.$this->me->getKey()]);
        $audience->aimAt($notMine, ['role:কেউ-নয়']);

        $visible = $audience->scopeVisibleTo(Notice::query(), $this->me)->pluck('id')->all();

        $this->assertContains($mine->id, $visible, 'নিজের নোটিশটা তালিকায় নেই।');

        $this->assertNotContains($notMine->id, $visible, 'অন্যের নোটিশটা তালিকায় চলে এসেছে।');
    }

    /**
     * ⭐ একটা মডিউল নিজের স্তর যোগ করতে পারে।
     *
     * ── ⓘ কেন এই দরজাটা দরকার ───────────────────────────────────────
     * বিভাগ, পদ আর কর্মী কোরের সম্পত্তি নয়, আর কোর কোনো মডিউলের নাম
     * জানতে পারে না ([[BoundariesTest]])। ⚠️ দরজাটা না থাকলে ঐ তিনটা
     * স্তর হয় কোনোদিন আসত না, নয় সীমানাটা ভাঙত।
     */
    public function test_a_module_can_add_a_level_of_its_own(): void
    {
        $audience = app(NoticeAudience::class);

        $audience->extend('department', fn (User $user) => ['department:9']);

        $notice = $this->aNotice();
        $audience->aimAt($notice, ['department:9']);

        $this->assertTrue($audience->reaches($notice->fresh(), $this->me),
            'মডিউলের যোগ করা স্তরটা কাজ করছে না।');
    }

    /**
     * ⛔ আর একটা মডিউলের ভুল গোটা পর্দা নামায় না।
     *
     * ── ⚠️ কেন এটা মাপা হয় ──────────────────────────────────────────
     * ⓘ একটা resolver ব্যতিক্রম ছুড়লে গোটা নোটিশের তালিকাটাই সাদা হয়ে
     * যেত, অথচ দোষটা ঐ মডিউলের। ⛔ আর ব্যবহারকারী দেখতেন একটা ভাঙা
     * পাতা, যার কারণ নোটিশে নেই।
     */
    public function test_a_broken_module_level_does_not_take_the_screen_down(): void
    {
        $audience = app(NoticeAudience::class);

        $audience->extend('broken', function (User $user): array {
            throw new \RuntimeException('এই মডিউলটা ভাঙা');
        });

        $keys = $audience->keysFor($this->me);

        $this->assertContains('user:'.$this->me->getKey(), $keys,
            'একটা ভাঙা স্তরের জন্য বাকি সব চাবিও হারিয়ে গেছে।');
    }

    /** একটা খসড়া নোটিশ। */
    private function aNotice(): Notice
    {
        return app(NoticeLifecycle::class)->draft(['title' => 'পরীক্ষা', 'body' => 'লেখা']);
    }
}
