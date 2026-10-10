<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Notifications\Channels\EmailChannel;
use App\Core\Notifications\DeliveryResult;
use App\Core\Notifications\DeliveryService;
use App\Core\Notifications\DigestService;
use App\Core\Services\NotificationService;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NotificationDigest;
use App\Models\NotificationJob;
use App\Models\NotificationPreference;
use App\Models\NotificationSuppression;
use App\Models\User;
use App\Notifications\NewsDigestByMail;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as Notices;
use Tests\Feature\Modules\Notification\Support\ScriptedChannel;
use Tests\TestCase;

/**
 * ⭐ নীরব সময় সাধারণ খবর পিছায়, জরুরি কখনো নয় — আর সারসংক্ষেপ, পছন্দ (মালিকের স্পেক §১৪; cloud task-এর বাধ্যতামূলক পরীক্ষা;
 * ধাপ ৩)।
 *
 * ⭐ দাবি:
 *   · নীরব সময়ে সাধারণ খবরের চিঠি যায় না — সময় শেষে যায়; জরুরিটা সাথে সাথে যায়; ঘণ্টায় দুইটাই সাথে সাথে
 *   · রাত পেরোনো নীরব সময় (২২:০০–০৭:০০) ঠিক গোনা হয়, মানুষটার সময় অঞ্চলে
 *   · নিজের সারি না থাকলে কোম্পানির স্বাভাবিক নীরব সময় খাটে
 *   · "দিনে একবার" বাছলে সাধারণ চিঠি ধরে রাখা হয় আর বেছে নেওয়া ঘণ্টায় একটা সারসংক্ষেপে যায় — একবারই; জরুরি ধরে রাখা হয় না
 *   · নিজের বন্ধ করা মাধ্যম বন্ধ; চুপ করা শ্রেণি বাইরে চুপ — জরুরি ছাড়া; কারণগুলো লেখা থাকে
 *   · নিজের পছন্দ নিজের সেটিংসের পাতা থেকে বসে
 */
final class QuietHoursHoldTheNormalButNeverTheCriticalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    private ScriptedChannel $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->actingAs($this->owner);

        config(['mail.default' => 'smtp']);
        $this->mail = new ScriptedChannel([DeliveryResult::sent('ok')]);
        $this->app->instance(EmailChannel::class, $this->mail);
    }

    public function test_quiet_hours_defer_a_normal_notice_but_not_a_critical_one(): void
    {
        $this->prefer(['quiet_enabled' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00', 'timezone' => 'Asia/Dhaka']);
        $this->at('2026-10-10 23:30');

        $normal = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'রাতের সাধারণ খবর');
        $critical = app(NotificationService::class)->send($this->clerk, 'backup.failed', 'রাতের ব্যাকআপ ব্যর্থ');

        $this->assertNotNull($normal, '⛔ নীরব সময়ে ঘণ্টার খবরও আটকাল');
        $this->assertNotNull($critical);
        $this->assertSame(1, $this->mail->calls, '⛔ নীরব সময়ে সাধারণ চিঠি গেল, নয়তো জরুরিটা গেল না');

        $held = NotificationJob::query()->where('notification_id', $normal->id)->firstOrFail();
        $this->assertSame(NotificationJob::QUEUED, $held->status);
        $this->assertSame('2026-10-11 07:00', $held->next_attempt_at->copy()->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'), '⛔ নীরব সময়ের শেষ ভুল গোনা');
        $this->assertSame(NotificationJob::SENT, NotificationJob::query()->where('notification_id', $critical->id)->value('status'), '⛔ জরুরি খবর নীরব সময়ে আটকাল');
        $this->assertSame(1, NotificationSuppression::query()->where('reason', 'quiet_hours')->where('user_id', $this->clerk->id)->count());

        // ⓘ ভোর ছয়টায় এখনো নীরব — সাতটার পরে যায়
        $this->at('2026-10-11 06:30');
        app(DeliveryService::class)->retryDue();
        $this->assertSame(1, $this->mail->calls, '⛔ নীরব সময় শেষ হওয়ার আগেই চিঠি গেল');

        $this->at('2026-10-11 07:01');
        app(DeliveryService::class)->retryDue();
        $this->assertSame(2, $this->mail->calls, '⛔ নীরব সময় শেষে পিছানো চিঠি গেল না');
        $this->assertSame(NotificationJob::SENT, $held->fresh()->status);
    }

    public function test_the_company_default_quiet_hours_apply_to_anyone_without_their_own(): void
    {
        app(SettingsService::class)->set('notification.quiet_start', '13:00');
        app(SettingsService::class)->set('notification.quiet_end', '14:00');
        app(SettingsService::class)->flush();
        $this->at('2026-10-10 13:15');

        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'দুপুরের খবর');
        $this->assertSame(0, $this->mail->calls, '⛔ কোম্পানির স্বাভাবিক নীরব সময় খাটল না');

        // ⓘ নিজের সারি থাকলে নিজেরটাই — এখানে নীরব সময় বন্ধ
        $this->prefer(['quiet_enabled' => false]);
        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'আরেকটা দুপুরের খবর');
        $this->assertSame(1, $this->mail->calls, '⛔ নিজের পছন্দের বদলে কোম্পানিরটা খাটল');
    }

    public function test_a_daily_digest_holds_normal_mail_and_sends_it_once_at_the_chosen_hour(): void
    {
        Notices::fake();
        $this->prefer(['frequency' => 'daily', 'digest_hour' => 9, 'timezone' => 'Asia/Dhaka']);
        $this->at('2026-10-10 07:00');

        $a = app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'প্রথম সাধারণ খবর');
        $b = app(NotificationService::class)->send($this->clerk, 'report_ready', 'রিপোর্ট তৈরি');
        app(NotificationService::class)->send($this->clerk, 'backup.failed', 'জরুরি খবর');

        $this->assertSame(1, $this->mail->calls, '⛔ জরুরি খবর সারসংক্ষেপে আটকাল, নয়তো সাধারণটা সাথে সাথে গেল');
        $this->assertSame(2, NotificationJob::query()->where('status', NotificationJob::HELD)->count());

        $this->at('2026-10-10 08:30');
        app(DigestService::class)->run(CarbonImmutable::now());
        Notices::assertNothingSentTo($this->clerk);

        $this->at('2026-10-10 09:05');
        app(DigestService::class)->run(CarbonImmutable::now());
        Notices::assertSentToTimes($this->clerk, NewsDigestByMail::class, 1);

        $digest = NotificationDigest::query()->where('user_id', $this->clerk->id)->firstOrFail();
        $this->assertSame('sent', $digest->status);
        $this->assertSame(2, (int) $digest->items);
        $this->assertSame(2, NotificationJob::query()->where('digest_id', $digest->id)->where('status', NotificationJob::SENT)->count());
        $this->assertSame(0, NotificationJob::query()->where('status', NotificationJob::HELD)->count());

        // ⓘ একই দিনে আবার চালালে দ্বিতীয় সারসংক্ষেপ নয়
        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'পরে আসা খবর');
        $this->at('2026-10-10 10:05');
        app(DigestService::class)->run(CarbonImmutable::now());
        Notices::assertSentToTimes($this->clerk, NewsDigestByMail::class, 1);
        $this->assertSame(1, NotificationJob::query()->where('status', NotificationJob::HELD)->count(), 'পরে আসা খবর পরের দিনের জন্য ধরা থাকে');
        $this->assertNotNull($a);
        $this->assertNotNull($b);
    }

    public function test_a_channel_switched_off_stays_off_and_a_muted_category_is_quiet_except_when_critical(): void
    {
        $this->prefer(['channels' => ['email' => false]]);
        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'চিঠি বন্ধ');
        app(NotificationService::class)->send($this->clerk, 'backup.failed', 'জরুরি, তবু চিঠি বন্ধ');
        $this->assertSame(0, $this->mail->calls, '⛔ নিজের বন্ধ করা মাধ্যমে চিঠি গেল');
        $this->assertSame(2, NotificationSuppression::query()->where('reason', 'preference')->count());

        $this->prefer(['channels' => null, 'muted_categories' => ['approval', 'system']]);
        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'চুপ করা শ্রেণি');
        $this->assertSame(0, $this->mail->calls, '⛔ চুপ করা শ্রেণির চিঠি গেল');
        $this->assertSame(1, NotificationSuppression::query()->where('reason', 'muted')->count());

        app(NotificationService::class)->send($this->clerk, 'backup.failed', 'জরুরি — চুপ হয় না');
        $this->assertSame(1, $this->mail->calls, '⛔ জরুরি খবর চুপ করা শ্রেণিতে আটকাল');
    }

    public function test_a_daily_limit_holds_back_extra_notices_but_never_a_critical_one(): void
    {
        app(SettingsService::class)->set('notification.daily_limit', 2);
        app(SettingsService::class)->flush();

        foreach (['প্রথম', 'দ্বিতীয়', 'তৃতীয়'] as $title) {
            $this->assertNotNull(app(NotificationService::class)->send($this->clerk, 'approval.rejected', $title), '⛔ সীমার জন্য ঘণ্টার খবরও আটকাল');
        }
        $this->assertSame(2, $this->mail->calls, '⛔ দিনের সীমা পেরিয়েও চিঠি গেল');
        $this->assertSame(1, NotificationSuppression::query()->where('reason', 'limit')->count());

        app(NotificationService::class)->send($this->clerk, 'backup.failed', 'জরুরি — সীমা মানে না');
        $this->assertSame(3, $this->mail->calls, '⛔ দিনের সীমায় জরুরি খবর আটকাল');

        // ⓘ পরের দিন আবার
        $this->travel(1)->days();
        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'পরের দিন');
        $this->assertSame(4, $this->mail->calls);
    }

    public function test_people_set_their_own_preferences_on_their_settings_page(): void
    {
        $this->actingAs($this->clerk)->get(route('notifications.settings'))->assertOk()->assertSee('data-notify-preferences', false);

        $this->actingAs($this->clerk)->put(route('notifications.settings.update'), [
            'kinds' => [], 'mailed' => [],
            'pref' => [
                'channels' => ['email', 'web_push'], 'muted' => ['update'], 'frequency' => 'weekly', 'digest_hour' => 18, 'digest_day' => 4,
                'quiet_enabled' => '1', 'quiet_start' => '22:30', 'quiet_end' => '06:30', 'timezone' => 'Asia/Dhaka',
            ],
        ])->assertRedirect();

        $pref = NotificationPreference::query()->where('user_id', $this->clerk->id)->firstOrFail();
        $this->assertFalse($pref->allowsChannel('mobile_push'));
        $this->assertTrue($pref->allowsChannel('email'));
        $this->assertTrue($pref->mutes('update'));
        $this->assertSame('weekly', $pref->frequency);
        $this->assertSame(18, $pref->digest_hour);
        $this->assertTrue($pref->quiet_enabled);
        $this->assertSame('22:30', substr((string) $pref->quiet_start, 0, 5));

        // ⓘ ভুল সময় বা অচেনা ঘনত্ব চলে না
        $this->actingAs($this->clerk)->put(route('notifications.settings.update'), ['pref' => ['frequency' => 'hourly']])
            ->assertSessionHasErrors('pref.frequency');
    }

    /** @param  array<string, mixed>  $values */
    private function prefer(array $values): void
    {
        NotificationPreference::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'user_id' => $this->clerk->id],
            $values,
        );
    }

    /** ঢাকার ঘড়িতে একটা মুহূর্তে যাওয়া */
    private function at(string $dhaka): void
    {
        $this->travelTo(CarbonImmutable::parse($dhaka, 'Asia/Dhaka'));
    }
}
