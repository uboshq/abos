<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাগজটার কী হলো, সেটা কেউ কোনোদিন জানত না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ঘণ্টা আগে থেকেই ছিল, আর "আপনার তিনটা সিদ্ধান্ত বাকি"ও দেখাত। কিন্তু
 * ওটা গণনা করা তথ্য — অর্থাৎ যা **এখনো ঝুলে আছে** কেবল সেটাই।
 *
 * ফলে সবচেয়ে দরকারি খবরটাই যেত না: যিনি খরচের দাবি তুলেছেন, তাঁর দাবি
 * অনুমোদিত হলো না বাতিল হলো। সিদ্ধান্তের মুহূর্তেই ওটা "অপেক্ষমাণ"
 * তালিকা থেকে হারিয়ে যেত, আর তারপর কোথাও থাকত না।
 *
 * বাস্তবে মানুষ তখন ফোন করেন। অনুমোদনের ব্যবস্থা কাগজে থাকে, আর কাজে
 * চলে ফোনে।
 */
class NobodyWasEverToldTheAnswerTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $notifications;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->manager = User::query()->where('id', '!=', $this->owner->id)->firstOrFail();

        $this->actingAs($this->owner);
        $this->notifications = app(NotificationService::class);
    }

    public function test_a_message_reaches_the_person_it_is_for(): void
    {
        $this->notifications->send($this->manager, 'approval.approved', 'Approved: expense');

        $this->assertSame(1, $this->notifications->unreadCount($this->manager));
        $this->assertSame(0, $this->notifications->unreadCount($this->owner));
    }

    /**
     * নিজের করা কাজের খবর নিজের কাছে যায় না।
     *
     * নিজের ছোট খরচ নিজে অনুমোদন করলে (self_limit-এর নিচে) নিজেই
     * নিজেকে "আপনার দাবি অনুমোদিত" পাঠাত। ওরকম খবর ঘণ্টায় বসে থাকে,
     * কিছু জানায় না, শুধু সংখ্যাটা বাড়ায় — আর যে সংখ্যা কিছু জানায়
     * না, মানুষ সেটা দেখা বন্ধ করে দেন।
     */
    public function test_a_person_is_never_told_about_their_own_doing(): void
    {
        $sent = $this->notifications->send($this->owner, 'approval.approved', 'Approved: expense');

        $this->assertNull($sent);
        $this->assertSame(0, $this->notifications->unreadCount($this->owner));
    }

    public function test_reading_a_message_takes_it_off_the_bell(): void
    {
        $this->notifications->send($this->manager, 'approval.rejected', 'Turned down');

        $note = Notification::query()->for($this->manager->id)->firstOrFail();

        $this->assertTrue($this->notifications->markRead($note, $this->manager));
        $this->assertSame(0, $this->notifications->unreadCount($this->manager));
    }

    /**
     * অন্যের খবর পড়া হিসেবে বসানো যায় না।
     *
     * নাহলে একটা লিংকে ক্লিক করেই অন্যের ঘণ্টা খালি করে দেওয়া যেত, আর
     * তিনি কোনোদিন জানতেন না তাঁর দাবিটা বাতিল হয়েছিল।
     */
    public function test_one_person_cannot_clear_another_persons_bell(): void
    {
        $this->notifications->send($this->manager, 'approval.rejected', 'Turned down');

        $note = Notification::query()->for($this->manager->id)->firstOrFail();

        $this->assertFalse($this->notifications->markRead($note, $this->owner));
        $this->assertSame(1, $this->notifications->unreadCount($this->manager));
    }

    public function test_opening_someone_elses_message_over_http_is_refused(): void
    {
        $this->notifications->send($this->manager, 'approval.rejected', 'Turned down');

        $note = Notification::query()->for($this->manager->id)->firstOrFail();

        $this->actingAs($this->owner)
            ->get(route('notifications.open', $note))
            ->assertForbidden();
    }

    public function test_opening_a_message_sends_the_reader_where_it_points(): void
    {
        $this->notifications->send(
            $this->manager, 'approval.approved', 'Approved', null, '/accounts/cheques',
        );

        $note = Notification::query()->for($this->manager->id)->firstOrFail();

        $this->actingAs($this->manager)
            ->get(route('notifications.open', $note))
            ->assertRedirect('/accounts/cheques');

        $this->assertSame(0, $this->notifications->unreadCount($this->manager));
    }

    /** একই ছকে একজন দুইবার থাকলেও খবর একটাই। */
    public function test_the_same_person_listed_twice_still_gets_one_message(): void
    {
        $this->notifications->sendMany(
            [$this->manager->id, $this->manager->id],
            'approval.pending',
            'Waiting for you',
        );

        $this->assertSame(1, $this->notifications->unreadCount($this->manager));
    }

    public function test_mark_all_read_clears_the_bell(): void
    {
        foreach (['one', 'two', 'three'] as $title) {
            $this->notifications->send($this->manager, 'approval.approved', $title);
        }

        $this->assertSame(3, $this->notifications->unreadCount($this->manager));

        $this->notifications->markAllRead($this->manager);

        $this->assertSame(0, $this->notifications->unreadCount($this->manager));
    }
}
