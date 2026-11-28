<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\NumberSeriesProvisioner;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\NumberSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ নম্বর-সিরিজের দুই কোণ যা কোনো পরীক্ষা দেখত না (Infection inf1, ২৮ নভেম্বর ২০২৬, abos-b1):
 *
 * ১. পরের অর্থবছরের তারিখের কাগজ, আর সেই বছরে ধরনটার সিরিজ নেই — সিরিজ **সেই বছরেই** বসার কথা। ⛔ mutant চলতি বছরে
 *    বসাত, তারপর পরের বছরে খুঁজে না পেয়ে থামত; জুনের শেষে আগামী বছরের তারিখে প্রথম কাগজটাই আটকাত।
 * ২. যে শাখার নাম ছকে ({BRANCH}) বসে, সেই শাখা আর নেই — নম্বর তবু বেরোয়, শাখার ঘর ফাঁকা। ⛔ mutant `?->`-কে `->` করত,
 *    আর মুছে ফেলা শাখার একটা পুরনো কাগজ নম্বর চাইলেই পর্দায় ৫০০।
 */
final class ANumberForAnotherYearOrAGoneBranchTest extends TestCase
{
    use RefreshDatabase;

    private NumberSeriesEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(NumberSeriesEngine::class);
        $company = Company::create(['code' => 'NY', 'name_en' => 'Next Year Co']);
        CompanyContext::set($company->id);

        FinancialYear::create(['name' => '2026-2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_current' => true]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_paper_dated_in_next_year_gets_its_series_in_that_year(): void
    {
        $next = FinancialYear::create(['name' => '2027-2028', 'starts_on' => '2027-07-01', 'ends_on' => '2028-06-30', 'is_current' => false]);

        app(NumberSeriesProvisioner::class)->provision(); // চলতি বছর কেবল
        $this->assertSame(0, NumberSeries::query()->where('financial_year_id', $next->id)->where('doc_type', 'LN')->count(), 'প্রস্তুতিটাই ভুল');

        $no = $this->engine->next('LN', date: Carbon::parse('2027-08-01'));

        $this->assertStringStartsWith('LN-', $no);
        $this->assertSame(1, NumberSeries::query()->where('financial_year_id', $next->id)->where('doc_type', 'LN')->count(), '⛔ পরের বছরের কাগজের সিরিজ সেই বছরে বসেনি');
    }

    public function test_a_number_still_comes_out_when_its_branch_is_gone(): void
    {
        $branch = Branch::create(['code' => 'OLD', 'name_en' => 'Closed branch']);

        NumberSeries::create([
            'module' => 'sales', 'doc_type' => 'SI', 'prefix' => 'INV', 'format' => '{PREFIX}/{BRANCH}/{SEQ}',
            'padding' => 3, 'next_number' => 1, 'start_number' => 1,
            'financial_year_id' => FinancialYear::query()->value('id'),
        ]);

        $id = $branch->id;
        DB::table('branches')->where('id', $id)->delete();

        $this->assertSame('INV//001', $this->engine->next('SI', branchId: $id), '⛔ মুছে ফেলা শাখার কাগজ নম্বর পেল না');
    }
}
