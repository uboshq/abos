<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\NumberSeriesProvisioner;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * কোনো অর্থবছরে না-পড়া তারিখের কাগজে ইংরেজি ৫০০ — মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ [[NumberSeriesEngine::next()]] বছর খুঁজে না পেলে বছরহীন সিরিজ খুঁজত; নিজে-বসানো সিরিজ বসে চলতি বছরে, তাই শেষে
 * "No number series is configured…" — পর্দায় ৫০০, অথচ ভুলটা তারিখের। ⓘ বছরের ভেতরের তারিখে আগের মতোই নম্বর।
 */
final class APaperOutsideEveryYearGaveAnEnglishErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_date_in_no_financial_year_is_a_bangla_date_error(): void
    {
        $company = Company::create(['code' => 'NSY', 'name_en' => 'No Year Co']);
        CompanyContext::set($company->id);
        FinancialYear::create(['name' => '2026-2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_current' => true]);
        app(NumberSeriesProvisioner::class)->provision();

        $engine = app(NumberSeriesEngine::class);

        try {
            $this->assertStringStartsWith('LN-', $engine->next('LN', date: Carbon::parse('2026-10-14')),
                'বছরের ভেতরের তারিখেও নম্বর এল না — দাবিটা কিছু মাপছে না।');

            try {
                $engine->next('LN', date: Carbon::parse('2020-01-05'));
                $this->fail('কোনো অর্থবছরে না-পড়া তারিখেও নম্বর কাটা হল।');
            } catch (ValidationException $e) {
                $this->assertSame(__('core.date_outside_every_financial_year'), $e->errors()['date'][0] ?? null);
            }
        } finally {
            CompanyContext::clear();
        }
    }
}
