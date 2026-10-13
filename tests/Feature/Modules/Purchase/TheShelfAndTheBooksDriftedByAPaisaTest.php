<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Tax;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⓘ দামের ভেতরে ভ্যাট — মজুদের স্তর আর খাতা এক পয়সার ভগ্নাংশে সরে যেত (fe, ১৩ অক্টোবর ২০২৬; [[PurchaseReceiptService::replaceLines()]])।
 *
 * ⚠️ ৭ পিস @১০০ (১৫% ভেতরে): মূল্য ৬০৮.৬৯৫৭, দর চার ঘরে ৮৬.৯৫৬৫ — স্তর বসত ৭ × ৮৬.৯৫৬৫ = ৬০৮.৬৯৫৫, খাতা ৬০৮.৬৯৫৭। ⭐ এখন মূল্য =
 * পরিমাণ × দর হুবহু, আর ভ্যাট বাকিটা — লেখা দাম ৭০০ অক্ষত।
 */
final class TheShelfAndTheBooksDriftedByAPaisaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_vat_inclusive_receipt_line_values_stock_exactly_qty_times_rate(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('purchase.vat_enabled', true);

        $tax = Tax::query()->create(['company_id' => $company->id, 'code' => 'VAT15-IN-DRIFT', 'name_en' => 'VAT 15% inclusive',
            'name_bn' => 'ভ্যাট ১৫% ভিতরে', 'rate' => '15', 'kind' => 'vat', 'is_inclusive' => true, 'is_active' => true]);
        $product = Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail();
        $product->forceFill(['tax_id' => $tax->id])->save();

        $receipt = app(PurchaseReceiptService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => '7', 'rate' => '100']],
        );
        $line = $receipt->fresh('lines')->lines->first();

        $this->assertSame(0, bccomp((string) $line->amount, bcmul((string) $line->received_qty, (string) $line->rate, 4), 4),
            "⛔ সারির মূল্য {$line->amount}, পরিমাণ × দর নয় — স্তর আর খাতা আলাদা হবে।");
        $this->assertSame(0, bccomp(bcadd((string) $line->amount, (string) $line->tax, 4), '700', 4), '⛔ মূল্য + ভ্যাট লেখা দাম ৭০০ নয়।');
    }
}
