<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Notifications\DeliveryService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationJob;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ⛔ মাঝপথে মরা পাঠানো চিরকাল আবার চেষ্টায় যেত (cb-র রিভিউ, cloud/notification-management মেশানোর আগে, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ চেষ্টার সংখ্যা বাড়ত কেবল পাঠানো ফিরলে, তাই আটকে যাওয়া সারি ১৫ মিনিট পরে একই সংখ্যায় ফিরত আর সীমা কখনো থামাত না।
 * এখন আটকানো = একটা চেষ্টা: সীমার আগে আবার চেষ্টা (সংখ্যা এক বেশি), সীমায় পৌঁছালে মৃত — আর তখন আর পাঠানো হয় না।
 */
final class AStuckSendCountsAsATryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_send_that_never_came_back_counts_as_a_try_and_dies_at_the_limit(): void
    {
        $this->seed(DemoSeeder::class);
        Queue::fake();
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $job = function (int $attempts) use ($company, $user): NotificationJob {
            $bell = Notification::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'user_id' => $user->id,
                'type' => 'backup.failed', 'title' => 'probe '.$attempts]);

            return NotificationJob::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'notification_id' => $bell->id,
                'user_id' => $user->id, 'channel' => 'email', 'status' => NotificationJob::PROCESSING, 'attempts' => $attempts,
                'max_attempts' => 5, 'claimed_at' => now()->subMinutes(30)]);
        };
        $early = $job(1);
        $last = $job(4);

        app(DeliveryService::class)->retryDue();

        $early->refresh();
        $last->refresh();
        $this->assertSame([NotificationJob::RETRYING, 2], [$early->status, $early->attempts], '⛔ আটকে যাওয়া চেষ্টাটা গোনা হলো না।');
        $this->assertSame([NotificationJob::DEAD, 5], [$last->status, $last->attempts], '⛔ সীমায় পৌঁছেও সারিটা আবার চেষ্টায় গেল — অন্তহীন।');
        $this->assertNotNull($last->dead_at);

        // ⓘ মৃত সারি আর পাঠানো হয় না
        Queue::assertNotPushed(\App\Jobs\DeliverNotification::class, fn ($pushed) => (int) ($pushed->jobId ?? 0) === (int) $last->id);
    }
}
