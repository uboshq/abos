<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
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
 *   · আজ বিল ১,০০,০০০, ফেরত ১০,০০০ → নিট ৯০,০০০: SalesMetrics, ওয়েবের কার্ড, ফোনের `sales.amount`, ছকের SALES;
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

        // প্রস্তুতি: বিল ১,০০,০০০ আর ফেরত ১০,০০০, দুইটাই খাতায় বসা, আজকের তারিখে
        $this->assertSame('100000.0000', bcadd((string) $bill->total, '0', 4), 'প্রস্তুতিটাই ভুল — বিলটা ১,০০,০০০ নয়।');
        $this->assertSame('10000.0000', bcadd((string) $return->total, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতটা ১০,০০০ নয়।');
        $this->assertContains($return->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — ফেরত খাতায় বসেনি।');
        $this->assertSame($today, $return->trx_date->toDateString());

        // ── SalesMetrics ──
        $metrics = $this->metrics($today);
        $this->assertSame('100000.0000', bcsub($metrics['billed'], $metricsBefore['billed'], 4), '⛔ বিলের অঙ্ক ভুল।');
        $this->assertSame('10000.0000', bcsub($metrics['returned'], $metricsBefore['returned'], 4), '⛔ ফেরত গোনা হয়নি।');
        $this->assertSame('90000.0000', bcsub($metrics['net'], $metricsBefore['net'], 4), '⛔ নিট = বিল − ফেরত নয়।');
        $this->assertSame($metrics['net'], $metrics['today'], '⛔ sales.today নিট নয়।');

        // ── ফোন: amount নিট, পাশে billed, returned, label ──
        $after = $this->phone($this->owner)->json('sales');
        foreach (['amount', 'billed', 'returned', 'label'] as $key) {
            $this->assertArrayHasKey($key, $after, "⛔ ফোনের sales.{$key} নেই।");
        }
        $this->assertSame('90000.0000', bcsub($after['amount'], $before['amount'], 4), '⛔ ফোনের sales.amount নিট নয়।');
        $this->assertSame('100000.0000', bcsub($after['billed'], $before['billed'], 4), '⛔ ফোনের sales.billed ভুল।');
        $this->assertSame('10000.0000', bcsub($after['returned'], $before['returned'], 4), '⛔ ফোনের sales.returned ভুল।');
        $this->assertSame(bcsub($after['billed'], $after['returned'], 4), $after['amount'], '⛔ ফোনে amount ≠ billed − returned।');
        foreach (['amount', 'billed', 'returned'] as $key) {
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{4}$/', $after[$key], "⛔ sales.{$key} চার ঘরের টাকা-স্ট্রিং নয়।");
        }
        $this->assertSame(self::NET_LABEL, $after['label'], '⛔ ফোনের নাম "নিট বিক্রি" নয়।');

        // ── ওয়েবের হোম কার্ড = ফোন, আর খোলা-ঘরে বিল আর ফেরত ──
        $this->web($this->owner);
        $card = $this->homeCard();
        $this->assertSame(Money::format($after['amount']), $card['value'], '⛔ ওয়েবের কার্ড আর ফোন দুই অঙ্ক।');
        $this->assertStringContainsString(Money::format($after['billed']), $card['title'], '⛔ কার্ড খুললে বিল দেখায় না।');
        $this->assertStringContainsString(Money::format($after['returned']), $card['title'], '⛔ কার্ড খুললে ফেরত দেখায় না।');

        // ── মালিকের ছক = ফোন ──
        $board = $this->board();
        $this->assertSame($after['amount'], bcadd((string) $board['values'][Figures::SALES], '0', 4), '⛔ ছকের বিক্রি আর ফোন দুই অঙ্ক।');
        $this->assertNotNull($board['sales_parts'], 'প্রস্তুতিটাই ভুল — মালিক ছকের বিক্রি দেখেন না।');
        $this->assertSame($after['billed'], bcadd($board['sales_parts']['billed'], '0', 4), '⛔ ছকের বিল আর ফোন দুই অঙ্ক।');
        $this->assertSame($after['returned'], bcadd($board['sales_parts']['returned'], '0', 4), '⛔ ছকের ফেরত আর ফোন দুই অঙ্ক।');
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

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** @return array{billed: string, returned: string, net: string, today: string} */
    private function metrics(string $day): array
    {
        $this->web($this->owner);

        return [
            'billed' => SalesMetrics::billed($day, $day),
            'returned' => SalesMetrics::returned($day, $day),
            'net' => SalesMetrics::net($day, $day),
            'today' => bcadd(SalesMetrics::salesToday()->value(), '0', 4),
        ];
    }

    private function bill(string $qty, string $rate, ?string $on = null, string $customer = 'Rahim Traders'): SalesInvoice
    {
        $this->web($this->owner);
        CompanyContext::set((int) $this->company->id, (int) $this->warehouse->branch_id);
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create([
            'customer_id' => Customer::acrossDealers()->where('name_en', $customer)->firstOrFail()->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => $on ?? Carbon::today()->toDateString(),
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
