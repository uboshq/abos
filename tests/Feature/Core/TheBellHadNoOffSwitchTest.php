<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationChoice;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিজ্ঞপ্তির সেটিংস — কে কোন খবর পেতে চান।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক: *"বিজ্ঞপ্তির সেটিংস ta koro"* (মানচিত্রের §৩২)। খবর পাঠানোর
 * ব্যবস্থা ছিল, বন্ধ করার উপায় ছিল না। ⓘ যে খবর কেউ চান না সেটা বন্ধ করতে
 * না পারলে মানুষ সবগুলোই দেখা ছেড়ে দেন, আর তখন জরুরিটাও হারায়।
 *
 * ⭐ ছাঁকনিটা পাঠানোর সময়েই — সারিটা তৈরিই হয় না। ⛔ কেবল পর্দায় লুকালে
 * ঘণ্টার সংখ্যায় ওটা গোনা হত, আর "৩টা নতুন" দেখে খুলে কিছু না পাওয়ার
 * চেয়ে খারাপ কিছু নেই।
 */
final class TheBellHadNoOffSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /* ⓘ দ্বিতীয় একজন লাগে: নিজের কাজের খবর নিজে পান না (NotificationService)। */
        $this->clerk = User::query()->where('email', '!=', $this->owner->email)->firstOrFail();
    }

    public function test_a_kind_switched_off_is_never_written(): void
    {
        /*
         * ⚠️ সুইচটা **যিনি খবর পাবেন** তাঁর, পাঠানোর সময় যিনি লগইন করা
         * তাঁর নয় — প্রথমবার এই পরীক্ষাটাই ভুল দিকে লিখেছিলাম, আর ওটাই
         * এই লাইনের আসল ফাঁদ।
         */
        $this->actingAs($this->clerk);

        $this->put(route('notifications.settings.update'), ['kinds' => ['approval.approved', 'report_ready']])
            ->assertRedirect();

        $this->actingAs($this->owner);

        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'ফেরত', 'কারণ');
        app(NotificationService::class)->send($this->clerk, 'approval.approved', 'অনুমোদিত');

        $this->assertSame(0, Notification::query()->where('user_id', $this->clerk->id)
            ->where('type', 'approval.rejected')->count(), 'বন্ধ করা সত্ত্বেও খবরটা লেখা হয়েছে।');

        $this->assertSame(1, Notification::query()->where('user_id', $this->clerk->id)
            ->where('type', 'approval.approved')->count(), 'যে ধরনটা চালু, সেটাও আসেনি।');
    }

    /**
     * ⚠️ পছন্দটা মানুষের, কোম্পানির নয় — আর সেটা ভুল ব্যক্তির খবর বন্ধ
     * করে দেওয়ার ফাঁদ ঠেকায়: একজনের সুইচ আরেকজনের ঘণ্টা চুপ করাতে পারে না।
     */
    public function test_one_persons_switch_does_not_silence_another(): void
    {
        NotificationChoice::query()->create([
            'user_id' => $this->owner->id, 'type' => 'approval.rejected', 'enabled' => false,
        ]);

        app(NotificationService::class)->send($this->clerk, 'approval.rejected', 'ফেরত');

        $this->assertSame(1, Notification::query()->where('user_id', $this->clerk->id)
            ->where('type', 'approval.rejected')->count(), 'একজনের সুইচ অন্যজনের খবর আটকেছে।');
    }

    /** সারি না থাকা মানে চালু — নতুন ধরনের খবর নিজে থেকেই আসে। */
    public function test_a_kind_nobody_has_chosen_still_arrives(): void
    {
        app(NotificationService::class)->send($this->clerk, 'something.new', 'নতুন খবর');

        $this->assertSame(1, Notification::query()->where('user_id', $this->clerk->id)
            ->where('type', 'something.new')->count(), 'অচেনা ধরনের খবর নীরবে গিলে ফেলা হয়েছে।');
    }

    public function test_the_page_shows_every_kind_and_remembers_the_choice(): void
    {
        $this->actingAs($this->owner);

        $this->put(route('notifications.settings.update'), ['kinds' => ['approval.approved']])
            ->assertRedirect();

        $html = $this->get(route('notifications.settings'))->assertOk()->getContent();

        foreach (['approval.approved', 'approval.rejected', 'report_ready'] as $type) {
            $this->assertStringContainsString('value="'.$type.'"', $html, "{$type} পর্দায় নেই।");
        }

        preg_match_all('/<input[^>]*value="([a-z_.]+)"[^>]*>/', $html, $inputs, PREG_SET_ORDER);

        $checked = [];

        foreach ($inputs as $input) {
            if (str_contains($input[0], 'checked')) {
                $checked[] = $input[1];
            }
        }

        $this->assertSame(['approval.approved'], $checked, 'পর্দা পছন্দটা মনে রাখেনি।');
    }
}
