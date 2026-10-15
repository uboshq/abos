<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportExport;
use App\Core\Services\ListExport;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

/**
 * রিপোর্টের ফাইলে টাকা আর পরিমাণ সংখ্যা — Excel যোগ করতে পারে (ec, রিপোর্ট লাইন-নিরীক্ষা ⚠️৬, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে xlsx-এর প্রতিটা ঘর লেখা (inlineStr), আর টাকায় লাখের কমা ("1,23,456.50") — হিসাবরক্ষকের SUM শূন্য দিত; CSV-তেও কমাসহ লেখা।
 *
 * দাবি: টাকা/পরিমাণের কলাম xlsx-এ `t="n"` সংখ্যার ঘর, কমা ছাড়া; CSV-তে খালি সংখ্যা; লেখার কলাম লেখাই থাকে (০০১২৩ নিরাপদ);
 * পর্দার রূপ (ধরা-টেবিল, PDF-এর জন্য) কমাসহ আগের মতো।
 */
final class TheSpreadsheetCouldNotAddTheMoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_and_quantity_are_numbers_in_the_file(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id);
        $this->actingAs($owner = User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $engine = app(ReportEngine::class);
        $engine->register(new ReportDefinition(
            key: 'ec_numbers_probe',
            title: 'Numbers probe',
            query: fn (array $f) => DB::query()->selectRaw("'00123' as code, 123456.50 as amount, 1500 as qty"),
            columns: [
                ['key' => 'code', 'label' => 'Code', 'type' => ReportColumn::TEXT],
                ['key' => 'amount', 'label' => 'Amount', 'type' => ReportColumn::MONEY],
                ['key' => 'qty', 'label' => 'Qty', 'type' => ReportColumn::QUANTITY],
            ],
            filters: [],
        ));

        $result = $engine->run('ec_numbers_probe');
        $export = new ListExport;
        ReportExport::into($export, $result, $result->columnsFor($owner));

        $this->assertSame('1,23,456.50', $export->captured()['values'][0][1], 'পর্দার রূপটাই বদলেছে — PDF কমা হারাত।');

        $csv = (string) $export->csv();
        $this->assertStringContainsString("00123,123456.50,1500\r\n", $csv, '⛔ CSV-তে টাকা/পরিমাণ খালি সংখ্যা নয়।');

        $path = tempnam(sys_get_temp_dir(), 'ec_num_');
        file_put_contents($path, (string) $export->xlsx());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('<c r="B2" t="n"><v>123456.50</v></c>', $sheet, '⛔ xlsx-এ টাকা সংখ্যার ঘর নয়।');
        $this->assertStringContainsString('<c r="C2" t="n"><v>1500</v></c>', $sheet, '⛔ xlsx-এ পরিমাণ সংখ্যার ঘর নয়।');
        $this->assertStringContainsString('<c r="A2" t="inlineStr"><is><t xml:space="preserve">00123</t></is></c>', $sheet,
            '⛔ লেখার কলাম সংখ্যা হয়ে শূন্য হারাল।');
        $this->assertStringContainsString('<c r="B3" t="n"><v>123456.50</v></c>', $sheet, '⛔ মোটের সারিতে টাকা সংখ্যার ঘর নয়।');
    }
}
