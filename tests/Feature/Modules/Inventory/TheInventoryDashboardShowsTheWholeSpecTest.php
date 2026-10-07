<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Dashboard\InventoryDashboard;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ মজুদ ড্যাশবোর্ডে মালিকের নকশার বাকিটা (৫ অক্টোবর ২০২৬): মোট পণ্য, ঋণাত্মক মজুদ, চালু লট, গুদামভিত্তিক মূল্য,
 * মজুদের স্বাস্থ্য, আর এ মাসের চলাচলের ধরন।
 *
 * ⓘ দাবিগুলো "চার্টটা আছে" নয় — প্রতিটা সংখ্যা আসল মাল বা খাতার সাথে মেলানো: কেনা পণ্যের গুদামের মূল্য বাড়ে ঠিক তার
 * খরচের স্তরের মূল্য ([[CostLayerService::valueOnHand()]]) — ফ্রি বাদে; ইচ্ছা করে শূন্যের নিচে নামানো জোড়াগুলোই ঋণাত্মক;
 * মাল থাকা লটগুলোই চালু; একটা পণ্য মাল নেই → ফুরিয়ে আসছে → ঠিক আছে-তে সরে; প্রতিটা উৎস ঠিক ধরনে গোনা।
 * ⛔ সুইচ বন্ধে একটাও নেই — চালুর আগে পুরনো ড্যাশবোর্ড যেমন ছিল।
 */
final class TheInventoryDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    public function test_every_new_figure_matches_the_stock_it_counts_and_the_switch_hides_them_all(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        config(['abos.dashboards_v2' => true]);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        // ── মোট পণ্য: একটা নতুন সচল পণ্য → এক বাড়ে ─────────────────────────
        $sku = $this->stat('total_sku');
        $bought = $this->newProduct('BUY');
        $this->assertSame($sku + 1, $this->stat('total_sku'), '⛔ নতুন পণ্য বানানোর পর মোট পণ্য এক বাড়েনি।');

        // ── গুদামের মূল্য: ১০০টা ৮০ টাকায় + ২০টা ফ্রি → গুদাম বাড়ে খরচের স্তরের মূল্যে, ফ্রি বাদে ─────
        $valueBefore = $this->warehouseValues();

        app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => Supplier::query()->firstOrFail()->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'WS-'.random_int(1000, 9999),
            ],
            [['product_id' => $bought->id, 'qty' => '100', 'free_qty' => '20', 'rate' => '80', 'sales_price' => '95']],
        );

        $book = app(CostLayerService::class)->valueOnHand($bought);
        $this->assertSame(0, bccomp($book, '8000', 4), 'খরচের স্তরে ১০০ × ৮০ বসেনি — দাবির ভিত নড়ে গেছে।');

        $valueAfter = $this->warehouseValues();
        $name = $this->warehouse->name();
        $this->assertArrayHasKey($name, $valueAfter, 'গুদামভিত্তিক মূল্যে ডিফল্ট গুদামই নেই।');
        $grew = bcsub($valueAfter[$name], $valueBefore[$name] ?? '0', 2);
        $this->assertSame(0, bccomp($grew, $book, 2), "⛔ গুদামের মূল্য বাড়ল {$grew}, খরচের স্তর {$book} — ফ্রি ২০টাও দামে ধরা, বা কেনা মাল বাদ।");

        foreach ($valueBefore as $other => $was) {
            if ($other !== $name) {
                $this->assertSame(0, bccomp($valueAfter[$other], $was, 2), "⛔ কেনা হলো এক গুদামে, বদলাল {$other}।");
            }
        }

        // ── ঋণাত্মক মজুদ: ইচ্ছা করে নামানো দুইটা জোড়া → ঠিক দুই; শূন্যে মেলা জোড়া গোনা নয় ─────
        $negative = $this->stat('negative_stock');

        $this->move($this->newProduct('NEG1'), ['floor_change' => '-5']);
        $freeShort = $this->newProduct('NEG2');
        $this->move($freeShort, ['floor_change' => '3']);
        $this->move($freeShort, ['free_change' => '-2']);
        $even = $this->newProduct('NEG3');
        $this->move($even, ['floor_change' => '5']);
        $this->move($even, ['floor_change' => '-5']);

        $this->assertSame($negative + 2, $this->stat('negative_stock'), '⛔ শূন্যের নিচে নামানো দুইটা জোড়া ঠিক দুইবার গোনা হয়নি।');
        $this->assertSame(Stat::BAD, $this->statObject('negative_stock')->tone, '⛔ ঋণাত্মক মজুদ আছে, তবু সংখ্যাটা লাল নয়।');

        // ── চালু লট: মাল থাকা লট (বসানো বাকি বা ফ্রি) গোনা, শেষ হয়ে যাওয়া লট নয় ─────────────
        $lots = $this->stat('active_batches');
        $lotProduct = $this->newProduct('LOT');

        $this->move($lotProduct, ['unplaced_change' => '10'], batch: $this->batch($lotProduct, 'WAIT'));
        $sold = $this->batch($lotProduct, 'SOLD');
        $this->move($lotProduct, ['floor_change' => '10'], batch: $sold);
        $this->move($lotProduct, ['floor_change' => '-10'], batch: $sold);
        $this->move($lotProduct, ['free_change' => '3'], batch: $this->batch($lotProduct, 'FREE'));

        $this->assertSame($lots + 2, $this->stat('active_batches'), '⛔ মাল থাকা দুইটা লট গোনা হয়নি, বা শেষ হয়ে যাওয়া লটও গোনা হয়েছে।');

        // ── স্বাস্থ্য: পুনঃক্রয় সীমা ১০-এর পণ্য — মাল নেই → ৫টা (ফুরিয়ে আসছে) → ২৫টা (ঠিক আছে) ─────
        $start = $this->health();
        $watched = $this->newProduct('HEALTH', reorder: '10');
        $this->assertSame($this->shift($start, out: 1), $this->health(), '⛔ মাল ছাড়া নতুন পণ্য "মাল নেই"-তে পড়েনি।');

        $this->move($watched, ['floor_change' => '5']);
        $this->assertSame($this->shift($start, low: 1), $this->health(), '⛔ সীমার নিচে মাল থাকা পণ্য "ফুরিয়ে আসছে"-তে পড়েনি।');

        $this->move($watched, ['floor_change' => '20']);
        $this->assertSame($this->shift($start, ok: 1), $this->health(), '⛔ সীমার উপরে উঠে পণ্যটা "ঠিক আছে"-তে যায়নি।');

        $health = $this->health();
        $this->assertSame($this->stat('total_sku'), $health['ok'] + $health['low'] + $health['out'], '⛔ স্বাস্থ্যের তিন ভাগ মিলে মোট পণ্য নয় — কেউ দুইবার গোনা বা বাদ।');

        // ── এ মাসের চলাচল: প্রতিটা উৎস নিজের ধরনে; বসানো, আটকানো আর গত মাস গোনা নয় ─────────────
        $kinds = $this->kinds();
        $moved = $this->newProduct('MOVE');

        $this->move($moved, ['floor_change' => '5'], source: 'stock_transfer');
        $this->move($moved, ['floor_change' => '-5'], source: 'stock_transfer');
        $this->move($moved, ['floor_change' => '1'], source: 'stock_transfer:cancel');
        $this->move($moved, ['floor_change' => '-1'], source: 'stock_adjustment');
        $this->move($moved, ['unplaced_change' => '10'], source: 'purchase_bill');
        $this->move($moved, ['floor_change' => '-3'], source: 'delivery_challan');
        $this->move($moved, ['unplaced_change' => '-10', 'floor_change' => '10'], source: 'stock_placement');
        $this->move($moved, ['hold_change' => '2'], source: 'stock_hold');
        $this->move($moved, ['floor_change' => '4'], source: 'purchase_bill', date: Carbon::today()->startOfMonth()->subDay());

        $this->assertSame(
            ['receive' => $kinds['receive'] + 1, 'issue' => $kinds['issue'] + 1, 'transfer' => $kinds['transfer'] + 3, 'adjustment' => $kinds['adjustment'] + 1],
            $this->kinds(),
            '⛔ চলাচল ভুল ধরনে — বা বসানো, আটকানো, গত মাসের চলাচলও গোনা হয়েছে।',
        );

        // ── কোন তারিখ থেকে কোন তারিখ (মালিক, ৫ অক্টোবর ২০২৬): প্রথম চার্টটা হোমেও যায়, তাই ওটাই আগে ─────
        $panels = InventoryDashboard::dashboard()->panels;
        $this->assertSame(DateRange::label(Carbon::today()->startOfYear(), Carbon::today()), $panels[0]->range,
            '⛔ মাসে মাসে ঢোকা-বেরোনোর চার্টে জানুয়ারি থেকে আজ লেখা নেই।');
        $moves = collect($panels)->firstWhere('label', __('inventory::dashboard.moves_title'));
        $this->assertSame(DateRange::label(Carbon::today()->startOfMonth(), Carbon::today()), $moves->range,
            '⛔ এ মাসের চলাচলের চার্টে মাসের শুরু থেকে আজ লেখা নেই।');
        $states = collect($panels)->firstWhere('label', __('inventory::overview.states'));
        $this->assertNull($states->range, '⛔ মজুদের অবস্থা একটা মুহূর্তের ছবি — তারিখের সীমা থাকার কথা নয়।');

        // ── সুইচ বন্ধ: নতুন ছয়টার একটাও নেই; প্রথম চার্টে শেষ সাত মাস ───────────────
        config(['abos.dashboards_v2' => false]);
        $dashboard = InventoryDashboard::dashboard();
        $this->assertSame(DateRange::label(Carbon::today()->startOfMonth()->subMonths(6), Carbon::today()), $dashboard->panels[0]->range,
            '⛔ সুইচ বন্ধে প্রথম চার্টের সাত মাসের সীমা লেখা নেই।');
        $labels = array_merge(
            array_map(fn ($s) => $s->label, $dashboard->stats),
            array_map(fn ($p) => $p->label, $dashboard->panels),
        );

        foreach (['total_sku', 'negative_stock', 'active_batches', 'by_warehouse', 'health', 'moves_title'] as $key) {
            $this->assertNotContains(__('inventory::dashboard.'.$key), $labels, "⛔ সুইচ বন্ধ, তবু '{$key}' দেখা যাচ্ছে — পুরনো ড্যাশবোর্ড বদলে গেছে।");
        }
    }

    private function newProduct(string $tag, string $reorder = '0'): Product
    {
        $template = Product::query()->where('is_active', true)->where('track_batch', false)->firstOrFail();
        $product = $template->replicate(['public_id']);
        $product->forceFill([
            'code' => 'WS-'.$tag.'-'.random_int(1000, 9999), 'name_en' => 'Spec '.$tag, 'name_bn' => 'নকশা '.$tag,
            'barcode' => null, 'reorder_level' => $reorder, 'is_active' => true,
        ])->save();

        return $product;
    }

    private function batch(Product $product, string $no): Batch
    {
        return Batch::query()->create([
            'company_id' => $this->company->id, 'product_id' => $product->id, 'batch_no' => 'WS-'.$no.'-'.random_int(1000, 9999),
            'expiry_date' => now()->addDays(200)->toDateString(),
        ]);
    }

    /** @param  array<string, string>  $boxes */
    private function move(Product $product, array $boxes, string $source = 'test', ?Batch $batch = null, ?Carbon $date = null): void
    {
        StockMovement::query()->create($boxes + [
            'company_id' => $this->company->id, 'branch_id' => $this->warehouse->branch_id,
            'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'batch_id' => $batch?->id,
            'trx_date' => ($date ?? Carbon::today())->toDateString(),
            'source_type' => $source, 'source_id' => 1, 'document_no' => 'WS-'.random_int(100000, 999999),
        ]);
    }

    private function statObject(string $key): Stat
    {
        $stat = collect(InventoryDashboard::dashboard()->stats)->firstWhere('label', __('inventory::dashboard.'.$key));
        $this->assertInstanceOf(Stat::class, $stat, "সুইচ চালু, তবু '{$key}' সংখ্যাটা নেই।");

        return $stat;
    }

    private function stat(string $key): int
    {
        return (int) self::plain((string) $this->statObject($key)->value);
    }

    /** @return array<string, string> নাম → পরিমাণ */
    private function parts(string $key): array
    {
        $panel = collect(InventoryDashboard::dashboard()->panels)->firstWhere('label', __('inventory::dashboard.'.$key));
        $this->assertInstanceOf(Breakdown::class, $panel, "সুইচ চালু, তবু '{$key}' চার্টটা নেই।");

        return collect($panel->parts)->mapWithKeys(fn ($p) => [$p['label'] => self::plain($p['value'])])->all();
    }

    /** @return array<string, string> */
    private function warehouseValues(): array
    {
        return $this->parts('by_warehouse');
    }

    /** @return array{ok: int, low: int, out: int} */
    private function health(): array
    {
        $parts = $this->parts('health');

        return [
            'ok' => (int) $parts[__('inventory::dashboard.health_ok')],
            'low' => (int) $parts[__('inventory::dashboard.health_low')],
            'out' => (int) $parts[__('inventory::dashboard.health_out')],
        ];
    }

    /** @return array{ok: int, low: int, out: int} — আগের ছবিতে নতুন পণ্যটা কোন ভাগে */
    private function shift(array $start, int $ok = 0, int $low = 0, int $out = 0): array
    {
        return ['ok' => $start['ok'] + $ok, 'low' => $start['low'] + $low, 'out' => $start['out'] + $out];
    }

    /** @return array{receive: int, issue: int, transfer: int, adjustment: int} */
    private function kinds(): array
    {
        $parts = $this->parts('moves_title');
        $out = [];

        foreach (['receive', 'issue', 'transfer', 'adjustment'] as $kind) {
            $out[$kind] = (int) $parts[__('inventory::dashboard.moves_'.$kind)];
        }

        return $out;
    }

    /** সাজানো মান থেকে bcmath-এর সংখ্যা — কমা আর বাংলা অঙ্ক ছাড়া ("8,000.00" → "8000.00") */
    private static function plain(string $value): string
    {
        $plain = trim(strtr($value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9', ',' => '']));

        return $plain === '' ? '0' : $plain;
    }
}
