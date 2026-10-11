<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\OpeningStockService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মজুদ ভুল শাখার খাতায় বসে থাকত — Inventory অডিট ম১১, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ (ক) খোলা মজুদের দাখিলা বসত যিনি লিখছেন তাঁর শাখায় (বা কোনো শাখায় নয়), গুদামের শাখায় নয়;
 * (খ) এক শাখার গুদাম থেকে আরেক শাখার গুদামে মাল গেলে খাতায় কিছুই নড়ত না — মাল নেত্রকোনা থেকে ময়মনসিংহে গিয়ে বিক্রি হলে
 * ময়মনসিংহের স্থিতিপত্রে মজুদ ঋণাত্মক, নেত্রকোনায় বাড়তি।
 * ⭐ এখন খোলা মজুদ গুদামের শাখায়; শাখা-পেরোনো স্থানান্তর গ্রহণে মজুদ খাতের দাখিলা — পাঠানো শাখায় ক্রেডিট, পাওয়া শাখায় ডেবিট,
 * চলে যাওয়া মালের আসল খরচে (এখানে একটাই স্তর, তাই ৫০; গড় নয় — পুরো-ERP অডিট ⚠️৮, [[ABranchTransferCarriesWhatTheGoodsCostTest]])।
 */
final class TheStockSatInTheWrongBranchsBooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_stock_and_a_cross_branch_transfer_land_in_the_right_branch(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($company->id, $mms->id); // ⓘ লিখছেন ময়মনসিংহের মানুষ
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $house = fn (string $code, int $branch) => Warehouse::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'branch_id' => $branch, 'code' => $code, 'name_en' => $code, 'is_active' => true,
        ]);
        $atNtk = $house('ZQ11N', $ntk->id);
        $atMms = $house('ZQ11M', $mms->id);

        $product = Product::query()->create([
            'code' => 'M11-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Branch probe', 'name_bn' => 'শাখার নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => false,
        ]);

        app(OpeningStockService::class)->bringIn($product, $atNtk, '10', '50');
        $this->assertSame('500', $this->inventory($ntk->id), '⛔ নেত্রকোনার গুদামের খোলা মজুদ নেত্রকোনার খাতায় বসেনি।');
        $this->assertSame('0', $this->inventory($mms->id), '⛔ খোলা মজুদ লেখকের শাখায় (ময়মনসিংহ) বসেছে।');

        $transfers = app(StockTransferService::class);
        $transfer = $transfers->dispatch($transfers->create(
            ['from_warehouse_id' => $atNtk->id, 'to_warehouse_id' => $atMms->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '4']],
        ));
        $transfers->receive($transfer->fresh());

        $this->assertSame(['300', '200'], [$this->inventory($ntk->id), $this->inventory($mms->id)],
            '⛔ ৪টা ময়মনসিংহে গেল, অথচ মজুদের টাকা নেত্রকোনার খাতাতেই রয়ে গেল।');

        // ⓘ একই শাখার দুই গুদাম — খাতায় কিছুই নয় (শাখার টাকা নড়েনি)
        $sameBranch = $house('ZQ11M2', $mms->id);
        $local = $transfers->dispatch($transfers->create(
            ['from_warehouse_id' => $atMms->id, 'to_warehouse_id' => $sameBranch->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '1']],
        ));
        $transfers->receive($local->fresh());
        $this->assertSame(0, LedgerEntry::query()->withoutGlobalScopes()->where('source_id', $local->id)
            ->where('source_type', \App\Modules\Inventory\Models\StockTransfer::drillSourceType())->count(),
            '⛔ একই শাখার ভিতরের স্থানান্তরেও খাতায় দাখিলা বসেছে।');
    }

    private function inventory(int $branchId): string
    {
        $account = StandardChart::find(StandardChart::INVENTORY);
        $sum = (string) (LedgerEntry::query()->withoutGlobalScopes()->where('account_id', $account->id)->where('branch_id', $branchId)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n') ?? '0');

        return rtrim(rtrim(bcadd($sum, '0', 4), '0'), '.') ?: '0';
    }
}
