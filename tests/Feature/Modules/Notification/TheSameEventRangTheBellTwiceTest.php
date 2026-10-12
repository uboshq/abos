<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationEvent;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১ — মূল ইঞ্জিন (মালিকের স্পেক §১০, §১১, ১০ অক্টোবর ২০২৬)।
 *
 * ⭐ দাবি:
 *   · একই চাবিতে একই ঘটনা দুইবার → একটাই ঘটনা, প্রত্যেকে একটাই খবর (ক্রন দুইবার চললেও)
 *   · চাবি ছাড়া আগের মতো প্রতিবার নতুন খবর; ভিন্ন চাবি → ভিন্ন খবর
 *   · একজনের পড়া, দেখা বা আর্কাইভ অন্যজনের খবর ছোঁয় না
 *   · গুরুত্ব আর ধরন ধরন থেকে আসে; ডাকা জায়গা গুরুত্ব দিলে সেটাই
 *   · ব্যবসার লেনদেন ফিরে গেলে খবরও যায় না (transactional outbox)
 */
final class TheSameEventRangTheBellTwiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_the_same_event_twice_is_one_notification(): void
    {
        $notify = app(NotificationService::class);

        $first = $notify->send($this->clerk, 'approval.rejected', 'ফেরত এল', 'কারণ', null, key: 'approval:9:rejected');
        $again = $notify->send($this->clerk, 'approval.rejected', 'ফেরত এল', 'কারণ', null, key: 'approval:9:rejected');

        $this->assertNotNull($first);
        $this->assertNull($again, '⛔ একই ঘটনা দুইবার খবর হলো');
        $this->assertSame(1, NotificationEvent::query()->where('idempotency_key', 'approval:9:rejected')->count());
        $this->assertSame(1, Notification::query()->where('user_id', $this->clerk->id)->count());

        // ⓘ একই ঘটনা অনেকজনকে, দুইবার — প্রত্যেকে একবার
        $notify->sendMany([$this->clerk, $this->owner->id], 'backup.failed', 'ব্যাকআপ ব্যর্থ', null, null, key: 'backup:1:failed:0');
        $notify->sendMany([$this->clerk, $this->owner->id], 'backup.failed', 'ব্যাকআপ ব্যর্থ', null, null, key: 'backup:1:failed:0');

        $this->assertSame(1, NotificationEvent::query()->where('idempotency_key', 'backup:1:failed:0')->count());
        $this->assertSame(1, Notification::query()->where('type', 'backup.failed')->where('user_id', $this->clerk->id)->count());
        $this->assertSame(1, NotificationEvent::query()->where('idempotency_key', 'backup:1:failed:0')->value('recipients'),
            'ⓘ মালিক নিজের কাজের খবর পান না — প্রাপক কেবল একজন');
    }

    public function test_without_a_key_or_with_another_key_a_new_notification_goes(): void
    {
        $notify = app(NotificationService::class);

        $notify->send($this->clerk, 'report_ready', 'রিপোর্ট');
        $notify->send($this->clerk, 'report_ready', 'রিপোর্ট');
        $notify->send($this->clerk, 'report_ready', 'রিপোর্ট', key: 'report:run:1');
        $notify->send($this->clerk, 'report_ready', 'রিপোর্ট', key: 'report:run:2');

        $this->assertSame(4, Notification::query()->where('user_id', $this->clerk->id)->count());
    }

    public function test_read_seen_and_archive_belong_to_each_person(): void
    {
        $sent = app(NotificationService::class)->sendMany([$this->clerk, $this->otherUser()], 'approval.reminder', 'সময় ফুরোচ্ছে', key: 'approval:5:reminder:1');
        [$mine, $theirs] = [$sent[0], $sent[1]];

        app(NotificationService::class)->markRead($mine, $this->clerk);
        app(NotificationService::class)->act($this->clerk, [(int) $mine->id], 'archive');

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNotNull($mine->fresh()->seen_at, 'পড়া মানে দেখাও');
        $this->assertNotNull($mine->fresh()->archived_at);
        $this->assertNull($theirs->fresh()->read_at, '⛔ একজনের পড়া অন্যজনের খবরে বসল');
        $this->assertNull($theirs->fresh()->archived_at);
        $this->assertSame(1, app(NotificationService::class)->unreadCount($theirs->user_id));

        // ⛔ অন্যের খবর পড়া বা আর্কাইভ করা যায় না
        $this->assertFalse(app(NotificationService::class)->markRead($theirs, $this->clerk));
        $this->assertSame(0, app(NotificationService::class)->act($this->clerk, [(int) $theirs->id], 'archive'));
        $this->assertNull($theirs->fresh()->archived_at);
    }

    public function test_priority_and_kind_come_from_the_type_unless_the_caller_says(): void
    {
        $notify = app(NotificationService::class);

        $backup = $notify->send($this->clerk, 'backup.failed', 'ব্যাকআপ');
        $report = $notify->send($this->clerk, 'report_ready', 'রিপোর্ট');
        $loud = $notify->send($this->clerk, 'report_ready', 'জরুরি রিপোর্ট', priority: 'high');

        $this->assertSame(['critical', 'system', 'backup'], [$backup->priority, $backup->category, $backup->module]);
        $this->assertSame(['low', 'update'], [$report->priority, $report->category]);
        $this->assertSame('high', $loud->priority);
    }

    public function test_a_business_transaction_that_rolls_back_takes_its_notification_with_it(): void
    {
        try {
            DB::transaction(function (): void {
                app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'ফেরত', key: 'approval:77:rejected');

                throw new \RuntimeException('কাগজটা সংরক্ষণ হলো না');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, NotificationEvent::query()->where('idempotency_key', 'approval:77:rejected')->count(), '⛔ ফিরে যাওয়া লেনদেনের খবর রয়ে গেল');
        $this->assertSame(0, Notification::query()->where('user_id', $this->clerk->id)->count());
    }

    private function otherUser(): User
    {
        return User::query()->whereNotIn('id', [$this->owner->id, $this->clerk->id])
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))->firstOrFail();
    }
}
