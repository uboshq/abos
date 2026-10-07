<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NoticeAudience;
use App\Core\Services\NoticeLifecycle;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Core\Support\NoticeStatus;
use App\Models\Company;
use App\Models\Notice;
use App\Models\NoticeTemplate;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * প্রতিটা নোটিশ শুরু হত সাদা পাতা থেকে।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ২০ ও ২৫ ───────────────
 * টেমপ্লেট ইঞ্জিন, আর প্রকাশের পর সম্পাদনা করলে নতুন সংস্করণ।
 *
 * ── ⚠️ ছাঁচে কেবল লেখা রাখলে অর্ধেক কাজ হত ─────────────────────────
 * ⓘ *"সার্ভার রক্ষণাবেক্ষণ"* নোটিশে প্রতিবার হাতে জরুরি বাছতে হলে
 * কোনো একদিন কেউ ভুলতেন, ⛔ আর ঐ নোটিশটা বারেই যেত না — অথচ ঠিক ওটাই
 * বারে সবচেয়ে বেশি দরকার।
 */
final class EveryNoticeWasWrittenFromABlankPageTest extends TestCase
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
     * ⭐ ছাঁচ থেকে লেখা নোটিশে অগ্রাধিকারও ছাঁচেরই।
     */
    public function test_a_template_carries_its_priority_across(): void
    {
        $notice = app(NoticeLifecycle::class)->fromTemplate($this->aTemplate());

        $this->assertSame(NoticePriority::CRITICAL, $notice->priority,
            'ছাঁচের অগ্রাধিকারটা নোটিশে আসেনি — মানুষকে আবার হাতে বাছতে হবে।');
    }

    /**
     * ⭐ আর লক্ষ্যটাও।
     *
     * ⓘ *"গুদামের সবাইকে"* প্রতিবার হাতে বাছা মানে একদিন কেউ সবাইকে
     * পাঠিয়ে দেবেন।
     */
    public function test_a_template_carries_its_target_across(): void
    {
        $notice = app(NoticeLifecycle::class)->fromTemplate($this->aTemplate());

        $this->assertSame(
            ['role:পরীক্ষার-দল'],
            $notice->targets()->pluck('match_key')->all(),
            'ছাঁচের লক্ষ্যটা নোটিশে বসেনি।',
        );
    }

    /**
     * ⛔ কিন্তু হাতে দেওয়া মান ছাঁচের উপরে বসে।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * ⓘ ছাঁচ একটা **শুরু**, একটা বেড়া নয়। ⛔ ছাঁচের মান উপরে বসালে
     * মানুষ শিরোনাম বদলানোর পরও পুরনো শিরোনামটাই বসত — নীরবে।
     */
    public function test_what_the_writer_types_beats_the_template(): void
    {
        $notice = app(NoticeLifecycle::class)
            ->fromTemplate($this->aTemplate(), ['title' => 'আমার নিজের শিরোনাম']);

        $this->assertSame('আমার নিজের শিরোনাম', (string) $notice->title,
            'ছাঁচটা মানুষের লেখা শিরোনামের উপরে বসে গেছে।');
    }

    /**
     * ⭐ প্রকাশিত নোটিশ বদলালে পুরনো লেখাটা থেকে যায়।
     *
     * ── ⚠️ কেন অডিট-খাতা যথেষ্ট নয় ─────────────────────────────────
     * ⓘ অডিট ঘর ধরে লেখে, সংস্করণ ধরে নয়। ⛔ প্রশ্নটা *"কে কী বদলেছে"*
     * নয় — প্রশ্নটা *"যিনি মঙ্গলবার পড়েছিলেন তিনি কোন লেখাটা
     * পড়েছিলেন"*।
     */
    public function test_editing_a_published_notice_keeps_the_old_words(): void
    {
        $notice = $this->published();

        app(NoticeLifecycle::class)->reviseInPlace($notice, ['body' => 'নতুন কথা'], 'তারিখ ভুল ছিল');

        $this->assertSame('পুরনো কথা', (string) $notice->versions()->first()?->body,
            'পুরনো লেখাটা কোথাও সংরক্ষিত হয়নি।');

        $this->assertSame('নতুন কথা', (string) $notice->fresh()->body,
            'নতুন লেখাটাই বসেনি।');
    }

    /**
     * ⭐ সংক্ষরণাগার থেকে ফেরা যায় — কিন্তু খসড়া হয়ে।
     *
     * ⓘ সরাসরি প্রকাশে ফেরার পথ রাখলে পুরনো একটা নোটিশ দুই বছর পরে
     * অনুমোদন ছাড়াই সবার চোখের সামনে চলে আসত।
     */
    public function test_an_archived_notice_comes_back_as_a_draft(): void
    {
        $life = app(NoticeLifecycle::class);

        $notice = $life->archive($this->published());

        $back = $life->restore($notice);

        $this->assertSame(NoticeStatus::DRAFT, $back->status, 'ফেরত এসে খসড়া হয়নি।');

        $this->assertNotNull($back->published_at,
            'প্রকাশের স্মৃতিটা মুছে গেছে — অথচ মানুষ ওটা পড়েছিল।');
    }

    /**
     * ⛔ আর যেটা সংরক্ষণাগারে নেই, সেটা ফেরানোর কিছু নেই।
     *
     * ⓘ উপরেরটা একা থাকলে *"যেকোনো নোটিশ খসড়া বানিয়ে দাও"* একটা
     * যন্ত্রও সবুজ পেত — ⛔ আর তখন একটা প্রকাশিত নোটিশ এক ক্লিকে
     * বার থেকে উধাও হয়ে যেত।
     */
    public function test_only_an_archived_notice_can_be_restored(): void
    {
        $this->expectException(ValidationException::class);

        app(NoticeLifecycle::class)->restore($this->published());
    }

    /** একটা ছাঁচ — জরুরি, আর একটা দলের দিকে তাক করা। */
    private function aTemplate(): NoticeTemplate
    {
        return NoticeTemplate::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'MAINT',
            'name_en' => 'System maintenance',
            'name_bn' => 'সার্ভার রক্ষণাবেক্ষণ',
            'title' => 'আজ রাতে সার্ভার বন্ধ',
            'body' => 'রাত ১১টা থেকে',
            'priority' => NoticePriority::CRITICAL->value,
            'audience' => ['role:পরীক্ষার-দল'],
            'is_active' => true,
        ]);
    }

    /** একটা প্রকাশিত নোটিশ। */
    private function published(): Notice
    {
        $life = app(NoticeLifecycle::class);

        $notice = $life->draft(['title' => 'ঘোষণা', 'body' => 'পুরনো কথা']);
        $notice = $life->submit($notice);
        $notice = $life->approve($notice);

        return $life->publish($notice);
    }
}
