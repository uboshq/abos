<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Notifications\Channels\EmailChannel;
use App\Core\Notifications\DeliveryResult;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NotificationJob;
use App\Models\NotificationSuppression;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Modules\Notification\Support\ScriptedChannel;
use Tests\TestCase;

/**
 * ⛔ বন্ধ করা কর্মী আর কোম্পানি থেকে সরানো মানুষ ইমেইল, SMS, পুশ পান না — Notification লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬
 * ([[DeliveryService::stillWorksHere()]])।
 *
 * ⓘ নিয়মের প্রাপক বাছাই সক্রিয়দের নিত, কিন্তু সরাসরি পাঠানো খবর (সইয়ের ফল যিনি চেয়েছিলেন তাঁকে) চাকরি ছাড়া মানুষের ইমেইলে যেত —
 * কাগজের নাম আর মন্তব্যসহ।
 */
final class AnInactivePersonGetsNoMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_switched_off_or_removed_person_gets_nothing_outside_and_an_active_one_does(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        config(['mail.default' => 'smtp']);
        $mail = new ScriptedChannel([DeliveryResult::sent('ok'), DeliveryResult::sent('ok'), DeliveryResult::sent('ok')]);
        $this->app->instance(EmailChannel::class, $mail);

        $person = function (bool $active, bool $member) use ($company): User {
            $user = User::factory()->create(['is_active' => $active, 'current_company_id' => $company->id]);
            $user->companies()->attach($company->id, ['is_active' => $member]);

            return $user->fresh();
        };

        // ⓘ তিনজনই আগে বসে — বন্ধ সদস্যদের তালিকা কোম্পানি ধরে এক মিনিট মনে রাখা হয়
        $active = $person(true, true);
        $leavers = ['বন্ধ করা' => $person(false, true), 'কোম্পানি থেকে সরানো' => $person(true, false)];

        // ⓘ সক্রিয় — আগের মতোই চিঠি যায়
        app(NotificationService::class)->send($active, 'approval.rejected', 'সক্রিয়ের খবর');
        $this->assertSame(1, $mail->calls, 'দৃশ্যটাই বানানো যায়নি — সক্রিয় মানুষও চিঠি পাননি');

        foreach ($leavers as $who => $gone) {
            app(NotificationService::class)->send($gone, 'approval.rejected', 'চলে যাওয়া মানুষের খবর');

            $this->assertSame(1, $mail->calls, "⛔ {$who} মানুষের কাছে ইমেইল গেল");
            $this->assertSame(0, NotificationJob::query()->withoutGlobalScopes()->where('user_id', $gone->id)->count(), "⛔ {$who} মানুষের পাঠানোর সারি বসল");
            $this->assertTrue(NotificationSuppression::query()->withoutGlobalScopes()->where('user_id', $gone->id)->where('reason', 'inactive')->exists(),
                "{$who}: কেন যায়নি তা লেখা নেই");
        }
    }
}
