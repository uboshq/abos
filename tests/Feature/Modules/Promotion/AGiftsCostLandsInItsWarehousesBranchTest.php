<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionGiftIssue;
use App\Modules\Promotion\Services\GiftIssuer;
use App\Modules\Promotion\Services\PromotionReversal;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ উপহারের খরচ গুদামের শাখায়, হেডারের শাখায় নয় — fe-র বিক্রয় ফাঁক ১২, ২০ অক্টোবর ২০২৬ ([[GiftIssuer::bookTheCost()]])।
 *
 * ⛔ আগে খাতার সারি `CompanyContext::branchId()` নিত: হেডারে ময়মনসিংহ, গুদাম নেত্রকোনার — খরচ আর মজুদের Cr ময়মনসিংহে বসত।
 *   নেত্রকোনার মজুদ খাতায় বেশি থাকত, ময়মনসিংহের কম, আর কোম্পানির মোট মিলত বলে কিছুই লাল হত না।
 * ⓘ বিপজ্জনক ইনপুট: হেডার এক শাখায়, গুদাম আরেক শাখায় — আসল দরজা দিয়ে (`promotion.gift.store`)। ফেরতও একই শাখায় উল্টায়।
 */
final class AGiftsCostLandsInItsWarehousesBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gift_issued_with_the_header_on_another_branch_books_in_the_warehouses_branch(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        CompanyContext::set($company->id, $mms->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        // ⓘ হেডার = ময়মনসিংহ ([[ResolveCompanyContext]] মানুষের `current_branch_id` থেকে বসায়); "সব শাখা" দেখেন, তাই নেত্রকোনার গুদাম পাওয়া যায়
        $owner->forceFill(['current_company_id' => $company->id, 'current_branch_id' => $mms->id, 'view_all_branches' => true])->save();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $warehouse = Warehouse::query()->create(['branch_id' => $ntk->id, 'code' => 'GNTK', 'name_en' => 'Netrokona gift store', 'name_bn' => 'নেত্রকোনার উপহারের গুদাম', 'is_active' => true]);

        $gift = Product::query()->orderBy('id')->firstOrFail()->replicate(['public_id']);
        $gift->forceFill(['code' => 'GIFT-BR1', 'barcode' => null, 'name_en' => 'Branch gift', 'name_bn' => 'শাখার উপহার', 'track_batch' => false, 'track_serial' => false, 'purchase_price' => '30'])->save();

        $stock = app(StockService::class);
        $stock->move(product: $gift, warehouse: $warehouse, sourceType: 'purchase_bill', sourceId: 6201, unplaced: '100');
        $stock->place(product: $gift, warehouse: $warehouse, qty: '100', sourceType: 'purchase_bill', sourceId: 6201);
        app(CostLayerService::class)->receive($gift, '100', '40', 'purchase_bill', 6201);

        $offer = new Promotion(['name_en' => 'Branch gift offer', 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek()]);
        $offer->code = 'PROM-BR-0001';
        $offer->type = PromotionType::BUY_X_GET_Y;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $owner->id;
        $offer->save();

        $benefit = PromotionBenefit::query()->create(['promotion_id' => $offer->id, 'kind' => BenefitKind::GOODS, 'amount' => '5',
            'gift_product_id' => $gift->id, 'gift_unit_id' => $gift->unit_id]);
        $owed = PromotionApplication::query()->create([
            'promotion_id' => $offer->id, 'promotion_benefit_id' => $benefit->id, 'branch_id' => $ntk->id,
            'source_type' => 'sales_invoice', 'source_id' => 8711,
            'benefit_kind' => BenefitKind::GOODS, 'benefit_amount' => '5', 'worth' => '500',
        ]);

        // ⓘ দৃশ্যটা সত্যিই তা-ই: দুই শাখা আলাদা, হেডার ময়মনসিংহে, গুদাম নেত্রকোনায়
        $this->assertNotSame($mms->id, $ntk->id);
        $this->assertSame($mms->id, (int) $owner->fresh()->current_branch_id, 'দৃশ্যটাই বানানো যায়নি — হেডার ময়মনসিংহে নয়');
        $this->assertSame($ntk->id, (int) $warehouse->fresh()->branch_id, 'দৃশ্যটাই বানানো যায়নি — গুদাম নেত্রকোনার নয়');

        $this->actingAs($owner->fresh())
            ->post(route('promotion.gift.store', $owed), ['warehouse_id' => $warehouse->id, 'qty' => '5'])
            ->assertSessionHasNoErrors();

        $issue = PromotionGiftIssue::query()->where('promotion_application_id', $owed->id)->firstOrFail();
        // ⓘ হেডার তখনো ময়মনসিংহে ছিল — নইলে দাবিটা কিছু প্রমাণ করে না
        $this->assertSame($mms->id, (int) CompanyContext::branchId(), 'অনুরোধের হেডার ময়মনসিংহে ছিল না');

        $rows = LedgerEntry::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('source_type', GiftIssuer::LEDGER_SOURCE)->where('source_id', $issue->id)->get();
        $this->assertCount(2, $rows, '⛔ উপহারের খরচ খাতায় দুই পায়ে ওঠেনি');
        $this->assertSame(0, bccomp((string) $rows->sum(fn ($r) => (float) $r->debit), '200', 4), '⛔ খরচ স্তরের ৫ × ৪০ নয়');

        foreach ($rows as $row) {
            $this->assertSame($ntk->id, (int) $row->branch_id, '⛔ উপহারের খরচ হেডারের শাখায় বসেছে, গুদামের (নেত্রকোনা) শাখায় নয়');
        }

        // ⭐ বিল বাতিলে উল্টো দাখিলাও নেত্রকোনায় — হেডার তখনো ময়মনসিংহে
        CompanyContext::set($company->id, $mms->id);
        app(PromotionReversal::class)->forSource('sales_invoice', 8711);

        $back = LedgerEntry::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('source_type', GiftIssuer::LEDGER_SOURCE.'_return')->where('source_id', $issue->id)->get();
        $this->assertCount(2, $back, '⛔ ফেরতের উল্টো দাখিলা ওঠেনি');

        foreach ($back as $row) {
            $this->assertSame($ntk->id, (int) $row->branch_id, '⛔ উপহার ফেরতের দাখিলা হেডারের শাখায় বসেছে, গুদামের শাখায় নয়');
        }
    }
}
