<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification as Bell;
use App\Models\User;
use App\Notifications\NewsByMail;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ চিঠির বোতাম খবরের দরজা ডিঙিয়ে সরাসরি কাগজে যেত (২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ঘণ্টার খবর চিঠি হয়ে গেলে ([[NewsByMail]]) বোতামে বসত খবরের কাঁচা ঠিকানা। ⚠️ তাতে তিনটা জিনিস বাদ পড়ত, যা খবর খোলার
 * দরজা (`notifications.open`) করে: কাগজটা এখনো প্রাপকের নাগালে কি না দেখা ([[NotificationService::mayOpen()]]), না হলে
 * "দেখার অধিকার নেই" পাতা আর নিরীক্ষার সারি, আর খবরটা পড়া হিসেবে বসানো — চিঠি থেকে খুললেও ঘণ্টায় খবরটা না-পড়া ঝুলে থাকত।
 *
 * ── ⭐ দাবি ──────────────────────────────────────────────────────────
 * চিঠির ঠিকানা খবরের দরজা (পূর্ণ ঠিকানা, চিঠির বাইরে থেকে খোলার মতো); সেটা খুললে খবর পড়া হয় আর আসল গন্তব্যে যায়।
 * ঘণ্টায় সারি নেই এমন চিঠিতে (মাধ্যমের পরীক্ষা) কাঁচা ঠিকানাই থাকে।
 */
final class AMailedNoticeGoesThroughTheOpenDoorTest extends TestCase
{
    use RefreshDatabase;

    private User $her;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->her = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        // ⓘ পাঠাচ্ছেন অন্য একজন — নিজের কাজের খবর নিজে পান না, তাহলে কিছুই মাপা হত না
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_letter_links_the_open_door_and_opening_it_marks_the_notice_read(): void
    {
        $bell = app(NotificationService::class)->send($this->her, 'approval.rejected', 'ফেরত এল', 'কারণসহ', route('notifications.index'));
        $this->assertNotNull($bell, 'প্রস্তুতিটাই ভুল — খবরটাই বসেনি।');

        $link = (new NewsByMail($bell))->toMail($this->her->fresh())->viewData['url'] ?? null;

        $this->assertSame(route('notifications.open', $bell), $link,
            '⛔ চিঠির বোতাম খবরের কাঁচা ঠিকানায় যায় — নাগালের পাহারা, "অধিকার নেই" পাতা আর পড়া-হিসেবে-বসা সব ডিঙিয়ে।');
        $this->assertStringStartsWith('http', (string) $link, '⛔ চিঠির ঠিকানা পূর্ণ নয় — ইনবক্স থেকে চাপলে কোথাও যায় না।');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->her->fresh());

        $opened = $this->call('GET', (string) $link);
        $this->assertSame(302, $opened->status(), '⛔ চিঠির ঠিকানা খুলে খবরের গন্তব্যে গেল না।');
        $this->assertSame(route('notifications.index'), $opened->headers->get('Location'), '⛔ চিঠি খুলে ভুল জায়গায় গেল।');
        $this->assertNotNull($bell->fresh()->read_at, '⛔ চিঠি থেকে খোলা খবর ঘণ্টায় না-পড়া থেকে গেল।');
    }

    public function test_a_letter_with_no_bell_row_keeps_its_own_address(): void
    {
        // ⓘ মাধ্যমের পরীক্ষার চিঠি — সারি সেভ হয় না, তাই দরজা নেই
        $probe = new Bell(['title' => 'পরীক্ষা', 'url' => 'https://abos.test/kaj']);

        $this->assertSame('https://abos.test/kaj', (new NewsByMail($probe))->toMail($this->her->fresh())->viewData['url'] ?? null,
            '⛔ সারি ছাড়া চিঠির ঠিকানা হারাল।');
    }

    public function test_a_notice_with_no_address_still_has_no_button(): void
    {
        $bell = app(NotificationService::class)->send($this->her, 'approval.rejected', 'ফেরত এল');
        $this->assertNotNull($bell, 'প্রস্তুতিটাই ভুল — খবরটাই বসেনি।');

        $this->assertNull((new NewsByMail($bell))->toMail($this->her->fresh())->viewData['url'] ?? null,
            '⛔ ঠিকানা নেই এমন খবরের চিঠিতেও বোতাম বসল।');
    }
}
