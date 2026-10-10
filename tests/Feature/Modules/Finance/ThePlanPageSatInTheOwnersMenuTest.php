<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অর্থের "পরিকল্পনা" পাতা মালিকের মেনুতে — পাতা-ঝাড়ু, ধাপ ০ (১০ অক্টোবর ২০২৬; fe)।
 *
 * ⭐ মেনুতে আর নেই; পাতাটা সরাসরি ঠিকানায় আগের মতোই খোলে (মানচিত্রের খাপ-মেলানো ওটাই পড়ে)।
 */
final class ThePlanPageSatInTheOwnersMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_plan_is_off_the_menu_but_still_opens(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $plan = route('finance.plan');
        $html = $this->get(route('finance.tenancy.index'))->assertOk()->getContent();
        $this->assertStringContainsString(route('finance.tenancy.index'), $html, 'দৃশ্যটাই বানানো যায়নি — অর্থের মেনু আঁকা হয়নি।');
        $this->assertStringNotContainsString('href="'.$plan.'"', $html, '⛔ "পরিকল্পনা" এখনও মালিকের মেনুতে।');

        $this->get($plan)->assertOk();
    }
}
