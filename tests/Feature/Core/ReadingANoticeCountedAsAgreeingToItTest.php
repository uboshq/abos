<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NoticeAcknowledgement;
use App\Core\Services\NoticeAudience;
use App\Core\Services\NoticeBoard;
use App\Core\Services\NoticeLifecycle;
use App\Core\Services\NoticeScheduler;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Core\Support\NoticeStanding;
use App\Core\Support\NoticeStatus;
use App\Models\Company;
use App\Models\Notice;
use App\Models\NoticeReminder;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * পড়া মানেই মেনে নেওয়া ধরা হত।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ১৮ ও ১৯ ───────────────
 * পাঁচটা অবস্থা, একটা সময়সীমা, আর তিন ধাপের তাগাদা।
 *
 * ── ⚠️ কেন পড়া আর মেনে নেওয়া আলাদা ─────────────────────────────────
 * ⓘ পড়া বসে যায় পাতা খুললেই। মেনে নেওয়ায় মানুষকে একটা বোতামে চাপতে
 * হয়। ⛔ এক ঘরে রাখলে *"নতুন নীতিমালা কে মেনেছেন"* প্রশ্নের উত্তর হত
 * *"পাতাটা কে খুলেছেন"* — আর সেটা অডিটে কোনো উত্তরই নয়।
 */
final class ReadingANoticeCountedAsAgreeingToItTest extends TestCase
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

        /* ⓘ সইয়ের নিয়ম এখানে মাপা হচ্ছে, লেখক-অনুমোদকের নয় */
        $settings = app(SettingsService::class);
        $settings->set('notice.creator_cannot_approve', false);
        $settings->flush();
    }

    /**
     * ⛔ পড়লেই মেনে নেওয়া হয় না।
     *
     * ── ⚠️ এই ফাইলের সবচেয়ে জরুরি দাবি ─────────────────────────────
     * ⓘ দুইটা এক ঘরে থাকলে একটা কোম্পানি বলতে পারত *"আমাদের ১০০%
     * কর্মী নতুন নীতিমালা মেনেছেন"* — অথচ তাঁরা কেবল পাতাটা খুলেছিলেন।
     */
    public function test_reading_is_not_agreeing(): void
    {
        $notice = $this->published(ack: true);

        app(NoticeBoard::class)->markRead($notice, $this->me);

        $this->assertSame(
            NoticeStanding::READ,
            app(NoticeAcknowledgement::class)->standingOf($notice, $this->me),
            'পড়া মাত্রই সই বসে গেছে।',
        );
    }

    /**
     * ⭐ আর সই দিলে অবস্থা বদলায়।
     *
     * ⓘ উপরেরটা একা থাকলে **সই কোনোদিন বসে না** এমন যন্ত্রও সবুজ পেত।
     */
    public function test_signing_does_change_the_standing(): void
    {
        $notice = $this->published(ack: true);

        app(NoticeAcknowledgement::class)->sign($notice, $this->me);

        $this->assertSame(
            NoticeStanding::ACKNOWLEDGED,
            app(NoticeAcknowledgement::class)->standingOf($notice, $this->me),
            'সই দেওয়ার পরেও অবস্থা বদলায়নি।',
        );
    }

    /**
     * ⓘ সই দিলে পড়াও হয়ে যায় — উল্টোটা নয়।
     *
     * ⚠️ কেউ পাতা না খুলেই তালিকা থেকে সই দিতে পারেন। ⛔ তখন *"পড়েছেন"*
     * সারিটা না থাকলে হিসাব অদ্ভুত দেখাত: মেনেছেন ২০ জন, পড়েছেন ১৮।
     */
    public function test_signing_also_counts_as_reading(): void
    {
        $notice = $this->published(ack: true);

        app(NoticeAcknowledgement::class)->sign($notice, $this->me);

        $this->assertSame(1, $notice->reads()->count(), 'সই বসল, কিন্তু পড়ার সারিটা নেই।');
    }

    /**
     * ⛔ যে নোটিশ সই চায় না, তাতে সই দেওয়া যায় না।
     */
    public function test_a_notice_that_asks_for_no_signature_takes_none(): void
    {
        $notice = $this->published(ack: false);

        $this->expectException(ValidationException::class);

        app(NoticeAcknowledgement::class)->sign($notice, $this->me);
    }

    /**
     * ⛔ আর লক্ষ্যের বাইরের কেউ সই দিতে পারেন না।
     *
     * ⓘ তাঁর সই হিসাবটা ঘোলা করত: *"২০ জনের মধ্যে ১৮ জন মেনেছেন"*
     * সংখ্যাটা তখন আর কিছুই বলে না।
     */
    public function test_someone_outside_the_target_cannot_sign(): void
    {
        $notice = $this->published(ack: true);

        app(NoticeAudience::class)->aimAt($notice, ['role:কেউ-নয়']);

        $this->expectException(ValidationException::class);

        app(NoticeAcknowledgement::class)->sign($notice->fresh(), $this->me);
    }

    /**
     * ⛔ সময় পেরোলে অবস্থা "সময় পেরিয়েছে"।
     */
    public function test_a_missed_deadline_shows_as_overdue(): void
    {
        $notice = $this->published(ack: true);
        $notice->forceFill(['ack_deadline' => now()->subDay()])->save();

        $this->assertSame(
            NoticeStanding::OVERDUE,
            app(NoticeAcknowledgement::class)->standingOf($notice->fresh(), $this->me),
            'সময় পেরোনোর পরেও অবস্থা বদলায়নি।',
        );
    }

    /**
     * ⭐ কিন্তু দেরিতে সই দিলে সেটাই শেষ কথা।
     *
     * ── ⚠️ কেন ক্রমটা মাপা হয় ───────────────────────────────────────
     * ⓘ যিনি মেনে ফেলেছেন তাঁর বেলায় সময় পেরোনোর প্রশ্নই ওঠে না। ⛔
     * উল্টো ক্রমে দেখলে দেরিতে সই দেওয়া মানুষটা চিরকাল *"সময় পেরিয়েছে"*
     * দেখাতেন, আর তাগাদাও পেতে থাকতেন।
     */
    public function test_a_late_signature_still_settles_it(): void
    {
        $notice = $this->published(ack: true);
        $notice->forceFill(['ack_deadline' => now()->subDay()])->save();

        app(NoticeAcknowledgement::class)->sign($notice->fresh(), $this->me);

        $this->assertSame(
            NoticeStanding::ACKNOWLEDGED,
            app(NoticeAcknowledgement::class)->standingOf($notice->fresh(), $this->me),
            'দেরিতে সই দেওয়ার পরেও "সময় পেরিয়েছে" দেখাচ্ছে।',
        );
    }

    /**
     * ⭐ যিনি পড়েছেন অথচ সই দেননি, তিনি তাগাদা পান।
     */
    public function test_whoever_read_and_did_not_sign_gets_chased(): void
    {
        $notice = $this->published(ack: true);
        $notice->forceFill(['published_at' => now()->subDays(2)])->save();

        app(NoticeBoard::class)->markRead($notice, $this->me);

        $sent = app(NoticeScheduler::class)->remindWhoHasNotSigned();

        $this->assertGreaterThan(0, $sent, 'একটাও তাগাদা গেল না।');
    }

    /**
     * ⛔ আর একই তাগাদা দুইবার যায় না।
     *
     * ── ⚠️ কেন এটা আলাদা করে মাপা ───────────────────────────────────
     * ⓘ সময়ের কাজ দুইবার চলে, আর সেটা স্বাভাবিক: সার্ভার রিস্টার্ট,
     * কিউ পুনরায় চেষ্টা, কিংবা দুইজন একসাথে হাতে চালানো। ⛔ দ্বিতীয়বার
     * চললে দ্বিতীয় ইমেল গেলে ভুলটা **মানুষ দেখে**, যন্ত্র নয়।
     */
    public function test_the_same_chase_never_goes_twice(): void
    {
        $notice = $this->published(ack: true);
        $notice->forceFill(['published_at' => now()->subDays(2)])->save();

        app(NoticeBoard::class)->markRead($notice, $this->me);

        $scheduler = app(NoticeScheduler::class);
        $scheduler->remindWhoHasNotSigned();
        $scheduler->remindWhoHasNotSigned();

        $this->assertSame(
            1,
            NoticeReminder::query()->where('notice_id', $notice->id)->where('user_id', $this->me->id)->count(),
            'একই তাগাদা দুইবার বসেছে।',
        );
    }

    /**
     * ⭐ সময় হলে নির্ধারিত নোটিশ নিজে থেকেই প্রকাশ হয়।
     */
    public function test_a_scheduled_notice_publishes_itself_when_due(): void
    {
        $life = app(NoticeLifecycle::class);

        $notice = $life->draft(['title' => 'কাল সকালে', 'body' => 'লেখা']);
        $notice = $life->submit($notice);
        $notice = $life->approve($notice);
        $notice = $life->schedule($notice, now()->addDay());

        /* ⓘ সময়টা পিছিয়ে দেওয়া — ঘড়ি এগোনোর বদলে */
        $notice->forceFill(['starts_on' => now()->subDay()->toDateString()])->save();

        app(NoticeScheduler::class)->publishWhatIsDue();

        $this->assertSame(NoticeStatus::PUBLISHED, $notice->fresh()->status,
            'সময় হয়ে গেলেও নোটিশটা প্রকাশ হয়নি।');
    }

    /**
     * ⛔ আর সময়ের আগে হয় না।
     *
     * ⓘ উপরেরটা একা থাকলে *"সব নির্ধারিত নোটিশ এখনই প্রকাশ করো"*
     * একটা যন্ত্রও সবুজ পেত — আর তখন সময় ধরে প্রকাশের কোনো মানেই
     * থাকত না।
     */
    public function test_a_scheduled_notice_waits_for_its_day(): void
    {
        $life = app(NoticeLifecycle::class);

        $notice = $life->draft(['title' => 'আগামী সপ্তাহে', 'body' => 'লেখা']);
        $notice = $life->submit($notice);
        $notice = $life->approve($notice);
        $notice = $life->schedule($notice, now()->addWeek());

        app(NoticeScheduler::class)->publishWhatIsDue();

        $this->assertSame(NoticeStatus::SCHEDULED, $notice->fresh()->status,
            'সময়ের আগেই নোটিশটা প্রকাশ হয়ে গেছে।');
    }

    /**
     * ⭐ আর মেয়াদ ফুরালে নিজে থেকেই নামে।
     */
    public function test_an_expired_notice_comes_down_on_its_own(): void
    {
        $notice = $this->published(ack: false);
        $notice->forceFill(['expires_at' => now()->subHour()])->save();

        app(NoticeScheduler::class)->expireWhatIsOver();

        $this->assertSame(NoticeStatus::EXPIRED, $notice->fresh()->status,
            'মেয়াদ পেরোনোর পরেও নোটিশটা প্রকাশিত।');
    }

    /** একটা প্রকাশিত নোটিশ। */
    private function published(bool $ack): Notice
    {
        $life = app(NoticeLifecycle::class);

        $notice = $life->draft([
            'title' => 'নতুন নীতিমালা',
            'body' => 'আজ থেকে',
            'priority' => NoticePriority::IMPORTANT->value,
            'ack_required' => $ack,
        ]);

        $notice = $life->submit($notice);
        $notice = $life->approve($notice);

        return $life->publish($notice);
    }
}
