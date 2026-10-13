<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ চালানের পাতায় লেখা দর, ভ্যাট বাদের দর আর ভ্যাট — তিনটাই (মালিক, ১০ অক্টোবর ২০২৬, fe মারফত: "লেখা দাম ১১৫, VAT-বাদ ১০০,
 * আর VAT ১৫ আলাদা লাইনে")।
 *
 * ⓘ 46088a75 দর ১০০ আর ভ্যাট দেখাত, লেখা ১১৫ কোথাও না। ⛔ লেখা দর জমা ভ্যাট থেকে — লাইভের পুরনো সারি (দর আগেই ভ্যাটসহ, ভ্যাট
 * শূন্য) ফুলে ১৩২.২৫ হয় না, আর এমন চালানে বাড়তি কলামই আসে না।
 */
final class AReceiptShowsTheRateAsTypedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_vat_inclusive_receipt_shows_the_typed_rate_the_net_rate_and_the_vat(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('purchase.vat_enabled', true);

        $tax = Tax::query()->create([
            'company_id' => $company->id, 'code' => 'VAT15-IN-T', 'name_en' => 'VAT 15% inclusive', 'name_bn' => 'ভ্যাট ১৫% ভিতরে',
            'rate' => '15', 'kind' => 'vat', 'is_inclusive' => true, 'is_active' => true,
        ]);
        $product = Product::query()->firstOrFail();
        $product->forceFill(['tax_id' => $tax->id])->save();
        $head = ['supplier_id' => Supplier::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()];

        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->create($head, [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '115']]);

        $this->get(route('purchase.receipt.show', $receipt))->assertOk()->assertSeeInOrder([
            __('purchase::field.typed_rate'), __('purchase::field.rate'), __('purchase::field.tax'),
            Money::format('115'), Money::format('100'), Money::format('150'),
        ], false);

        // ⓘ লাইভের পুরনো সারির চেহারা — দর ভ্যাটসহ ১১৫, ভ্যাট শূন্য: বাড়তি কলাম নেই, দর ফোলে না
        $old = $receipts->create($head, [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '115']]);
        DB::table('pur_receipt_lines')->where('purchase_receipt_id', $old->id)->update(['rate' => '115', 'amount' => '1150', 'tax' => '0']);
        $this->assertSame(0, bccomp($old->fresh('lines')->lines->first()->typedRate(), '115', 4), '⛔ পুরনো সারির লেখা দর ফুলে গেল।');
        $this->get(route('purchase.receipt.show', $old))->assertOk()->assertDontSee(__('purchase::field.typed_rate'));
    }
}
