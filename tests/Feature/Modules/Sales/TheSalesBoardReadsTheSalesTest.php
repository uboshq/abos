<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Dashboard\SalesBoard;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ বিক্রয়ের ড্যাশবোর্ড — মালিকের চূড়ান্ত নমুনা H8JXFPzh8m7zpsQSedmPDT (10 নভেম্বর 2026 রাত)। [[SalesBoard]]
 *
 * দাবি: PVM সেতু হাতে গোনা অঙ্কে মেলে আর যোগফল হুবহু (আগে + পরিমাণ + দাম + মিশ্রণ + নতুন + বাদ পড়া = এখন); গ্রাহক ধরে রাখার নতুন,
 * ফিরে আসা আর ধরে রাখার হার; সেরা পণ্য আর সবচেয়ে বেশি কমা পণ্য; পাতায় কেবল নমুনার সাত সারি, ক্রমে।
 */
final class TheSalesBoardReadsTheSalesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    /** @var array<string, Product> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00'));
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        foreach (['A', 'B', 'C', 'D'] as $k) {
            $this->products[$k] = Product::query()->create(['code' => 'SB-'.$k.'-'.mb_substr(md5(microtime().$k), 0, 6), 'name_en' => 'Board product '.$k,
                'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
        }

        // ⓘ দুই সময়ে বোর্ডের পরিচিত বিল ছাড়া আর কিছু যেন না থাকে — ডেমোর পাকা বিল বাতিল ধরে সরানো
        SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->update(['status' => DocumentStatus::CANCELLED]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * ⭐ গত মাসের 10 তারিখে A 10×100, B 5×200, D 2×150; এ মাসের 10 তারিখে A 12×110, B 4×200, C 3×200 (নতুন)।
     * পরিমাণ = (16 − 15) × 2000/15 = 133.33; মিশ্রণ = (100×12 + 200×4) − 16 × 133.33 = −133.33; দাম = 2120 − 2000 = 120; নতুন 600; বাদ −300।
     */
    public function test_the_price_volume_mix_bridge_matches_the_hand_sum_and_adds_up_exactly(): void
    {
        $shop = Customer::query()->firstOrFail();
        $this->invoice('2026-10-10', $shop, [['A', 10, 1000], ['B', 5, 1000], ['D', 2, 300]]);
        $this->invoice('2026-11-10', $shop, [['A', 12, 1320], ['B', 4, 800], ['C', 3, 600]]);

        $p = app(SalesBoard::class)->pvm();

        $this->assertSame(['2300.0000', '2720.0000'], [$p['before'], $p['after']], '⛔ দুই সময়ের বিক্রি ভুল।');
        $this->assertSame('133.3333', $p['volume'], '⛔ পরিমাণের প্রভাব ভুল।');
        $this->assertSame('-133.3333', $p['mix'], '⛔ মিশ্রণের প্রভাব ভুল।');
        $this->assertSame('120.0000', $p['price'], '⛔ দামের প্রভাব ভুল।');
        $this->assertSame(['600.0000', '-300.0000'], [$p['new'], $p['dropped']], '⛔ নতুন বা বাদ পড়া পণ্য ভুল।');

        $sum = array_reduce(['volume', 'mix', 'price', 'new', 'dropped'], fn (string $c, string $k) => bcadd($c, $p[$k], 4), $p['before']);
        $this->assertSame(0, bccomp($sum, $p['after'], 2), '⛔ সেতুর ধাপগুলো যোগ করে এখনকার বিক্রিতে পৌঁছায় না।');

        $products = app(SalesBoard::class)->products();
        $this->assertSame('Board product A', $products['top'][0]['name'], '⛔ সবচেয়ে বেশি বিক্রির পণ্য প্রথমে নয়।');
        $this->assertSame(['Board product D', -100], [$products['falling'][0]['name'], $products['falling'][0]['pct']], '⛔ সবচেয়ে বেশি কমা পণ্য (−100%) প্রথমে নয়।');
    }

    /** ⭐ গত মাসে X ও Y কিনেছে; এ মাসে X (ধরে রাখা), Z (নতুন) আর W (আগস্টে কিনেছিল — ফিরে এল) — ধরে রাখা 50%, নতুন 1, ফিরে এল 1 */
    public function test_retention_counts_kept_new_and_returning_shops(): void
    {
        $shops = Customer::query()->limit(4)->get()->values();
        $this->assertCount(4, $shops, 'প্রস্তুতিটাই ভুল — ডেমোতে চারটা গ্রাহক নেই।');
        [$x, $y, $z, $w] = [$shops[0], $shops[1], $shops[2], $shops[3]];

        $this->invoice('2026-08-15', $w, [['A', 1, 100]]);
        $this->invoice('2026-10-12', $x, [['A', 1, 100]]);
        $this->invoice('2026-10-13', $y, [['A', 1, 100]]);
        $this->invoice('2026-11-05', $x, [['A', 1, 100]]);
        $this->invoice('2026-11-06', $z, [['A', 1, 100]]);
        $this->invoice('2026-11-07', $w, [['A', 1, 100]]);

        $r = app(SalesBoard::class)->retention();

        $this->assertSame(50, $r['kept'], '⛔ গত মাসের দুই দোকানের একটা এ মাসে কিনেছে — 50% নয়।');
        $this->assertSame(1, $r['new'], '⛔ নতুন দোকান ভুল গোনা।');
        $this->assertSame(1, $r['back'], '⛔ ফিরে আসা দোকান ভুল গোনা।');
        $this->assertSame(3, end($r['months'])['shops'], '⛔ এ মাসের চালু দোকান তিনটা নয়।');
    }

    /** ⭐ পাতায় কেবল নমুনার সাত সারি, ক্রমে — পুরনো কার্ড-চার্ট-তালিকা নেই */
    public function test_the_page_is_the_samples_seven_rows_in_order(): void
    {
        $html = (string) $this->get(route('module.dashboard', ['module' => 'sales']))->assertOk()->getContent();
        $marks = ['data-sales-problems', 'data-sales-glance', 'data-sales-ladder', 'data-sales-target', 'data-sales-products', 'data-sales-path', 'data-sales-pvm',
            'data-sales-retention', 'data-sales-cei', 'data-sales-o2c', 'data-sales-returns', 'data-sales-brands', 'data-sales-channels', 'data-sales-heat',
            'data-sales-sr-targets', 'data-sales-sr-commission', 'data-sales-top-two', 'data-sales-dealers', 'data-sales-branches', 'data-sales-pareto', 'data-sales-areas'];
        $at = [];

        foreach ($marks as $m) {
            $at[$m] = strpos($html, $m);
            $this->assertNotFalse($at[$m], '⛔ পাতায় '.$m.' নেই।');
        }

        $sorted = $at;
        asort($sorted);
        $this->assertSame($marks, array_keys($sorted), '⛔ ঘরগুলো নমুনার ক্রমে নেই।');
        $this->assertStringNotContainsString('data-stat-grid', $html, '⛔ পুরনো সংখ্যার কার্ড এখনো পাতায়।');
    }

    /** ⛔ একই মানুষ: বিক্রয়ের খরচের চাবি (sales.cost.view) ছাড়া লাভের সিঁড়ি নেই — কেনা দাম আর গ্রস লাভ; চাবি দিলে আছে */
    public function test_the_profit_ladder_needs_the_sales_cost_key(): void
    {
        $seller = User::factory()->create(['current_company_id' => $this->company->id]);
        $seller->companies()->attach($this->company->id);
        $seller->givePermissionTo('sales.invoice.view');

        $html = (string) $this->actingAs($seller->fresh())->get(route('module.dashboard', ['module' => 'sales']))->assertOk()->getContent();
        $this->assertStringContainsString('data-sales-ladder-hidden', $html, 'প্রস্তুতিটাই ভুল — সিঁড়ির ঘরই খোলেনি।');
        $this->assertStringNotContainsString('data-ladder-step', $html, '⛔ খরচের চাবি ছাড়াই কেনা দাম আর লাভ দেখা গেল।');

        $seller->givePermissionTo('sales.cost.view');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $html = (string) $this->actingAs($seller->fresh())->get(route('module.dashboard', ['module' => 'sales']))->assertOk()->getContent();
        $this->assertStringContainsString('data-ladder-step', $html, '⛔ খরচের চাবি দিলেও লাভের সিঁড়ি এল না।');
    }

    /** @param  list<array{0: string, 1: int, 2: int}>  $lines  পণ্য, পরিমাণ, টাকা */
    private function invoice(string $date, Customer $customer, array $lines): SalesInvoice
    {
        $total = (string) array_sum(array_column($lines, 2));
        $invoice = SalesInvoice::query()->create([
            'document_no' => 'SB-'.mb_substr(md5(microtime().$date.$customer->id), 0, 8), 'customer_id' => $customer->id,
            'branch_id' => CompanyContext::branchId(), 'warehouse_id' => Warehouse::query()->firstOrFail()->id, 'trx_date' => $date, 'due_on' => $date,
            'subtotal' => $total, 'discount' => '0', 'tax' => '0', 'total' => $total, 'status' => DocumentStatus::CONFIRMED,
        ]);

        foreach ($lines as $n => [$k, $qty, $amount]) {
            SalesInvoiceLine::query()->create(['sales_invoice_id' => $invoice->id, 'line_no' => $n + 1, 'product_id' => $this->products[$k]->id,
                'qty' => $qty, 'rate' => $amount / $qty, 'discount' => '0', 'tax' => '0', 'amount' => $amount, 'unit_cost' => '0']);
        }

        return $invoice;
    }
}
