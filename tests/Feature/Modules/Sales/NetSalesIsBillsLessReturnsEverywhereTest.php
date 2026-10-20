<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\Figures;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ নিট বিক্রি = বিল − ফেরত, সব জায়গায় এক অঙ্ক (IFRS 15; fe, ২০ অক্টোবর ২০২৬)।
 *
 * দাবি, আসল পথে (ওয়েবের হোম `GET /`, ফোনের `GET /api/v1/dashboard/today` Sanctum + অ্যাপের ক্ষমতা, মালিকের ছক [[Board]]),
 * আসল নিশ্চিত বিলে আর আসল নিশ্চিত বিক্রয় ফেরতে ([[SalesInvoiceService]], [[SalesReturnService]]):
 *   · আজ বিল ১,০০,০০০ + VAT, ফেরত ১০,০০০ + VAT → নিট ৯০,০০০ (VAT বাদে, IFRS 15.47), VAT ৯,০০০ আলাদা: SalesMetrics, ওয়েবের কার্ড, ফোনের `sales.amount`, ছকের SALES;
 *   · বিল আর ফেরত আলাদা দেখায় — ফোনের `sales.billed`/`sales.returned`, ওয়েবের কার্ডের খোলা-ঘর, ছকের `sales_parts`;
 *   · ফেরত নিজের তারিখে: গতকালের ফেরত আজ কমায় না, আজকের ফেরত গতকালের বিলেরও হলে আজ কমায়;
 *   · শাখার সারির যোগ = মোট, প্রতিটা সারি নিট আর পাশে বিল-ফেরত;
 *   · ওয়েব = ফোন = ছক, পয়সায়;
 *   · দেয়ালে বাঁধা বিক্রয়কর্মীর নিট কেবল নিজের ডিলারের;
 *   · নাম "নিট বিক্রি" — ওয়েবের কার্ড, ফোনের `sales.label`, ছকের ঘর।
 */
final class NetSalesIsBillsLessReturnsEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private const NET_LABEL = 'নিট বিক্রি';

    private const FULL_LABEL = 'নিট বিক্রি (VAT ও ফেরত বাদে)';

    private Company $company;

    private User $owner;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'bn'])->save();
        $this->web($this->owner);
        $this->warehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();

        app(SettingsService::class)->set('sales.vat_enabled', true);
        $this->assertTrue((bool) app(SettingsService::class)->get('sales.vat_enabled', false), 'প্রস্তুতিটাই ভুল — বিক্রিতে VAT চালু হয়নি।');

        // ⭐ চালে ১০% VAT, দামের উপরে — বিল আর ফেরত দুইটাতেই VAT থাকে, আর নিট বিক্রি VAT বাদে (IFRS 15.47)
        $vat = Tax::query()->create(['code' => 'NET-VAT10', 'name_en' => 'NET-VAT10', 'name_bn' => 'NET-VAT10', 'rate' => '10', 'kind' => 'vat',
            'is_inclusive' => false, 'is_active' => true]);
        Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail()->forceFill(['tax_id' => $vat->id])->save();

        // ⓘ বাকির সীমা আর দিন এই দাবির বিষয় নয় — বিক্রি যেন আটকে না যায়
        Customer::acrossDealers()->whereIn('name_en', ['Rahim Traders', 'Niloy Store'])->get()
            ->each(fn (Customer $c) => $c->forceFill(['credit_limit' => '100000000', 'credit_days' => 60])->save());
    }

    public function test_a_tenth_returned_today_leaves_ninety_thousand_on_every_screen(): void
    {
        $today = Carbon::today()->toDateString();
        $before = $this->phone($this->owner)->json('sales');
        $metricsBefore = $this->metrics($today);

        $bill = $this->bill('10', '10000');
        $return = $this->giveBack($bill, '1', $today);

        // প্রস্তুতি: বিল ১,০০,০০০ + VAT ১০,০০০ আর ফেরত ১০,০০০ + VAT ১,০০০, দুইটাই খাতায় বসা, আজকের তারিখে
        $this->assertSame('110000.0000', bcadd((string) $bill->total, '0', 4), 'প্রস্তুতিটাই ভুল — বিলটা VAT সহ ১,১০,০০০ নয়।');
        $this->assertSame('10000.0000', bcadd((string) $bill->tax, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে VAT ১০,০০০ নয়।');
        $this->assertSame('11000.0000', bcadd((string) $return->total, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতটা VAT সহ ১১,০০০ নয়।');
        $this->assertSame('1000.0000', bcadd((string) $return->tax, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতে VAT ১,০০০ নয়।');
        $this->assertContains($return->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — ফেরত খাতায় বসেনি।');
        $this->assertSame($today, $return->trx_date->toDateString());

        // ── SalesMetrics ──
        $metrics = $this->metrics($today);
        $this->assertSame('100000.0000', bcsub($metrics['billed'], $metricsBefore['billed'], 4), '⛔ বিলের অঙ্ক ভুল।');
        $this->assertSame('10000.0000', bcsub($metrics['returned'], $metricsBefore['returned'], 4), '⛔ ফেরত গোনা হয়নি।');
        $this->assertSame('90000.0000', bcsub($metrics['net'], $metricsBefore['net'], 4), '⛔ নিট = বিল − ফেরত নয় (VAT বাদে)।');
        $this->assertSame('9000.0000', bcsub($metrics['vat'], $metricsBefore['vat'], 4), '⛔ VAT আলাদা আসেনি (বিলের VAT − ফেরতের VAT)।');
        $this->assertSame('99000.0000', bcsub($metrics['gross'], $metricsBefore['gross'], 4), '⛔ VAT সহ মোট (আদায়যোগ্য) ভুল।');
        $this->assertSame($metrics['net'], $metrics['today'], '⛔ sales.today নিট নয়।');

        // ── ফোন: amount নিট, পাশে billed, returned, label ──
        $after = $this->phone($this->owner)->json('sales');
        foreach (['amount', 'billed', 'returned', 'vat', 'label'] as $key) {
            $this->assertArrayHasKey($key, $after, "⛔ ফোনের sales.{$key} নেই।");
        }
        $this->assertSame('90000.0000', bcsub($after['amount'], $before['amount'], 4), '⛔ ফোনের sales.amount নিট নয়।');
        $this->assertSame('100000.0000', bcsub($after['billed'], $before['billed'], 4), '⛔ ফোনের sales.billed ভুল।');
        $this->assertSame('10000.0000', bcsub($after['returned'], $before['returned'], 4), '⛔ ফোনের sales.returned ভুল।');
        $this->assertSame('9000.0000', bcsub($after['vat'], $before['vat'], 4), '⛔ ফোনের sales.vat ভুল।');
        $this->assertSame(bcsub($after['billed'], $after['returned'], 4), $after['amount'], '⛔ ফোনে amount ≠ billed − returned।');
        foreach (['amount', 'billed', 'returned', 'vat'] as $key) {
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{4}$/', $after[$key], "⛔ sales.{$key} চার ঘরের টাকা-স্ট্রিং নয়।");
        }
        $this->assertSame(self::NET_LABEL, $after['label'], '⛔ ফোনের নাম "নিট বিক্রি" নয়।');

        // ── ওয়েবের হোম কার্ড = ফোন, আর খোলা-ঘরে বিল আর ফেরত ──
        $this->web($this->owner);
        $card = $this->homeCard();
        $this->assertSame(Money::format($after['amount']), $card['value'], '⛔ ওয়েবের কার্ড আর ফোন দুই অঙ্ক।');
        $this->assertStringContainsString(Money::format($after['billed']), $card['title'], '⛔ কার্ড খুললে বিল দেখায় না।');
        $this->assertStringContainsString(Money::format($after['returned']), $card['title'], '⛔ কার্ড খুললে ফেরত দেখায় না।');
        $this->assertStringContainsString('VAT সহ মোট (আদায়যোগ্য) '.Money::format(bcadd($after['amount'], $after['vat'], 4)), $card['title'],
            '⛔ কার্ড খুললে VAT সহ মোট আলাদা লাইনে নেই।');

        // ── মালিকের ছক = ফোন ──
        $board = $this->board();
        $this->assertSame($after['amount'], bcadd((string) $board['values'][Figures::SALES], '0', 4), '⛔ ছকের বিক্রি আর ফোন দুই অঙ্ক।');
        $this->assertNotNull($board['sales_parts'], 'প্রস্তুতিটাই ভুল — মালিক ছকের বিক্রি দেখেন না।');
        $this->assertSame($after['billed'], bcadd($board['sales_parts']['billed'], '0', 4), '⛔ ছকের বিল আর ফোন দুই অঙ্ক।');
        $this->assertSame($after['returned'], bcadd($board['sales_parts']['returned'], '0', 4), '⛔ ছকের ফেরত আর ফোন দুই অঙ্ক।');
        $this->assertSame($after['vat'], bcadd($board['sales_parts']['vat'], '0', 4), '⛔ ছকের VAT আর ফোন দুই অঙ্ক।');

        // ── হোম-স্ক্রিন উইজেট (sales_today) = ফোন — উইজেটও নিট, একই নাম আর একই ভাগ (a5, ২১ অক্টোবর ২০২৬) ──
        $this->actingAs($this->owner);
        $widget = app(\App\Modules\Sales\Widgets\SalesTodaySummary::class)->data($this->owner->fresh());
        $this->assertSame($after['amount'], $widget['total'], '⛔ উইজেট আর ফোন দুই অঙ্ক — উইজেট এখনো মোট বিল।');
        $this->assertSame($after['billed'], $widget['billed'], '⛔ উইজেটের বিল আর ফোন দুই অঙ্ক।');
        $this->assertSame($after['returned'], $widget['returned'], '⛔ উইজেটের ফেরত আর ফোন দুই অঙ্ক।');
        $this->assertSame($after['vat'], $widget['vat'], '⛔ উইজেটের VAT আর ফোন দুই অঙ্ক।');
        $this->assertSame(self::NET_LABEL, $widget['label'], '⛔ উইজেটের নাম "নিট বিক্রি" নয়।');
    }

    public function test_a_return_dated_yesterday_does_not_lower_today(): void
    {
        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();
        $before = $this->metrics($today);

        $bill = $this->bill('10', '10000');
        $return = $this->giveBack($bill, '1', $yesterday);

        $this->assertSame($yesterday, $return->trx_date->toDateString(), 'প্রস্তুতিটাই ভুল — ফেরতটা গতকালের নয়।');
        $this->assertSame($today, $bill->trx_date->toDateString(), 'প্রস্তুতিটাই ভুল — বিলটা আজকের নয়।');
        $this->assertContains($return->status, DocumentStatus::POSTED);

        $after = $this->metrics($today);
        $this->assertSame('0.0000', bcsub($after['returned'], $before['returned'], 4), '⛔ গতকালের ফেরত আজ গোনা হলো — বিলের তারিখে।');
        $this->assertSame('100000.0000', bcsub($after['net'], $before['net'], 4), '⛔ গতকালের ফেরত আজকের বিক্রি কমাল।');
        $this->assertSame('10000.0000', SalesMetrics::returned($yesterday, $yesterday), '⛔ ফেরতটা নিজের দিনে নেই।');

        $phone = $this->phone($this->owner)->json('sales');
        $this->assertSame($phone['billed'], $phone['amount'], '⛔ ফোনে গতকালের ফেরত আজকের বিক্রি কমাল।');
    }

    public function test_a_return_today_of_yesterdays_bill_lowers_today(): void
    {
        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();

        $bill = $this->bill('10', '10000', $yesterday);
        $before = $this->metrics($today);
        $this->giveBack($bill, '1', $today);
        $after = $this->metrics($today);

        $this->assertSame($yesterday, $bill->trx_date->toDateString(), 'প্রস্তুতিটাই ভুল — বিলটা গতকালের নয়।');
        $this->assertSame('0.0000', bcsub($after['billed'], $before['billed'], 4), 'প্রস্তুতিটাই ভুল — আজ নতুন বিল হলো।');
        $this->assertSame('10000.0000', bcsub($after['returned'], $before['returned'], 4), '⛔ আজকের ফেরত আজ গোনা হলো না।');
        $this->assertSame('-10000.0000', bcsub($after['net'], $before['net'], 4), '⛔ আজকের ফেরত আজকের নিট কমাল না।');
    }

    public function test_the_branch_rows_are_net_and_add_up_to_the_total(): void
    {
        $bill = $this->bill('10', '10000');
        $this->giveBack($bill, '1', Carbon::today()->toDateString());

        $body = $this->phone($this->owner)->json();
        $rows = $body['salesByBranch'];
        $sum = array_reduce($rows, fn (string $s, array $r) => bcadd($s, $r['amount'], 4), '0.0000');

        $this->assertSame($body['sales']['amount'], $body['salesByBranchTotal'], '⛔ শাখার মোট আর নিট বিক্রি দুই অঙ্ক।');
        $this->assertSame($body['salesByBranchTotal'], $sum, '⛔ শাখার সারির যোগ মোট নয় — সারিগুলো নিট নয়।');

        foreach ($rows as $row) {
            $this->assertSame(['branch_id', 'branch', 'amount', 'billed', 'returned'], array_keys($row));
            $this->assertSame(bcsub($row['billed'], $row['returned'], 4), $row['amount'], '⛔ শাখার সারি নিট নয়।');
        }

        $here = collect($rows)->firstWhere('branch_id', (int) $bill->branch_id);
        $this->assertNotNull($here, 'প্রস্তুতিটাই ভুল — বিলের শাখার সারি নেই।');
        $this->assertSame(1, bccomp($here['returned'], '9999.9999', 4), '⛔ ফেরতটা নিজের শাখার সারিতে নেই।');
    }

    public function test_a_walled_salesman_nets_only_their_own_dealers(): void
    {
        $sr = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->assertTrue(app(DealerScope::class)->walled($sr), 'প্রস্তুতিটাই ভুল — ডেমোর বিক্রয়কর্মী দেয়ালে নেই।');
        $this->grant($sr, 'sales.invoice.view');

        $before = $this->phone($sr)->json('sales');

        $own = $this->bill('10', '10000', null, 'Rahim Traders');
        $this->giveBack($own, '1', Carbon::today()->toDateString());
        $other = $this->bill('5', '7000', null, 'Niloy Store');
        $this->giveBack($other, '2', Carbon::today()->toDateString());

        $after = $this->phone($sr)->json('sales');
        $this->assertSame('100000.0000', bcsub($after['billed'], $before['billed'], 4), '⛔ বিক্রয়কর্মীর বিলে অন্য ডিলার।');
        $this->assertSame('10000.0000', bcsub($after['returned'], $before['returned'], 4), '⛔ বিক্রয়কর্মীর ফেরতে অন্য ডিলার।');
        $this->assertSame('90000.0000', bcsub($after['amount'], $before['amount'], 4), '⛔ বিক্রয়কর্মীর নিটে অন্য ডিলার।');

        // ⓘ একই মানুষ, দেয়াল ছাড়া মালিক — দুইটা ডিলারই
        $owner = $this->phone($this->owner)->json('sales');
        $this->assertSame(1, bccomp($owner['returned'], $after['returned'], 4), 'প্রস্তুতিটাই ভুল — অন্য ডিলারের ফেরত ওঠেনি।');
    }

    public function test_the_labels_read_net_sales(): void
    {
        $this->bill('1', '500');

        $this->assertSame(self::NET_LABEL, $this->phone($this->owner)->json('sales.label'));

        $this->web($this->owner);
        $this->assertSame(self::NET_LABEL, $this->homeCard()['label'], '⛔ ওয়েবের কার্ডের নাম "নিট বিক্রি" নয়।');

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->get(route('executive.today'))->assertOk()->getContent());
        $cell = (new DOMXPath($dom))->query('//*[@data-headline]//*[@data-figure="'.Figures::SALES.'"]/span[1]')->item(0);
        $this->assertNotNull($cell, 'প্রস্তুতিটাই ভুল — মালিকের ছকে বিক্রির ঘর নেই।');
        $this->assertSame(self::NET_LABEL, trim($cell->textContent), '⛔ মালিকের ছকের ঘরের নাম "নিট বিক্রি" নয়।');
    }

    /** ⭐ বিক্রয়ের সারাংশ: "নিট বিক্রি (VAT ও ফেরত বাদে)" কার্ড আর বারো মাসের নিট ঘর — একই উৎস (fe-র ৩ নম্বর, ২০ অক্টোবর ২০২৬) */
    public function test_the_overview_net_card_and_the_twelve_months_are_net_of_vat_and_returns(): void
    {
        $before = $this->overviewNet();

        $bill = $this->bill('10', '10000');
        $this->giveBack($bill, '1', Carbon::today()->toDateString());
        $this->assertSame('10000.0000', bcadd((string) $bill->tax, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে VAT নেই।');

        $after = $this->overviewNet();
        $this->assertSame('90000.0000', bcsub($after['card'], $before['card'], 4), '⛔ সারাংশের নিট কার্ড VAT বা ফেরত বাদ দেয় না।');
        $this->assertSame('90000.0000', bcsub($after['month'], $before['month'], 4), '⛔ বারো মাসের এই মাসের নিট VAT বা ফেরত বাদ দেয় না।');
        $this->assertSame(self::FULL_LABEL, $after['label'], '⛔ কার্ডের নাম "নিট বিক্রি (VAT ও ফেরত বাদে)" নয়।');

        // ⓘ সারাংশ = হোম/ফোনের একই উৎস — এই মাসে
        $this->web($this->owner);
        $this->assertSame(SalesMetrics::net(Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()), $after['month']);
    }

    /** ⭐ এই মাস, এই বছর, সাত দিনের রেখা আর গত সপ্তাহের তুলনা — সব নিট বিক্রিতে (fe-র ৩ নম্বর, ২০ অক্টোবর ২০২৬) */
    public function test_month_year_spark_and_week_on_week_are_net_of_vat_and_returns(): void
    {
        $today = Carbon::today()->toDateString();
        $lastWeek = Carbon::today()->subWeek()->toDateString();
        $this->web($this->owner);
        $month = fn () => bcadd(SalesMetrics::salesThisMonth()->value(), '0', 4);
        $year = fn () => bcadd(SalesMetrics::salesThisYear()->value(), '0', 4);
        [$monthBefore, $yearBefore] = [$month(), $year()];

        // গত সপ্তাহের একই বারে: বিল ১,০০,০০০ + VAT, ফেরত ৫০,০০০ + VAT → নিট ৫০,০০০; আজ: বিল ১,০০,০০০ + VAT, ফেরত ১০,০০০ + VAT → নিট ৯০,০০০
        $old = $this->bill('10', '10000', $lastWeek);
        $this->giveBack($old, '5', $lastWeek);
        $new = $this->bill('10', '10000');
        $this->giveBack($new, '1', $today);

        $this->web($this->owner);
        $this->assertNotSame(SalesMetrics::invoiceTotal($today, $today), SalesMetrics::net($today, $today), 'প্রস্তুতিটাই ভুল — আজ বিল আর নিট এক।');
        // ⓘ গত সপ্তাহের দিনটা এই মাসে/এই অর্থবছরে পড়লে তার নিট ৫০,০০০-ও যোগ হয়
        $fyStart = SalesMetrics::financialYear()[0];
        $this->assertSame(bcadd('90000', Carbon::parse($lastWeek)->isSameMonth(Carbon::today()) ? '50000' : '0', 4),
            bcsub($month(), $monthBefore, 4), '⛔ এই মাসের বিক্রি নিট নয় (VAT ও ফেরত বাদে)।');
        $this->assertSame(bcadd('90000', $lastWeek >= $fyStart ? '50000' : '0', 4),
            bcsub($year(), $yearBefore, 4), '⛔ এই বছরের বিক্রি নিট নয় (VAT ও ফেরত বাদে)।');

        // ── সাত দিনের রেখা: প্রতিটা দিন সেই দিনের নিট ──
        $card = collect(\App\Modules\Sales\Dashboard\SalesWidgets::widgets())->first(fn ($w) => $w->group === 'kpi' && $w->sort === 10);
        $this->assertNotNull($card, 'প্রস্তুতিটাই ভুল — হোমের বিক্রির কার্ড নেই।');
        $this->assertCount(7, $card->spark);
        $this->assertSame(SalesMetrics::net($today, $today), bcadd((string) $card->spark[6], '0', 4), '⛔ রেখার আজকের বিন্দু নিট নয়।');
        $sixAgo = Carbon::today()->subDays(6)->toDateString();
        $this->assertSame(SalesMetrics::net($sixAgo, $sixAgo), bcadd((string) $card->spark[0], '0', 4), '⛔ রেখার প্রথম বিন্দু নিট নয়।');

        // ── গত সপ্তাহের তুলনা: নিট বনাম নিট ──
        $now = SalesMetrics::net($today, $today);
        $then = SalesMetrics::net($lastWeek, $lastWeek);
        $this->assertSame(1, bccomp($then, '0', 4), 'প্রস্তুতিটাই ভুল — গত সপ্তাহের নিট শূন্য।');
        $change = bcdiv(bcmul(bcsub($now, $then, 4), '100', 6), $then, 1);
        $this->assertSame((bccomp($change, '0', 1) >= 0 ? '+' : '').$change.'%', $card->delta, '⛔ গত সপ্তাহের তুলনা নিট বিক্রিতে নয়।');

        // ── নাম ──
        app()->setLocale('bn');
        $this->assertStringContainsString(self::FULL_LABEL, SalesMetrics::salesThisMonth()->label);
        $this->assertStringContainsString(self::FULL_LABEL, SalesMetrics::salesThisYear()->label);
    }

    /** ⭐ ক্রেতা, পণ্য আর ব্র্যান্ড ধরে বিক্রি — নিট (VAT ও ফেরত বাদে), মুনাফাও নিট (fe-র ৩ নম্বর, ২০ অক্টোবর ২০২৬) */
    public function test_the_customer_product_and_brand_reports_are_net_of_vat_and_returns(): void
    {
        $today = Carbon::today()->toDateString();
        $rice = Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail();
        $rahim = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $before = $this->reports($rahim, $rice);

        $bill = $this->bill('10', '10000');
        $return = $this->giveBack($bill, '1', $today);
        $this->assertSame(1, bccomp((string) $return->cost_of_goods, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতের খরচ শূন্য, তাই মুনাফার দাবি কিছু মাপে না।');
        $this->assertSame('10000.0000', bcadd((string) $bill->tax, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে VAT নেই।');

        $after = $this->reports($rahim, $rice);
        $d = fn (string $report, string $column) => bcsub($after[$report][$column], $before[$report][$column], 4);

        // ── ক্রেতা ধরে ──
        $this->assertSame('10000.0000', $d('customer', 'returned'), '⛔ ক্রেতার সারিতে ফেরত (VAT বাদে) নেই।');
        $this->assertSame('90000.0000', $d('customer', 'net_sales'), '⛔ ক্রেতার নিট বিক্রি VAT বা ফেরত বাদ দেয় না।');
        $this->assertSame(bcsub('90000', bcsub((string) $bill->cost_of_goods, (string) $return->cost_of_goods, 4), 4), $d('customer', 'gross_profit'),
            '⛔ ক্রেতার মুনাফা নিট নয় — ফেরতের নিট বা ফেরত আসা মালের খরচ বাদ পড়েছে।');

        // ── পণ্য ধরে ──
        $this->assertSame('10000.0000', $d('product', 'returned'), '⛔ পণ্যের সারিতে ফেরত নেই।');
        $this->assertSame('90000.0000', $d('product', 'revenue'), '⛔ পণ্যের বিক্রি VAT বা ফেরত বাদ দেয় না।');
        $this->assertSame(bcsub($d('product', 'revenue'), $d('product', 'cost'), 4), $d('product', 'gross_profit'));
        $this->assertSame(bcsub('90000', bcsub((string) $bill->cost_of_goods, (string) $return->cost_of_goods, 4), 4), $d('product', 'gross_profit'),
            '⛔ পণ্যের মুনাফা নিট নয়।');
        $this->assertSame('9.0000', $d('product', 'qty'), '⛔ পণ্যের পরিমাণে ফেরত বাদ যায়নি।');

        // ── ব্র্যান্ড ধরে ──
        $this->assertSame('90000.0000', $d('brand', 'revenue'), '⛔ ব্র্যান্ডের বিক্রি VAT বা ফেরত বাদ দেয় না।');

        // ── একই উৎস: ক্রেতা ধরে রিপোর্টের নিট যোগ = হোমের নিট বিক্রি ──
        $this->assertSame(SalesMetrics::net($today, $today), $after['customer_total'], '⛔ রিপোর্টের নিট আর হোমের নিট বিক্রি দুই অঙ্ক।');

        // ⓘ নাম
        app()->setLocale('bn');
        $this->assertSame(self::FULL_LABEL, __('sales::field.net_sales'));
    }

    /**
     * ⭐ খাতাই বিচারক (fe, ২১ অক্টোবর ২০২৬): দিনের নিট বিক্রি = বিক্রয় খাতের (৪১০০) জমা − খরচ বাদ বিক্রয় ফেরতের খাতের (৪১১০)
     * খরচ − জমা, পয়সায়; VAT = প্রদেয় VAT-এর (২১২০) নড়াচড়া; বিলে যোগ করা গাড়িভাড়া = নিজের আয়ের খাতের (৪৩৬০) নড়াচড়া — বিক্রয়ে নয়।
     * ⓘ খাতগুলো পোস্টিং কোডের নিজের ([[SalesInvoiceService::postToLedger()]], [[SalesReturnService::postToLedger()]])।
     */
    public function test_net_sales_vat_and_freight_reconcile_to_the_ledger(): void
    {
        $today = Carbon::today()->toDateString();
        $before = $this->books($today);

        $bill = $this->bill('10', '10000', null, 'Rahim Traders', '500');
        $this->giveBack($bill, '1', $today);
        $this->assertSame('500.0000', bcadd((string) $bill->freight_charge, '0', 4), 'প্রস্তুতিটাই ভুল — বিলে গাড়িভাড়া নেই।');
        $this->assertSame('110500.0000', bcadd((string) $bill->total, '0', 4), 'প্রস্তুতিটাই ভুল — বিলটা VAT আর ভাড়াসহ ১,১০,৫০০ নয়।');

        $after = $this->books($today);
        $d = fn (string $k) => bcsub($after[$k], $before[$k], 4);

        $this->assertSame('90000.0000', $d('net'), '⛔ নিট বিক্রি ৯০,০০০ নয় — ভাড়া বা VAT নিটে ঢুকেছে।');
        $this->assertSame($d('net'), bcsub($d('sales'), $d('returns'), 4), '⛔ নিট বিক্রি খাতার বিক্রয় − বিক্রয় ফেরতের সমান নয়।');
        $this->assertSame($d('vat'), $d('vat_account'), '⛔ VAT খাতার প্রদেয় VAT-এর নড়াচড়ার সমান নয়।');
        $this->assertSame('9000.0000', $d('vat'));
        $this->assertSame('500.0000', $d('freight_account'), '⛔ গাড়িভাড়া নিজের আয়ের খাতে যায়নি।');

        // ⭐ পুরো দিনের মিল — শুধু এই দুই কাগজ নয়
        $this->assertSame($after['net'], bcsub($after['sales'], $after['returns'], 4), '⛔ দিনের নিট বিক্রি খাতার সাথে মেলে না।');
        $this->assertSame($after['vat'], $after['vat_account'], '⛔ দিনের VAT খাতার সাথে মেলে না।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @return array{net: string, vat: string, sales: string, returns: string, vat_account: string, freight_account: string} গোটা কোম্পানি, এক দিন */
    private function books(string $day): array
    {
        $this->web($this->owner);
        CompanyContext::set((int) $this->company->id, null);
        $parts = SalesMetrics::netParts($day, $day);
        $move = function (string $code, bool $credit) use ($day): string {
            $id = \App\Modules\Accounts\Models\Account::query()->where('code', $code)->value('id');
            $this->assertNotNull($id, "প্রস্তুতিটাই ভুল — {$code} খাতটা নেই।");
            $row = \Illuminate\Support\Facades\DB::table('ledger_entries')->where('company_id', $this->company->id)
                ->where('account_id', $id)->whereDate('trx_date', $day)
                ->selectRaw('COALESCE(SUM(credit), 0) as c, COALESCE(SUM(debit), 0) as d')->first();

            return $credit ? bcsub((string) $row->c, (string) $row->d, 4) : bcsub((string) $row->d, (string) $row->c, 4);
        };

        return [
            'net' => $parts['net'],
            'vat' => $parts['vat'],
            'sales' => $move(\App\Modules\Accounts\Services\StandardChart::SALES, true),
            'returns' => $move(\App\Modules\Accounts\Services\StandardChart::SALES_RETURN, false),
            'vat_account' => $move(\App\Modules\Accounts\Services\StandardChart::VAT_PAYABLE, true),
            'freight_account' => $move(\App\Modules\Accounts\Services\StandardChart::FREIGHT_INCOME, true),
        ];
    }

    /** @return array{customer: array<string, string>, product: array<string, string>, brand: array<string, string>, customer_total: string} */
    private function reports(Customer $customer, Product $product): array
    {
        $this->web($this->owner);
        $engine = app(\App\Core\Engines\Report\ReportEngine::class);
        $today = ['from' => Carbon::today()->toDateString(), 'to' => Carbon::today()->toDateString()];
        $pick = function (array $rows, string $key, mixed $id, array $columns): array {
            $row = collect($rows)->map(fn ($r) => (array) $r)->first(fn (array $r) => (string) ($r[$key] ?? '') === (string) ($id ?? ''));

            return collect($columns)->mapWithKeys(fn (string $c) => [$c => bcadd((string) ($row[$c] ?? '0'), '0', 4)])->all();
        };

        $customers = $engine->run('sales.by_customer', $today, 1, 500)->rows;

        return [
            'customer' => $pick($customers, 'customer_id', $customer->id, ['returned', 'net_sales', 'gross_profit']),
            'product' => $pick($engine->run('sales.by_product', $today, 1, 500)->rows, 'product_id', $product->id, ['qty', 'returned', 'revenue', 'cost', 'gross_profit']),
            'brand' => $pick($engine->run('sales.by_brand', $today, 1, 500)->rows, 'brand_id', $product->brand_id, ['revenue']),
            'customer_total' => array_reduce($customers, fn (string $s, $r) => bcadd($s, (string) ((array) $r)['net_sales'], 4), '0.0000'),
        ];
    }

    /** @return array{card: string, month: string, label: string} সারাংশের নিট কার্ড (এ মাস) আর বারো মাসের এই মাসের ঘর */
    private function overviewNet(): array
    {
        $this->web($this->owner);
        $response = $this->get(route('sales.overview', ['period' => 'month']))->assertOk();
        $card = collect($response->viewData('cards'))->firstWhere('key', 'sales.overview.net');
        $this->assertNotNull($card, 'প্রস্তুতিটাই ভুল — সারাংশে নিট কার্ড নেই।');
        $month = collect($response->viewData('panels')['monthly']['rows'])->firstWhere('ym', Carbon::today()->format('Y-m'));
        $this->assertNotNull($month, 'প্রস্তুতিটাই ভুল — বারো মাসে এই মাস নেই।');

        return ['card' => bcadd((string) $card['raw'], '0', 4), 'month' => bcadd((string) $month['net'], '0', 4), 'label' => (string) $card['label']];
    }

    /** @return array{billed: string, returned: string, net: string, vat: string, gross: string, today: string} */
    private function metrics(string $day): array
    {
        $this->web($this->owner);
        $parts = SalesMetrics::netParts($day, $day);

        return [
            'vat' => $parts['vat'],
            'gross' => $parts['gross'],
            'billed' => SalesMetrics::billed($day, $day),
            'returned' => SalesMetrics::returned($day, $day),
            'net' => SalesMetrics::net($day, $day),
            'today' => bcadd(SalesMetrics::salesToday()->value(), '0', 4),
        ];
    }

    private function bill(string $qty, string $rate, ?string $on = null, string $customer = 'Rahim Traders', string $freight = '0'): SalesInvoice
    {
        $this->web($this->owner);
        CompanyContext::set((int) $this->company->id, (int) $this->warehouse->branch_id);
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create([
            'customer_id' => Customer::acrossDealers()->where('name_en', $customer)->firstOrFail()->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => $on ?? Carbon::today()->toDateString(),
            'freight_charge' => $freight,
        ], [[
            'product_id' => Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail()->id,
            'qty' => $qty, 'rate' => $rate,
        ]]))->fresh('lines');
    }

    private function giveBack(SalesInvoice $bill, string $qty, string $on): SalesReturn
    {
        $this->web($this->owner);
        CompanyContext::set((int) $this->company->id, (int) $bill->branch_id);
        $returns = app(SalesReturnService::class);

        return $returns->confirm($returns->create([
            'customer_id' => $bill->customer_id,
            'warehouse_id' => $bill->warehouse_id,
            'sales_invoice_id' => $bill->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => $on,
        ], [[
            'product_id' => $bill->lines->first()->product_id,
            'sales_invoice_line_id' => $bill->lines->first()->id,
            'qty' => $qty,
        ]]))->fresh();
    }

    /** @return array{label: string, value: string, title: string} ওয়েবের হোমের মূল সূচকের নিট বিক্রির কার্ড */
    private function homeCard(): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->get(route('dashboard'))->assertOk()->getContent());
        $page = new DOMXPath($dom);

        foreach ($page->query('//a[@data-kpi]') as $kpi) {
            $label = trim($page->query('.//span[contains(@class, "truncate")]/span[contains(@class, "truncate")]', $kpi)->item(0)?->textContent ?? '');

            if ($label === self::NET_LABEL) {
                return [
                    'label' => $label,
                    'value' => trim($page->query('.//span[contains(@class, "hm-kpi-value")]', $kpi)->item(0)?->textContent ?? ''),
                    'title' => (string) $kpi->getAttribute('title'),
                ];
            }
        }

        $this->fail('⛔ হোমের মূল সূচকে "নিট বিক্রি" কার্ড নেই।');
    }

    /** @return array<string, mixed> মালিকের ছকে এই কোম্পানির সারি */
    private function board(): array
    {
        app(Board::class)->refresh($this->owner);
        $company = collect(app(Board::class)->build($this->owner->fresh(), Figures::TODAY)['companies'])->firstWhere('id', (int) $this->company->id);
        $this->assertNotNull($company, 'প্রস্তুতিটাই ভুল — ছকে কোম্পানিটাই নেই।');

        return $company;
    }

    private function web(User $user): void
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        $this->actingAs($user->fresh());
        CompanyContext::set((int) $this->company->id, $this->company->defaultBranch()?->id);
    }

    private function phone(User $user): TestResponse
    {
        $this->app['auth']->forgetGuards();
        app(DataScope::class)->forget();
        app(DealerScope::class)->forget();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/dashboard/today')->assertOk();
    }

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany((int) $this->company->id,
            fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
