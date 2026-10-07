<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\NoticeLifecycle;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\Notice;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ওয়েবের চলমান নোটিশ ফোনেও — মালিক, ৬ অক্টোবর ২০২৬ (সমন্বয়কের মারফত)।
 *
 * ⭐ দাবি: `/notices/bar` ওয়েবের পাদদেশের মানুষের কথাগুলোই দেয় — বারে টিক দেওয়া নিজের নোটিশের শিরোনাম, ওয়েবের
 * একই ক্রমে; অন্য কোম্পানির কিছু নয়; বন্ধ (স্থগিত) নোটিশ মানে কিছুই নয়। ⓘ পুরনো `system.notice` লাইনটা ২৩ সেপ্টেম্বর
 * ২০২৬ থেকে নোটিশ ([[the_bottom_bar_was_one_line_in_a_settings_row]]), তাই আলাদা কিছু নেই।
 */
final class ThePhoneRunsTheWebsNoticeLineTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $settings = app(SettingsService::class);
        $settings->set('notice.creator_cannot_approve', false);
        $settings->flush();
        Notice::query()->update(['in_ticker' => false]);
    }

    public function test_the_bar_carries_the_webs_notice_titles_in_the_webs_order(): void
    {
        $this->published('সাধারণ খবর', NoticePriority::IMPORTANT);
        $this->published('আগুন লেগেছে', NoticePriority::EMERGENCY);

        $rows = $this->bar();

        // ⓘ ওয়েবের বারের একই উৎস ([[StatusNotices::ownNotices()]] → [[NoticeBoard::forTicker()]]) — জরুরিটা আগে
        $this->assertSame(['আগুন লেগেছে', 'সাধারণ খবর'], array_column($rows, 'title'));
        $this->assertFalse($rows[0]['can_dismiss'], 'জরুরি নোটিশ সরানো যায় দেখাল।');
    }

    public function test_nothing_when_nothing_is_on_or_the_notice_is_switched_off(): void
    {
        $this->assertSame([], $this->bar(), 'কিছু না থাকলে বারটা চুপ নয়।');

        $notice = $this->published('পুরনো খবর', NoticePriority::IMPORTANT);
        $this->assertCount(1, $this->bar());

        app(NoticeLifecycle::class)->suspend($notice);
        $this->assertSame([], $this->bar(), '⛔ বন্ধ করা নোটিশ ফোনে ঘুরতে থাকল।');
    }

    public function test_another_companys_notice_never_comes(): void
    {
        $this->published('আমাদের খবর', NoticePriority::IMPORTANT);
        $other = Company::query()->where('code', '!=', 'TDEPOT')->firstOrFail();

        CompanyContext::forCompany($other->id, function (): void {
            app(SettingsService::class)->set('notice.creator_cannot_approve', false);
            app(SettingsService::class)->flush();
            $this->published('অন্য কোম্পানির কথা', NoticePriority::EMERGENCY);
        });
        $this->assertSame(1, Notice::query()->withoutGlobalScopes()->where('company_id', $other->id)->where('title', 'অন্য কোম্পানির কথা')->where('in_ticker', true)->count(),
            'দাবির ভিত্তি নেই — অন্য কোম্পানির নোটিশ বারে ওঠেনি।');

        $this->assertSame(['আমাদের খবর'], array_column($this->bar(), 'title'), '⛔ অন্য কোম্পানির নোটিশ এল।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function bar(): array
    {
        app(SettingsService::class)->flush();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/notices/bar')->assertOk()->json('data');
    }

    private function published(string $title, NoticePriority $priority): Notice
    {
        $life = app(NoticeLifecycle::class);
        $notice = $life->draft(['title' => $title, 'body' => $title, 'priority' => $priority->value]);

        return $life->publish($life->approve($life->submit($notice)));
    }
}
