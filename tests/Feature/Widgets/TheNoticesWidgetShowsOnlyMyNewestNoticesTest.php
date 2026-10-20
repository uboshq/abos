<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Widgets\WidgetSummaries;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Notification\Widgets\NoticesSummary;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ "নোটিশ" উইজেট — ফোনের ঘণ্টার একই খবর, এক দরজায় (`GET /api/v1/widgets/summary`; a6, ২০ অক্টোবর ২০২৬;
 * [[NoticesSummary]])।
 *
 * দাবি:
 *  - আমার দুই খবর (একটা পড়া, একটা না-পড়া) আর অন্যের একটা: না-পড়া ১, কেবল আমার দুই সারি, নতুনটা আগে, `link` ওয়েবের পথ;
 *    ডিপ-লিংকের বাইরের পথে `link` null।
 *  - নয়টা খবর: ৮ সারি, `more` ১।
 *  - কোনো চাবি নেই এমন কর্মীও নিজের নোটিশের উইজেট পান; ফোনে সিস্টেম-মডিউল বন্ধ হলে উইজেট নেই।
 */
final class TheNoticesWidgetShowsOnlyMyNewestNoticesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $me;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        foreach (['me', 'other'] as $who) {
            $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
            $user->companies()->attach($this->company->id, ['is_active' => true]);
            $this->{$who} = $user;
        }

        WidgetSummaries::$testing = [NoticesSummary::class];
    }

    protected function tearDown(): void
    {
        WidgetSummaries::$testing = [];
        parent::tearDown();
    }

    public function test_only_my_notices_newest_first_with_the_unread_count_and_the_paper_link(): void
    {
        $old = $this->tell($this->me, 'পুরনো খবর', 'https://erp.adi.com.bd/login');
        $this->tell($this->me, 'নতুন খবর', '/sales/invoices/391?tab=lines');
        $this->tell($this->other, 'অন্যের খবর', '/sales/invoices/7');
        $old->forceFill(['read_at' => now()])->save();

        $widget = $this->widgets($this->me)['notices'] ?? null;
        $this->assertNotNull($widget, '⛔ নিজের নোটিশের উইজেট এল না।');

        $this->assertSame(1, $widget['unread']);
        $this->assertSame(['নতুন খবর', 'পুরনো খবর'], array_column($widget['rows'], 'title'), '⛔ অন্যের খবর এল, বা ক্রম উল্টো।');
        $this->assertSame('নতুন খবর body', $widget['rows'][0]['second']);
        $this->assertTrue($widget['rows'][0]['unread']);
        $this->assertFalse($widget['rows'][1]['unread']);
        $this->assertSame('/sales/invoices/391?tab=lines', $widget['rows'][0]['link'], '⛔ "খুলুন"-এর পথ উইজেটে নেই।');
        $this->assertNull($widget['rows'][1]['link'], '⛔ ডিপ-লিংকের বাইরের পথ (লগইন) উইজেটের link হল।');
        $this->assertNotEmpty($widget['rows'][0]['at']);
        $this->assertSame(0, $widget['more']);
        $this->assertSame(['title', 'second', 'at', 'unread', 'link'], array_keys($widget['rows'][0]));
    }

    public function test_nine_notices_show_eight_and_say_one_more(): void
    {
        foreach (range(1, 9) as $n) {
            $this->tell($this->me, "খবর {$n}", null);
        }

        $widget = $this->widgets($this->me)['notices'];

        $this->assertCount(8, $widget['rows'], '⛔ ৮টার বেশি সারি গেল।');
        $this->assertSame('খবর 9', $widget['rows'][0]['title']);
        $this->assertSame('খবর 2', $widget['rows'][7]['title']);
        $this->assertSame(1, $widget['more'], '⛔ দেখানো ৮টার বাইরের খবর গোনা হল না।');
        $this->assertSame(9, $widget['unread']);
    }

    public function test_a_staff_member_with_no_keys_still_gets_his_notices_but_not_while_the_module_is_off_on_the_phone(): void
    {
        $this->assertSame(0, $this->me->getAllPermissions()->count(), 'প্রস্তুতিটাই ভুল — মানুষটার কোনো চাবি থাকার কথা নয়।');
        $this->tell($this->me, 'আমার খবর', null);

        $this->assertArrayHasKey('notices', $this->widgets($this->me), '⛔ চাবি নেই বলে নিজের নোটিশও এল না।');

        app(SettingsService::class)->set(PhoneModules::key('system_admin'), false);
        app()->forgetInstance(SettingsService::class);
        app()->forgetInstance(PhoneModules::class);

        $this->assertArrayNotHasKey('notices', $this->widgets($this->me), '⛔ ফোনে মডিউল বন্ধ, তবু নোটিশের উইজেট।');
    }

    private function tell(User $user, string $title, ?string $url): Notification
    {
        return Notification::query()->create([
            'company_id' => $this->company->id, 'user_id' => $user->id, 'type' => 'test.widget',
            'title' => $title, 'body' => $title.' body', 'url' => $url,
        ]);
    }

    /** @return array<string, mixed> */
    private function widgets(User $user): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return (array) $this->getJson('/api/v1/widgets/summary?keys=notices')->assertOk()->json('widgets');
    }
}
