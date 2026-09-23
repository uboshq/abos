<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NoticeLifecycle;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Core\Support\NoticeStatus;
use App\Models\Company;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * নোটিশ যেকোনো অবস্থা থেকে যেকোনো অবস্থায় লাফাতে পারত।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ৪ ─────────────────────
 * *"Create → Review → Approve → Schedule/Publish"*, আর প্রকাশিত নোটিশ
 * সরাসরি মোছা যাবে না।
 *
 * ── ⛔ কেন অবস্থার পাহারা ছাড়া গোটা অনুমোদন ব্যবস্থাটাই সাজসজ্জা ────
 * ⓘ `status` একটা সাধারণ কলাম হলে যেকোনো পর্দা `update()` দিয়ে সেটা
 * বদলে দিতে পারত। ⚠️ তখন *"সংরক্ষণাগার থেকে সোজা প্রকাশ"* একটা বৈধ
 * পথ হয়ে যেত — অনুমোদনের ধাপটা নীরবে এড়ানো যেত, আর খাতায় কোনো চিহ্ন
 * থাকত না।
 *
 * ⛔ ভুলটা ধরা পড়ত সেদিন, যেদিন কেউ একটা ফেরত-পাঠানো নোটিশ প্রকাশ করে
 * ফেলতেন।
 */
final class ANoticeCouldJumpAnyStateItLikedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⭐ খসড়া থেকে প্রকাশ — পুরো পথটা হাঁটা যায়।
     *
     * ── ⚠️ কেন এই দাবিটা সবার আগে ───────────────────────────────────
     * ⓘ বাকি সব দাবি বলে *"এই পথটা বন্ধ"*। ⛔ পাহারাটা যদি **সব** পথ
     * বন্ধ করে দিত, তবে ওগুলো সবুজ থাকত আর নোটিশ কোনোদিন প্রকাশই হত
     * না — আর সেটা কোনো পরীক্ষা ধরত না।
     */
    public function test_the_whole_road_from_draft_to_published_can_be_walked(): void
    {
        $life = app(NoticeLifecycle::class);

        $notice = $life->draft(['title' => 'বাকি দেওয়া বন্ধ', 'body' => 'আজ থেকে']);

        $this->assertSame(NoticeStatus::DRAFT, $notice->status, 'নতুন নোটিশ খসড়া হয়ে জন্মায়নি।');

        $notice = $life->submit($notice);
        $notice = $life->approve($notice);
        $notice = $life->publish($notice);

        $this->assertSame(NoticeStatus::PUBLISHED, $notice->status, 'পুরো পথটা হেঁটেও প্রকাশ হলো না।');

        $this->assertNotNull($notice->published_at, 'প্রকাশ হলো, কিন্তু কখন হলো তা লেখা নেই।');
    }

    /**
     * ⛔ আর খসড়া থেকে সোজা প্রকাশে লাফানো যায় না।
     *
     * ⓘ ঠিক এই লাফটাই অনুমোদনের গোটা ব্যবস্থাটা অর্থহীন করে দিত।
     */
    public function test_a_draft_cannot_jump_straight_to_published(): void
    {
        $notice = app(NoticeLifecycle::class)->draft(['title' => 'পরীক্ষা', 'body' => 'লেখা']);

        $this->expectException(ValidationException::class);

        app(NoticeLifecycle::class)->publish($notice);
    }

    /**
     * ⛔ সংরক্ষণাগার শেষ ঘর — ওখান থেকে ফেরা নেই।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * ⓘ উপরেরটা একটা **আগের** ঘর থেকে লাফ মাপে। ⛔ এটা মাপে **শেষ**
     * ঘর থেকে, আর তফাতটা দামি: শেষ ঘরের তালিকা খালি, তাই ওখানে একটা
     * ভুল থাকলে সেটা অন্যরকম — `nextAllowed()` খালি ফেরত দেওয়া আর
     * ভুল তালিকা ফেরত দেওয়া দুইটা আলাদা ভাঙন।
     */
    public function test_an_archived_notice_cannot_come_back(): void
    {
        $life = app(NoticeLifecycle::class);

        $notice = $life->draft(['title' => 'পুরনো', 'body' => 'লেখা']);
        $notice = $life->submit($notice);
        $notice = $life->approve($notice);
        $notice = $life->publish($notice);
        $notice = $life->archive($notice);

        $this->assertTrue($notice->status->isFinal(), 'সংরক্ষণাগার থেকে এখনো পথ খোলা।');

        $this->expectException(ValidationException::class);

        $life->publish($notice);
    }

    /**
     * ⛔ আর প্রত্যাহার কারণ ছাড়া হয় না।
     *
     * ⓘ ছয় মাস পরে *"ঐ নোটিশটা কেন তুলে নেওয়া হলো"* প্রশ্নের এই
     * লেখাটাই একমাত্র উত্তর।
     */
    public function test_a_recall_without_a_reason_is_refused(): void
    {
        $notice = $this->aPublishedNotice();

        $this->expectException(ValidationException::class);

        app(NoticeLifecycle::class)->recall($notice, '   ');
    }

    /**
     * ⭐ কারণ দিলে প্রত্যাহার হয়, আর নোটিশটা বার থেকে নেমে যায়।
     *
     * ── ⚠️ কেন `in_ticker`-টাও মাপা ─────────────────────────────────
     * ⓘ অবস্থা বদলানো সহজ অংশ। ⛔ কঠিন অংশটা হলো **ফলটা**: প্রত্যাহার
     * করার পরেও নোটিশটা নিচের বারে বসে থাকলে কাজটা হয়নি, অথচ খাতা
     * বলত হয়েছে।
     */
    public function test_a_recall_takes_it_off_the_bar(): void
    {
        $notice = app(NoticeLifecycle::class)->recall($this->aPublishedNotice(), 'তারিখটা ভুল ছিল');

        $this->assertSame(NoticeStatus::RECALLED, $notice->status, 'প্রত্যাহার হয়নি।');

        $this->assertFalse((bool) $notice->in_ticker, 'প্রত্যাহারের পরেও নোটিশটা নিচের বারে বসে আছে।');
    }

    /**
     * ⭐ আর নতুন নোটিশ পুরনোটাকে ঢেকে দেয় — মোছে না।
     *
     * ⓘ স্পেকের ধারা ২৬: ভুল নোটিশ মোছা হয় না। ⚠️ সুতোটা থাকলে ছয়
     * মাস পরেও বলা যায় ভুলটা কী ছিল আর কে শুধরেছেন।
     */
    public function test_a_new_notice_supersedes_the_old_one(): void
    {
        $life = app(NoticeLifecycle::class);

        $old = $this->aPublishedNotice();
        $new = $life->draft(['title' => 'শুদ্ধ তারিখ', 'body' => 'আসল কথা']);

        $old = $life->supersede($old, $new, 'তারিখটা ভুল ছিল');

        $this->assertSame($new->id, $old->superseded_by, 'পুরনোটা কোনটা দিয়ে ঢাকা হলো তা লেখা নেই।');

        $this->assertNotNull(Notice::query()->find($old->id), 'পুরনো নোটিশটা মুছে গেছে — ঢাকার কথা ছিল।');
    }

    /**
     * ⭐ জরুরি নোটিশ নিজে থেকেই বারে যায়, সাধারণটা যায় না।
     *
     * ── ⚠️ কেন দুই দিকই মাপা ────────────────────────────────────────
     * ⓘ কেবল *"জরুরিটা বারে যায়"* মাপলে **সব নোটিশ বারে পাঠানো**
     * একটা যন্ত্রও সবুজ পেত। ⛔ আর তখন বারটা রোজই ভরা থাকত, আর ভরা
     * বার মানে না-পড়া বার।
     */
    public function test_the_bar_takes_the_urgent_one_and_leaves_the_ordinary(): void
    {
        $life = app(NoticeLifecycle::class);

        $urgent = $life->draft(['title' => 'আগুন', 'body' => 'বেরোন', 'priority' => NoticePriority::CRITICAL->value]);
        $plain = $life->draft(['title' => 'ছুটি', 'body' => 'শুক্রবার', 'priority' => NoticePriority::LOW->value]);

        $this->assertTrue((bool) $urgent->in_ticker, 'জরুরি নোটিশটা বারে যাচ্ছে না।');

        $this->assertFalse((bool) $plain->in_ticker, 'সাধারণ নোটিশও বারে চলে যাচ্ছে — বারটা ভিড়ে ভরবে।');
    }

    /**
     * ⭐ প্রতিটা নোটিশের নিজের নম্বর, আর খসড়া অবস্থাতেই।
     *
     * ⓘ অনুমোদনের আলোচনা হয় খসড়া অবস্থায় — *"NTC-…-0058 নিয়ে কথা
     * আছে"*। ⚠️ নম্বরটা প্রকাশে বসালে ঐ কথাটা বলা যেত না।
     */
    public function test_every_notice_carries_its_own_number_from_the_start(): void
    {
        $notice = app(NoticeLifecycle::class)->draft(['title' => 'নম্বর', 'body' => 'লেখা']);

        $this->assertNotEmpty($notice->document_no, 'খসড়া নোটিশের কোনো নম্বর নেই।');

        $this->assertStringContainsString(NoticeLifecycle::SERIES, (string) $notice->document_no,
            'নম্বরটা নোটিশের সিরিজ থেকে আসেনি।');
    }

    /**
     * ⛔ আর প্রকাশিত নোটিশ মোছা যায় না।
     *
     * ── ⭐ মালিকের স্পেক, ধারা ৪ ও ৪৯ ───────────────────────────────
     * *"Published Notice সরাসরি delete করা যাবে না"*।
     */
    public function test_a_published_notice_cannot_be_deleted(): void
    {
        $this->expectException(ValidationException::class);

        $this->aPublishedNotice()->delete();
    }

    /**
     * ⭐ কিন্তু যে খসড়া কেউ দেখেনি, সেটা মোছা যায়।
     *
     * ── ⚠️ কেন এই দাবিটা ছাড়া উপরেরটা কিছুই মাপে না ──────────────────
     * ⓘ পাহারাটা যদি **সব** নোটিশের মোছা আটকাত, তবু উপরেরটা সবুজ থাকত।
     * ⛔ আর তখন একটা ভুল করে লেখা খসড়া চিরকাল তালিকায় বসে থাকত, আর
     * সরানোর কোনো পথই থাকত না।
     *
     * ⓘ নিয়মটা *"মোছা নিষেধ"* নয়, *"মানুষ যা দেখে ফেলেছে তা মোছা
     * নিষেধ"* — আর তফাতটা এই দাবিটাই ধরে রাখে।
     */
    public function test_a_draft_nobody_saw_can_still_be_deleted(): void
    {
        $draft = app(NoticeLifecycle::class)->draft(['title' => 'ভুল করে', 'body' => 'লেখা']);

        $draft->delete();

        $this->assertNull(Notice::query()->find($draft->id), 'কেউ দেখেনি এমন খসড়াও মোছা যাচ্ছে না।');
    }

    /** প্রকাশিত একটা নোটিশ — পুরো পথ হেঁটে। */
    private function aPublishedNotice(): Notice
    {
        $life = app(NoticeLifecycle::class);

        $notice = $life->draft(['title' => 'বাকি বন্ধ', 'body' => 'আজ থেকে']);
        $notice = $life->submit($notice);
        $notice = $life->approve($notice);

        return $life->publish($notice);
    }
}
