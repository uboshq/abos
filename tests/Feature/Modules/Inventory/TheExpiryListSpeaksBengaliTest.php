<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⓘ মেয়াদ তালিকা পণ্যের নাম কেবল ইংরেজিতে দেখাত (পুরো-ERP অডিট, মজুদ ছ১৮-এর লেজ; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * বাংলায় বাংলা নাম; বাংলা নাম ফাঁকা হলে ইংরেজি — খালি ঘর নয়; ইংরেজিতে ইংরেজি।
 */
final class TheExpiryListSpeaksBengaliTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_expiry_list_names_the_product_in_the_reading_language(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $unit = Unit::query()->where('code', 'PCS')->firstOrFail()->id;
        $named = Product::query()->create(['code' => 'EXP-BN', 'name_en' => 'Milk powder', 'name_bn' => 'গুঁড়া দুধ',
            'unit_id' => $unit, 'is_active' => true, 'track_batch' => true]);
        $bare = Product::query()->create(['code' => 'EXP-EN', 'name_en' => 'Soap bar', 'name_bn' => '',
            'unit_id' => $unit, 'is_active' => true, 'track_batch' => true]);

        foreach ([[$named, 'L-BN'], [$bare, 'L-EN']] as $i => [$product, $no]) {
            $lot = Batch::query()->create(['company_id' => $company->id, 'product_id' => $product->id, 'batch_no' => $no,
                'expiry_date' => now()->addDays(20)->toDateString()]);
            StockMovement::query()->create(['company_id' => $company->id, 'branch_id' => $warehouse->branch_id, 'product_id' => $product->id,
                'warehouse_id' => $warehouse->id, 'batch_id' => $lot->id, 'trx_date' => now()->toDateString(),
                'floor_change' => '4', 'source_type' => 'test.expiry', 'source_id' => $i + 1]);
        }

        $names = fn () => collect(app(ReportEngine::class)->run('inventory.expiring', [], perPage: 500)->rows)
            ->pluck('product_name', 'batch_no');

        app()->setLocale('bn');
        $this->assertSame('গুঁড়া দুধ', $names()['L-BN'] ?? null, '⛔ বাংলায় পড়লেও মেয়াদ তালিকা ইংরেজি নাম দেখাল।');
        $this->assertSame('Soap bar', $names()['L-EN'] ?? null, '⛔ বাংলা নাম ফাঁকা — ইংরেজি নামটাও এল না।');

        app()->setLocale('en');
        $this->assertSame('Milk powder', $names()['L-BN'] ?? null, '⛔ ইংরেজিতে পড়লে বাংলা নাম এল।');
    }
}
