<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\QualityInspectionService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাস বন্ধ হওয়ার পরে রায় আর কোনোদিন দেওয়া যেত না — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ রায়ের আটকানো লেখা হত পরিদর্শনের তারিখে। আগের মাসের শেষ দিনে খোলা পরিদর্শন, পরের মাসে রায়, আর মাঝে আগের মাস বন্ধ —
 * প্রতিটা চেষ্টা বন্ধ মাসে আটকাত; কাগজ চিরকাল অপেক্ষমাণ, আর অপেক্ষার আটকানো চিরকাল মাল আটকে রাখত।
 */
final class AVerdictAfterTheMonthClosedCouldNeverBeGivenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_verdict_is_written_on_its_own_day(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->create([
            'code' => 'QCD-'.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Inspected probe', 'name_bn' => 'পরিদর্শনের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'qc_required' => true, 'is_active' => true,
        ]);

        $lastMonth = now()->subMonthNoOverflow()->endOfMonth();
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test_opening', sourceId: $product->id, floor: '50', date: $lastMonth->copy()->subDays(2));

        $paper = app(QualityInspectionService::class)->open([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'inspected_qty' => '30', 'inspected_on' => $lastMonth->toDateString(),
        ]);

        // ⓘ মালিক আগের মাসটা বন্ধ করলেন
        PeriodLock::query()->create([
            'company_id' => $company->id, 'year' => (int) $lastMonth->year, 'month' => (int) $lastMonth->month,
            'reason' => 'মাস বন্ধ', 'locked_by' => auth()->id(), 'locked_at' => now(),
        ]);

        $decided = app(QualityInspectionService::class)->decide($paper->fresh(), QualityInspection::REJECTED, '25', '5');

        $this->assertSame(QualityInspection::REJECTED, $decided->status, 'বন্ধ মাসের পরিদর্শনে আজকের রায় বসল না।');
        $this->assertSame(0, bccomp(app(StockService::class)->holdQty($product, $warehouse), '5', 4));
    }
}
