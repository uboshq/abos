<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * আটকানো মাল মজুদের রিপোর্টে দুবার গোনা হত — Inventory অডিট ম১০, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ "হাতে আছে" গোনা হত তাক + অপেক্ষা + আটকানো, অথচ আটকানো মাল তাকেরই অংশ (বিক্রয়যোগ্য = তাক − সংরক্ষিত − আটকানো):
 * তাকে ১০-এর ৪টা আটকানো হলে রিপোর্ট বলত ১৪, আর মূল্যও ১৪টার — খাতার ১০০ টাকার মজুদ রিপোর্টে ১৪০।
 * ⭐ এখন রিপোর্টের "হাতে" [[StockService::statesFor()]]-এর হুবহু: তাক + অপেক্ষা।
 */
final class TheHeldStockWasCountedTwiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_held_goods_are_counted_once_in_the_stock_reports(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->create([
            'code' => 'ZQH-1', 'name_en' => 'Held probe', 'name_bn' => 'আটকানোর নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true,
        ]);
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.opening', sourceId: $product->id, floor: '10');
        app(CostLayerService::class)->receive(product: $product, qty: '10', unitCost: '10', sourceType: 'test.opening', sourceId: $product->id);
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test.hold', sourceId: $product->id, hold: '4');

        $this->assertSame(0, bccomp('10', app(StockService::class)->statesFor($product, $warehouse)['on_hand'], 4), 'প্রস্তুতিটাই ভুল।');

        $position = collect(app(ReportEngine::class)->run('inventory.stock_position', ['product_id' => $product->id], perPage: 100)->rows)->first();
        $this->assertNotNull($position, 'প্রস্তুতিটাই ভুল — অবস্থার রিপোর্টে পণ্যটা নেই।');
        $this->assertSame(0, bccomp('10', (string) $position['on_hand'], 4), '⛔ অবস্থার রিপোর্টে হাতে '.$position['on_hand'].' — আটকানো ৪ দুবার গোনা।');
        $this->assertSame(0, bccomp('100', (string) $position['value'], 4), '⛔ মজুদের মূল্য '.$position['value'].' — খাতার ১০০-র চেয়ে বেশি।');
        $this->assertSame(0, bccomp('6', (string) $position['sellable'], 4));

        $alert = collect(app(ReportEngine::class)->run('inventory.slow_dead', ['product_id' => $product->id], perPage: 100)->rows)
            ->first(fn ($r) => str_starts_with((string) ($r['product_name'] ?? ''), 'ZQH-1'));
        if ($alert !== null) {
            $this->assertSame(0, bccomp('10', (string) $alert['on_hand'], 4), '⛔ অলস মজুদের রিপোর্টেও আটকানো দুবার গোনা।');
        }

        // ⓘ মাসিক চলাচল — আটকানো তাক থেকে নড়ে না, তাই এই মাসে এল ১০, ১৪ নয়
        $month = collect(app(ReportEngine::class)->run('inventory.monthly_movement', [
            'product_id' => $product->id, 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(),
        ], perPage: 100)->rows)->firstWhere('ym', now()->format('Y-m'));
        $this->assertNotNull($month, 'প্রস্তুতিটাই ভুল — মাসিক রিপোর্টে এই মাস নেই।');
        $this->assertSame(0, bccomp('10', (string) $month['in_qty'], 4), '⛔ মাসিক চলাচলে আটকানো ৪ "এল" হিসেবে গোনা হয়েছে।');

        // ⓘ মজুদ-সতর্কতা — সর্বোচ্চ ১২: হাতে ১০, তাই সতর্কতা নয় (আটকানো দুবার গুনলে ১৪, "বেশি" দেখাত); সর্বোচ্চ ৯: সতর্কতা, হাতে ১০
        $alerts = fn () => collect(app(ReportEngine::class)->run('inventory.stock_alerts', ['product_id' => $product->id], perPage: 100)->rows)
            ->filter(fn ($r) => str_contains((string) collect($r)->implode(' '), 'ZQH-1'));
        $product->forceFill(['max_level' => '12'])->save();
        $this->assertCount(0, $alerts(), '⛔ হাতে ১০, সর্বোচ্চ ১২ — তবু "বেশি" সতর্কতা, আটকানো দুবার গোনা।');
        $product->forceFill(['max_level' => '9'])->save();
        $this->assertSame(0, bccomp('10', (string) ($alerts()->first()['on_hand'] ?? '-1'), 4), '⛔ সর্বোচ্চের উপরে, অথচ সতর্কতায় হাতে ভুল বা নেই।');
    }
}
