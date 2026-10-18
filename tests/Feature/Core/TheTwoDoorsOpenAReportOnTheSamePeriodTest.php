<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\FiscalYearStart;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ একই রিপোর্ট, একই মানুষ, শুরুর তারিখ ছাড়া — ওয়েবের পাতা আর ফোনের দরজা একই সময়কাল খোলে (a5-এর খোঁজ, ১৭ অক্টোবর ২০২৬;
 * [[ReportDefinition::$defaultPeriod]])।
 *
 * <p>⛔ আগে বিক্রয়ের মাসওয়ারি আর হিসাবের লাভ-ক্ষতি ও মাসওয়ারি নগদের ওয়েব-পাতা নিজে চলতি অর্থবছর বসাত, অথচ ফোনের দরজা ইঞ্জিনের "এই
 * মাস" নিত: ফোনে ১ সারি, ওয়েবে ১২। দাবি:
 *  - তিনটা রিপোর্টের দুই দরজাই শুরু করে চলতি অর্থবছরের প্রথম দিন থেকে — আর ফোনের দরজা সেটাই ফেরত বলে।
 *  - ঘোষণা ছাড়া রিপোর্ট আগের মতো "এই মাস"।
 *  - অচেনা সময়কাল ঘোষণা করলে রিপোর্টই দাঁড়ায় না।
 *  - কোম্পানির বছরের সারি না থাকলে ১ জুলাই।
 */
final class TheTwoDoorsOpenAReportOnTheSamePeriodTest extends TestCase
{
    use RefreshDatabase;

    private const FISCAL = [
        'sales.monthly' => '/sales/reports/monthly',
        'accounts.profit_loss' => '/accounts/reports/profit-loss',
        'accounts.monthly_cash' => '/accounts/reports/monthly-cash',
    ];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['current_company_id' => $company->id])->save();
        CompanyContext::set((int) $company->id, $this->owner->current_branch_id);
    }

    public function test_the_web_page_and_the_phone_open_the_same_fiscal_year_without_a_from(): void
    {
        $start = FiscalYearStart::on(Carbon::today());
        $this->assertNotSame(Carbon::today()->startOfMonth()->toDateString(), $start,
            'দাবির ভিত্তি নেই — আজ অর্থবছরের প্রথম মাস, দুই সময়কাল একই দিনে পড়ে।');

        foreach (self::FISCAL as $key => $web) {
            $this->actingAs($this->owner);
            // ⓘ পাতার তারিখের ঘর `$result->filters['from']` থেকে আঁকা; তারিখের ঘর Alpine-এ বাঁধা, HTML-এ `value="…"` লেখা থাকে না
            $this->get($web)->assertOk()->assertViewHas('result', function ($result) use ($start, $key): bool {
                $this->assertSame($start, $result->filters['from'] ?? null, "⛔ ওয়েবের {$key} চলতি অর্থবছর থেকে খুলল না।");

                return true;
            });

            $phone = $this->phone("/api/v1/reports/{$key}")->assertOk();
            $this->assertSame($start, $phone->json('filters.from'),
                "⛔ ফোনের {$key} ওয়েবের পাতার সময়কাল থেকে খুলল না — ফোনে এক মাস, ওয়েবে গোটা বছর।");
        }
    }

    public function test_a_report_without_the_declaration_still_opens_on_this_month(): void
    {
        $result = app(ReportEngine::class)->run('accounts.day_book', []);

        $this->assertSame(Carbon::today()->startOfMonth()->toDateString(), $result->filters['from'], '⛔ ঘোষণা ছাড়া রিপোর্টের সময়কাল বদলে গেল।');
        $this->assertSame(Carbon::today()->startOfMonth()->toDateString(), $this->phone('/api/v1/reports/accounts.day_book')->json('filters.from'));
    }

    public function test_a_given_from_wins_and_an_unknown_period_is_refused(): void
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $this->assertSame($from, $this->phone('/api/v1/reports/sales.monthly?from='.$from)->json('filters.from'),
            '⛔ মানুষের দেওয়া শুরুর তারিখ ঘোষণার নিচে চাপা পড়ল।');

        $this->expectException(\InvalidArgumentException::class);
        new ReportDefinition(key: 'x.y', title: 'x', query: fn () => null, columns: [], defaultPeriod: 'quarter');
    }

    public function test_without_a_year_row_the_year_begins_on_the_first_of_july(): void
    {
        // ⓘ ২০৪০ — কোনো বছরের সারি ঐ দিন ঢাকে না (ডেমোর বছর আজকের)
        $this->assertSame(0, \App\Models\FinancialYear::query()->whereDate('ends_on', '>=', '2040-01-01')->count(), 'প্রস্তুতিটাই ভুল — ২০৪০-এর বছর আছে।');
        $this->assertSame('2040-07-01', FiscalYearStart::on('2040-10-17'));
        $this->assertSame('2040-07-01', FiscalYearStart::on('2041-06-30'));
        $this->assertSame('2041-07-01', FiscalYearStart::on('2041-07-01'));
    }

    private function phone(string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }
}
