<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Notifications\Channels\EmailChannel;
use App\Core\Notifications\DeliveryResult;
use App\Core\Notifications\DigestService;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NotificationJob;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NewsDigestByMail;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as Notices;
use Tests\Feature\Modules\Notification\Support\ScriptedChannel;
use Tests\TestCase;

/**
 * ⛔ "সাপ্তাহিক, রবিবার ৯টা" সারসংক্ষেপ রবিবার ৯টায় যায় — Notification লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[DigestService]])।
 *
 * ⓘ ঘরে রবিবার ০ (পর্দার প্রথম বিকল্প, আর স্বাভাবিক), কিন্তু সপ্তাহের চাবি ISO — সোমবারে শুরু। সোমবার (১) "রবিবারের পরে" গোনা হত,
 * তাই চিঠি সোমবার রাত ১২টার পরেই যেত আর সপ্তাহটা "পাঠানো" হয়ে যেত — রবিবারে কিছুই না।
 */
final class ASundayDigestGoesOnSundayTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_weekly_sunday_digest_waits_past_monday_and_goes_on_sunday(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'Asia/Dhaka'));   // বৃহস্পতিবার
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);

        config(['mail.default' => 'smtp']);
        $this->app->instance(EmailChannel::class, new ScriptedChannel([DeliveryResult::sent('ok')]));
        Notices::fake();

        NotificationPreference::query()->updateOrCreate(['company_id' => $company->id, 'user_id' => $clerk->id],
            ['frequency' => 'weekly', 'digest_day' => 0, 'digest_hour' => 9, 'timezone' => 'Asia/Dhaka']);

        app(NotificationService::class)->send($clerk, 'approval.rejected', 'সপ্তাহের খবর');
        $this->assertSame(1, NotificationJob::query()->where('status', NotificationJob::HELD)->count(), 'দৃশ্যটাই বানানো যায়নি — চিঠি ধরা হয়নি');

        // ⓘ রবিবার ৯টার আগে — অপেক্ষা
        $this->travelTo(CarbonImmutable::parse('2026-10-18 08:30', 'Asia/Dhaka'));
        app(DigestService::class)->run(CarbonImmutable::now());
        Notices::assertNothingSentTo($clerk);

        // ⭐ রবিবার ৯টা — যায়
        $this->travelTo(CarbonImmutable::parse('2026-10-18 09:05', 'Asia/Dhaka'));
        app(DigestService::class)->run(CarbonImmutable::now());
        Notices::assertSentToTimes($clerk, NewsDigestByMail::class, 1);

        // ⛔ পরের সপ্তাহের খবর সোমবার রাত ১২টার পরেই নয় — পরের রবিবার
        app(NotificationService::class)->send($clerk, 'approval.rejected', 'পরের সপ্তাহের খবর');
        $this->travelTo(CarbonImmutable::parse('2026-10-19 00:30', 'Asia/Dhaka'));
        app(DigestService::class)->run(CarbonImmutable::now());
        Notices::assertSentToTimes($clerk, NewsDigestByMail::class, 1);

        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:05', 'Asia/Dhaka'));
        app(DigestService::class)->run(CarbonImmutable::now());
        Notices::assertSentToTimes($clerk, NewsDigestByMail::class, 2);
    }
}
