<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Models\NumberSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ডকুমেন্ট নম্বর — কখনো দুইবার একই নম্বর নয়, আর ফাঁক থাকলে সেটা দেখা যাবে।
 */
class NumberSeriesTest extends TestCase
{
    use RefreshDatabase;

    private NumberSeriesEngine $engine;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(NumberSeriesEngine::class);

        $this->company = Company::create(['code' => 'NS', 'name_en' => 'Number Series Co']);
        CompanyContext::set($this->company->id);

        FinancialYear::create([
            'name' => '2026-2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'is_current' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function series(array $overrides = []): NumberSeries
    {
        return NumberSeries::create(array_merge([
            'module' => 'sales',
            'doc_type' => 'SI',
            'prefix' => 'INV',
            'format' => '{PREFIX}-{SEQ}',
            'padding' => 4,
            'next_number' => 1,
            'start_number' => 1,
            'financial_year_id' => FinancialYear::query()->value('id'),
        ], $overrides));
    }

    public function test_numbers_come_out_in_order_and_padded(): void
    {
        $this->series();

        $this->assertSame('INV-0001', $this->engine->next('SI'));
        $this->assertSame('INV-0002', $this->engine->next('SI'));
        $this->assertSame('INV-0003', $this->engine->next('SI'));
    }

    public function test_the_same_number_is_never_handed_out_twice(): void
    {
        $this->series();

        $issued = [];
        for ($i = 0; $i < 50; $i++) {
            $issued[] = $this->engine->next('SI');
        }

        $this->assertCount(50, array_unique($issued), 'Two documents were given the same number.');
    }

    public function test_every_issued_number_is_recorded(): void
    {
        $this->series();

        $this->engine->next('SI', sourceType: 'sales_invoice', sourceId: 42);

        $record = IssuedNumber::query()->first();

        $this->assertSame('INV-0001', $record->document_no);
        $this->assertSame('sales_invoice', $record->source_type);
        $this->assertSame(42, (int) $record->source_id);
        $this->assertFalse($record->is_voided);
    }

    public function test_a_voided_number_stays_voided_and_is_not_reused(): void
    {
        $this->series();

        $first = $this->engine->next('SI');
        $this->engine->void($first, 'গ্রাহক অর্ডার বাতিল করেছে');

        $second = $this->engine->next('SI');

        // পুনর্ব্যবহার করলে নিরীক্ষায় একই নম্বরের দুইটা কাগজ পাওয়া যেত।
        $this->assertNotSame($first, $second);
        $this->assertSame('INV-0002', $second);

        $voided = IssuedNumber::query()->where('document_no', $first)->first();
        $this->assertTrue($voided->is_voided);
        $this->assertSame('গ্রাহক অর্ডার বাতিল করেছে', $voided->void_reason);
    }

    public function test_the_format_placeholders_are_filled_in(): void
    {
        $branch = Branch::create(['code' => 'DHK', 'name_en' => 'Dhaka', 'is_default' => true]);

        $this->series([
            'branch_id' => $branch->id,
            'format' => '{PREFIX}/{BRANCH}/{YYYY}{MM}/{SEQ}',
            'padding' => 3,
        ]);

        $number = $this->engine->next('SI', branchId: $branch->id, date: Carbon::parse('2026-08-04'));

        $this->assertSame('INV/DHK/202608/001', $number);
    }

    public function test_two_branches_keep_separate_counters(): void
    {
        $dhaka = Branch::create(['code' => 'DHK', 'name_en' => 'Dhaka', 'is_default' => true]);
        $ctg = Branch::create(['code' => 'CTG', 'name_en' => 'Chattogram']);

        $fy = FinancialYear::query()->value('id');

        $this->series(['branch_id' => $dhaka->id, 'prefix' => 'DHK', 'financial_year_id' => $fy]);
        $this->series(['branch_id' => $ctg->id, 'prefix' => 'CTG', 'financial_year_id' => $fy]);

        $this->assertSame('DHK-0001', $this->engine->next('SI', branchId: $dhaka->id));
        $this->assertSame('CTG-0001', $this->engine->next('SI', branchId: $ctg->id));
        $this->assertSame('DHK-0002', $this->engine->next('SI', branchId: $dhaka->id));
    }

    public function test_a_branch_without_its_own_series_falls_back_to_the_company_one(): void
    {
        $branch = Branch::create(['code' => 'SYL', 'name_en' => 'Sylhet']);
        $this->series(['branch_id' => null]);

        // শাখা আলাদা না করা প্রতিষ্ঠানকে প্রতিটা শাখায় সিরিজ বানাতে বাধ্য
        // করার কোনো কারণ নেই।
        $this->assertSame('INV-0001', $this->engine->next('SI', branchId: $branch->id));
    }

    public function test_an_unconfigured_document_type_says_so_clearly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No number series is configured/');

        $this->engine->next('PI');
    }

    public function test_gaps_are_visible_because_an_auditor_will_ask(): void
    {
        $series = $this->series();

        $this->engine->next('SI');   // 1
        $this->engine->next('SI');   // 2
        $this->engine->next('SI');   // 3

        // ২ নম্বরের রেকর্ড হারিয়ে গেলে (যেভাবেই হোক) সেটা ধরা পড়তে হবে
        IssuedNumber::query()->where('sequence', 2)->delete();

        $gaps = $this->engine->gaps('SI', null, $series->financial_year_id);

        $this->assertSame([2], $gaps);
    }

    public function test_numbers_are_scoped_to_the_company(): void
    {
        $this->series();
        $this->engine->next('SI');

        $other = Company::create(['code' => 'OTHER', 'name_en' => 'Other Co']);

        CompanyContext::forCompany($other->id, function () {
            FinancialYear::create([
                'name' => '2026-2027',
                'starts_on' => '2026-07-01',
                'ends_on' => '2027-06-30',
                'is_current' => true,
            ]);

            NumberSeries::create([
                'module' => 'sales',
                'doc_type' => 'SI',
                'prefix' => 'INV',
                'format' => '{PREFIX}-{SEQ}',
                'padding' => 4,
                'next_number' => 1,
                'start_number' => 1,
                'financial_year_id' => FinancialYear::query()->value('id'),
            ]);

            // অন্য কোম্পানির কাউন্টার আলাদা — এক থেকেই শুরু হবে।
            $this->assertSame('INV-0001', app(NumberSeriesEngine::class)->next('SI'));
        });
    }
}
