<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ গ্র্যাচুইটির সুইচের পাশে নিরীক্ষকের জন্য এক লাইন — IAS 19 defined benefit, পূর্ণ actuarial মূল্যায়নের বদলে বর্তমান বেতনে অর্জিত দায়
 * (fe, ২১ অক্টোবর ২০২৬)। দাবি: লাইনটা কন্ট্রোল প্যানেলের HR ভাগে (যেখানে `hr.gratuity.enabled` বসে) আর গ্র্যাচুইটির পাতায় দেখা যায়,
 * বাংলায়; ইংরেজিতে তার জোড়া।
 */
final class TheGratuitySettingTellsAuditorsTheMethodTest extends TestCase
{
    use RefreshDatabase;

    private const BN = 'IAS 19 অনুযায়ী গ্র্যাচুইটি একটা defined benefit; পূর্ণ actuarial মূল্যায়নের বদলে আমরা বর্তমান বেতন ধরে অর্জিত দায় রাখছি — ছোট কোম্পানির জন্য গ্রহণযোগ্য সরলীকরণ।';

    private const EN = 'Under IAS 19 gratuity is a defined benefit; instead of a full actuarial valuation we carry the accrued obligation at current salary — an acceptable simplification for a small company.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_line_renders_beside_the_switch_and_on_the_gratuity_page(): void
    {
        app()->setLocale('bn');
        $this->assertStringContainsString(self::BN, __('hr::settings.gratuity_enabled'));

        // ⓘ যে ট্যাবে HR-এর সুইচ বসে — সব ট্যাব ঘুরে (কন্ট্রোল প্যানেলের নিজের পরীক্ষার ধরন); পাতার ভাষা মানুষের, তাই দুই ভাষার যেকোনোটা
        $first = $this->get(route('system_admin.control-panel'))->assertOk();
        $pages = (string) $first->getContent();

        foreach ($first->viewData('tabs') as $tab) {
            $pages .= (string) $this->get(route('system_admin.control-panel', ['tab' => $tab['key']]))->assertOk()->getContent();
        }

        $this->assertTrue(str_contains($pages, e(self::BN)) || str_contains($pages, e(self::EN)),
            '⛔ গ্র্যাচুইটির সুইচের পাশে নিরীক্ষকের লাইন নেই।');

        $page = (string) $this->get(route('hr.gratuity.report'))->assertOk()->assertSee('data-ias19-note', false)->getContent();
        $this->assertTrue(str_contains($page, e(self::BN)) || str_contains($page, e(self::EN)), '⛔ গ্র্যাচুইটির পাতায় লাইনটা নেই।');

        app()->setLocale('en');
        $this->assertStringContainsString('Under IAS 19 gratuity is a defined benefit', __('hr::settings.gratuity_enabled'));
        $this->assertStringContainsString('acceptable simplification', __('hr::message.gratuity_ias19'));
    }
}
