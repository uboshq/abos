<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\MasterData\Models\PaymentTerm;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "মাস শেষে"-র বিল বিলের দিনেই মেয়াদোত্তীর্ণ হত — মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ [[PaymentTerm::dueDateFrom()]] কেবল `days` যোগ করত, আর "মাস শেষে"/"বছর শেষে" সারির `days` ইচ্ছা করে ০ (দিন গোনা নয়, ঘটনা —
 * মালিক, ১৬ সেপ্টেম্বর ২০২৬)। ক্রয়ের বিল এই তারিখ থেকেই দেয়-তারিখ নেয়, তাই ৩ তারিখের বিল পরদিন থেকে "বাকি পেরোনো"।
 */
final class AMonthEndBillWasDueOnItsOwnDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_end_and_year_end_terms_fall_on_their_events_and_day_terms_still_count_days(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $month = new PaymentTerm(['code' => 'MONTH_END', 'days' => 0]);
        $this->assertSame('2026-10-31', $month->dueDateFrom('2026-10-03')->toDateString(), '"মাস শেষে"-র বিল মাসের শেষ দিনে নয়।');

        $year = new PaymentTerm(['code' => 'YEAR_END', 'days' => 0]);
        $fy = FinancialYear::forDate('2026-10-03');
        $this->assertSame($fy?->ends_on?->toDateString() ?? '2026-12-31', $year->dueDateFrom('2026-10-03')->toDateString(),
            '"বছর শেষে"-র বিল আর্থিক বছরের শেষ দিনে নয়।');

        $thirty = new PaymentTerm(['code' => 'NET30', 'days' => 30]);
        $this->assertSame('2026-11-02', $thirty->dueDateFrom('2026-10-03')->toDateString(), 'দিন-গোনা শর্ত বদলে গেছে।');
    }
}
