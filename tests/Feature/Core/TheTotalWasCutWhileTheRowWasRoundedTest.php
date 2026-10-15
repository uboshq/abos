<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportResult;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "মোট" সারির মতোই গোল হয় — কাটা নয় (ec, রিপোর্ট লাইন-নিরীক্ষা ⚠️৩, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে: সারি অর্ধেক-হলে-উপরে গোল হত (১,২৩৪.৫৭), মোট কাটা হত (১,২৩৪.৫৬); পরিমাণের সারি চার ঘর (১২.৩৪৫৬), মোট তিন ঘর (১২.৩৪৫)।
 * এক সারির রিপোর্টেও "মোট" নিজের সারির সমান ছিল না।
 */
final class TheTotalWasCutWhileTheRowWasRoundedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_one_row_total_reads_the_same_as_its_row(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $engine = app(ReportEngine::class);
        $engine->register(new ReportDefinition(
            key: 'ec_rounding_probe',
            title: 'Rounding probe',
            query: fn (array $f) => DB::query()->selectRaw("'one' as name, 1234.5678 as cost, 12.3456 as qty"),
            columns: [
                ['key' => 'name', 'label' => 'Name', 'type' => ReportColumn::TEXT],
                ['key' => 'cost', 'label' => 'Cost', 'type' => ReportColumn::MONEY],
                ['key' => 'qty', 'label' => 'Qty', 'type' => ReportColumn::QUANTITY],
            ],
            filters: [],
        ));

        $result = $engine->run('ec_rounding_probe');
        $columns = [];
        foreach ($result->report->columns as $column) {
            $columns[$column->key] = $column;
        }

        $this->assertSame('1,234.57', ReportResult::number('1234.5678', $columns['cost']), 'সারির রূপটাই বদলেছে — দাবি অন্ধ।');
        $this->assertSame('1234.57', $result->totals['cost'], '⛔ টাকার মোট কাটা হলো, গোল নয়।');
        $this->assertSame(ReportResult::number('1234.5678', $columns['cost']), $result->formatTotal($columns['cost']),
            '⛔ এক সারির মোট সারিটার সমান নয়।');
        $this->assertSame(ReportResult::number('12.3456', $columns['qty']), $result->formatTotal($columns['qty']),
            '⛔ পরিমাণের মোট সারির চেয়ে কম ঘরে কাটা।');
    }
}
