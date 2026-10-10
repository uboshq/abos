<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ খাত-বিশ্লেষণের মাসের লিংক বাংলা পর্দাতেও "Oct 2026" লিখত — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (সারাই ১০)।
 *
 * ⓘ `format('M Y')` ভাষা মানে না; প্রকল্পের নিয়ম `translatedFormat()` — বাকি ১৫টা পর্দা সেটাই করে।
 */
final class TheMonthNameWasInEnglishOnABengaliScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_month_link_speaks_the_screens_language(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        app()->setLocale('bn');
        Carbon::setLocale('bn');

        $html = view('finance::account-analysis.partials.month-link', [
            'm' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
            'account' => StandardChart::find(StandardChart::BANK_CHARGES),
        ])->render();

        $this->assertStringNotContainsString('Oct 2026', $html, '⛔ বাংলা পর্দায় মাসের নাম ইংরেজিতে');
        $this->assertStringContainsString(Carbon::parse('2026-10-01')->translatedFormat('M Y'), $html);
    }
}
