<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\Trend;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\ExportLog;
use App\Models\SavedView;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Support\Go;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use ZipArchive;

/**
 * রিপোর্টের ফাইল — CSV, Excel আর JSON একই সারি বহন করে, আর প্রতিটা নামানো রপ্তানির খাতায় ওঠে।
 *
 * ── ⓘ যা মাপা হলো, আর কেন নতুন কিছু বসানো হয়নি ─────────────────────────
 * কাজের তালিকা বলেছিল: Excel বা JSON না থাকলে কেন্দ্রীয় রপ্তানির পথে যোগ করতে। ⭐ দুইটাই আগে থেকেই আছে
 * ([[ListExport::FORMATS]] = csv, xlsx, json), একই পথে ([[ExportListing]]), সূত্র-পাহারাসহ, আর রপ্তানির
 * খাতায় ([[ExportJournal]]) — তাই নতুন লাইব্রেরি লাগেনি। এখানে কেবল মালিকের কেন্দ্রের নতুন রিপোর্টে সেটা প্রমাণ।
 *
 * ⓘ আর সময়ের ধারা ([[Trend]]) — তুলনার পাতা যেটা ডাকে — তার নিয়মগুলো।
 */
final class EveryReportLeavesInEveryFormatWithATraceTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_excel_and_json_carry_the_same_rows_and_each_is_journalled(): void
    {
        $this->seed(DemoSeeder::class);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->actingAs($owner);

        foreach (['Rahim Traders', 'Alam Store'] as $name) {
            CompanyContext::forCompany((int) $alpha->id, function () use ($alpha, $name) {
                $warehouse = Warehouse::query()->where('code', 'WH-MMS')->firstOrFail();
                CompanyContext::set((int) $alpha->id, (int) $warehouse->branch_id);
                app(DirectSaleService::class)->complete(
                    ['customer_id' => Customer::acrossDealers()->where('name_en', $name)->firstOrFail()->id,
                        'warehouse_id' => $warehouse->id, 'own_transport' => '1', DirectSaleService::REPEAT_FIELD => '1'],
                    [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id,
                        'qty' => '2', 'rate' => '1250', 'free_qty' => '0']],
                );
            });
        }

        $url = fn (string $format) => route('executive.report.show', ['slug' => 'profit-by-customer', 'export' => $format]);
        $logged = ExportLog::query()->withoutGlobalScopes()->count();

        $csv = $this->get($url('csv'))->assertOk()->getContent();
        $csvRows = array_values(array_filter(array_map('str_getcsv', preg_split('/\r?\n/', ltrim((string) $csv, "\u{FEFF}"))), fn ($r) => $r !== [null] && $r !== ['']));

        $json = json_decode((string) $this->get($url('json'))->assertOk()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $xlsx = $this->sheetRows((string) $this->get($url('xlsx'))->assertOk()->getContent());

        // ⓘ প্রথম সারি শিরোনাম — তিন ফাইলে একই কলাম, একই ক্রমে
        $this->assertSame($csvRows[0], $xlsx[0], '⛔ Excel-এর শিরোনাম CSV-র থেকে আলাদা।');
        $this->assertSame(array_column($json['columns'], 'label'), $csvRows[0], '⛔ JSON-এর কলাম CSV-র থেকে আলাদা।');

        // ⭐ একই সারি — CSV-র প্রতিটা সারি Excel-এ আর JSON-এ হুবহু
        $body = array_slice($csvRows, 1, count($json['rows']));
        // ⓘ শাখা ধরে ভাগ — শাখার শিরোনাম আর যোগের সারিও ফাইলে আছে; দুই ক্রেতার নাম অন্তত থাকতে হবে
        $names = implode('|', array_map(fn (array $r) => implode(',', $r), $body));
        foreach (['রহিম', 'আলম'] as $who) {
            $this->assertStringContainsString($who, $names, "প্রস্তুতিটাই ভুল — {$who}-এর সারি ফাইলে নেই।");
        }
        $this->assertSame($body, array_slice($xlsx, 1, count($json['rows'])), '⛔ Excel-এর সারি CSV-র সারি নয়।');
        $this->assertSame($body, array_map(fn (array $r) => array_values(array_map('strval', $r)), $json['rows']), '⛔ JSON-এর সারি CSV-র সারি নয়।');

        // ⭐ তিনটা নামানো, তিনটা রপ্তানির খাতার সারি — এই রিপোর্টের নামে
        $journal = ExportLog::query()->withoutGlobalScopes()->orderBy('id')->get()->slice($logged)->values();
        $this->assertCount(3, $journal, '⛔ প্রতিটা ফাইল রপ্তানির খাতায় ওঠেনি।');
        foreach ($journal as $entry) {
            $this->assertSame('executive.report.show', $entry->route);
            $this->assertSame((int) $owner->id, (int) $entry->user_id);
            $this->assertSame(count($json['rows']), (int) $entry->row_count);
        }

        // ⓘ একই রিপোর্ট ফোনের দরজাতেও — কেন্দ্রীয় ইঞ্জিনের নিজের নিবন্ধন
        $this->assertContains('executive.profit_by_customer', app(ReportEngine::class)->keys());
    }

    public function test_the_reports_page_opens_saved_views_in_their_own_company(): void
    {
        $this->seed(DemoSeeder::class);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        CompanyContext::forCompany((int) $beta->id, fn () => SavedView::query()->create([
            'user_id' => $owner->id, 'company_id' => $beta->id, 'screen' => 'sales.report.show:by-customer',
            'name' => 'ফ্যামিলির বড় ক্রেতা', 'query' => 'top=10', 'is_default' => false,
        ]));

        $page = $this->actingAs($owner)->get(route('executive.reports'))->assertOk()
            ->assertSee('ফ্যামিলির বড় ক্রেতা')
            ->assertSee(route('reports.center'), false);

        foreach (['csv', 'xlsx', 'json'] as $format) {
            $page->assertSee('data-export="'.$format.'"', false);
        }

        // ⓘ দৃশ্যটা ফ্যামিলি মার্টের — খোলার বোতাম আগে সেখানে নিয়ে যায়
        $this->post(route('executive.open'), ['go' => Go::to((int) $beta->id, null, 'sales.report.show', ['slug' => 'by-customer', 'top' => '10'])])
            ->assertRedirect(route('sales.report.show', ['slug' => 'by-customer', 'top' => '10']));
        $this->assertSame((int) $beta->id, (int) $owner->fresh()->current_company_id);
    }

    public function test_the_trend_buckets_cover_the_range_exactly_and_weeks_start_on_saturday(): void
    {
        $weeks = Trend::buckets(Trend::WEEKLY, '2026-10-01', '2026-10-20');

        // ⓘ ১ অক্টোবর ২০২৬ বৃহস্পতিবার — প্রথম খোপ আধা, পরেরগুলো শনিবারে শুরু
        $this->assertSame('2026-10-01', $weeks[0]['from']);
        $this->assertSame('2026-10-02', $weeks[0]['to']);
        $this->assertSame('2026-10-03', $weeks[1]['from'], '⛔ সপ্তাহ শনিবারে শুরু হওয়ার কথা।');
        $this->assertSame('2026-10-20', $weeks[count($weeks) - 1]['to']);

        foreach ([Trend::DAILY, Trend::WEEKLY, Trend::MONTHLY, Trend::QUARTERLY, Trend::YEARLY] as $grain) {
            $buckets = Trend::buckets($grain, '2025-11-15', '2026-10-09');
            $days = 0;

            foreach ($buckets as $i => $b) {
                $days += (int) Carbon::parse($b['from'])->diffInDays(Carbon::parse($b['to'])) + 1;

                if ($i > 0) {
                    $this->assertSame(Carbon::parse($buckets[$i - 1]['to'])->addDay()->toDateString(), $b['from'],
                        "⛔ '{$grain}' খোপের মাঝে ফাঁক বা দ্বিগুণ।");
                }
            }

            $this->assertSame(329, $days, "⛔ '{$grain}' খোপগুলো গোটা পরিসর ঢাকেনি।");
        }

        // ⓘ যোগফল মেলে — প্রতিটা খোপে একই সংজ্ঞা, খোপের যোগ = পরিসরের সংখ্যা
        $perDay = fn (string $f, string $t) => (string) ((int) Carbon::parse($f)->diffInDays(Carbon::parse($t)) + 1);
        $series = Trend::series(Trend::MONTHLY, '2026-01-10', '2026-03-05', $perDay);
        $this->assertSame('55', array_reduce($series, fn ($s, $b) => bcadd($s, $b['value']), '0'));

        $this->assertCount(Trend::MOST_BUCKETS, Trend::buckets(Trend::DAILY, '2020-01-01', '2026-01-01'), '⛔ খোপের সীমা নেই।');
        $this->assertNull(Trend::change('500', '0'), 'শূন্য থেকে বদলের শতাংশ নেই।');
        $this->assertSame('-50.00', Trend::change('50', '100'));
    }

    /**
     * Excel ফাইলের প্রথম পাতার সারি — লেখা ঘর ধরে।
     *
     * @return list<list<string>>
     */
    private function sheetRows(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, '⛔ Excel ফাইলটা খোলা যায় না।');
        $xml = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        @unlink($path);

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $cells[] = (string) $c->is->t;
            }
            $rows[] = $cells;
        }

        return $rows;
    }
}
