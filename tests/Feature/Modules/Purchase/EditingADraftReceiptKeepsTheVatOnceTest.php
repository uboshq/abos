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
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ দামের ভেতরের ভ্যাটে খসড়া চালান সম্পাদনা করলে ভ্যাট আবার বাদ যেত (cb, cloud/purchase-fixes মেশানোর সময়, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ ১১৫ (ভ্যাট ১৫% ভেতরে) লিখলে চালান রাখে দর ১০০, ভ্যাট আলাদা (46088a75)। সম্পাদনার ফর্ম জমা দর ১০০ বসাত; "সংরক্ষণ" সেটাকে
 * আবার ভ্যাটসহ ধরে ৮৬.৯৬ করত, দ্বিতীয়বার ৭৫.৬২ — মজুদ আর দেনা প্রতিবার কমে। ⭐ এখন ফর্মে লেখা দর (১১৫) ফেরে; দুইবার সম্পাদনা
 * করে রাখলেও দর ১০০, ভ্যাট ১৫০।
 */
final class EditingADraftReceiptKeepsTheVatOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_receipt_edited_twice_keeps_rate_100_and_vat_150(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('purchase.vat_enabled', true);

        $tax = Tax::query()->create([
            'company_id' => $company->id, 'code' => 'VAT15-IN-E', 'name_en' => 'VAT 15% inclusive', 'name_bn' => 'ভ্যাট ১৫% ভিতরে',
            'rate' => '15', 'kind' => 'vat', 'is_inclusive' => true, 'is_active' => true,
        ]);
        $product = Product::query()->firstOrFail();
        $product->forceFill(['tax_id' => $tax->id])->save();
        $head = ['supplier_id' => Supplier::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()];

        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->create($head, [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '115']]);

        foreach ([1, 2] as $round) {
            // ⓘ ফর্ম যা বসায় তা-ই — সম্পাদনার পাতা থেকে পড়া দর, সেটাই আবার জমা
            $seeded = $this->rateOnTheEditForm($receipt);
            $this->assertSame(0, bccomp($seeded, '115', 4), "⛔ সম্পাদনার ফর্মে দর {$seeded}, লেখা ১১৫ নয় (দফা {$round})।");

            $receipt = $receipts->update($receipt->fresh(), $head, [['product_id' => $product->id, 'received_qty' => '10', 'rate' => $seeded]]);

            $line = $receipt->fresh('lines')->lines->first();
            $this->assertSame(0, bccomp((string) $line->rate, '100', 4), "⛔ {$round} বার সম্পাদনার পরে দর {$line->rate}, ১০০ নয় — ভ্যাট আবার বাদ গেল।");
            $this->assertSame(0, bccomp((string) $line->tax, '150', 4), "⛔ {$round} বার সম্পাদনার পরে ভ্যাট {$line->tax}, ১৫০ নয়।");
            $this->assertSame(0, bccomp((string) $receipt->fresh()->total, '1000', 2));
        }
    }

    private function rateOnTheEditForm(PurchaseReceipt $receipt): string
    {
        $html = $this->get(route('purchase.receipt.edit', $receipt))->assertOk()->getContent();

        // ⓘ সারিগুলো `@js(...)`-এ — উদ্ধৃতি চিহ্ন " হয়ে আসে
        $found = preg_match('/rate(?:\\\\u0022|"|&quot;)\s*:\s*(?:\\\\u0022|"|&quot;)([0-9.]+)/', (string) $html, $m);
        $this->assertSame(1, $found, 'দাবির ভিত্তি: সম্পাদনার ফর্মে সারির দর পাওয়া গেল না।');

        return $m[1];
    }
}
