<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চালানের পথে আসা মালের ভাড়া খাতায় কোথাও বসত না — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ⛔ বিলের ভাড়া মালের দামে বসে কেবল চালান-ছাড়া সারিতে। বিলের সব সারির পেছনে চালান থাকলে ৫০০ টাকার ভাড়া
 * বিলে লেখা থাকত, অথচ খাতায় গাড়িওয়ালার পাওনা শূন্য। ⓘ মালের দামে তোলা মজুদের খরচের ইঞ্জিনের কাজ
 * (অন্য সেশন); এখানে দাবি — টাকাটা অন্তত খাতায় সত্যি, আর মজুদের খাতা স্তরের বাইরে বাড়ে না।
 */
final class TheFreightOfReceivedGoodsWasNowhereTest extends TestCase
{
    use RefreshDatabase;

    public function test_freight_on_a_bill_for_received_goods_books_the_carriers_payable(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $product = Product::query()->firstOrFail();
        $supplier = Supplier::query()->firstOrFail();

        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->confirm($receipts->create(
            ['supplier_id' => $supplier->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '100']],
        ));

        $bills = app(PurchaseBillService::class);
        $bill = $bills->confirm($bills->create(
            ['supplier_id' => $supplier->id, 'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'FREIGHT-GRN-1', 'transport_cost' => '500'],
            [['product_id' => $product->id, 'qty' => '10', 'rate' => '100', 'purchase_receipt_line_id' => $receipt->fresh('lines')->lines->first()->id]],
        ));

        $entries = LedgerEntry::query()->where('source_type', PurchaseBill::drillSourceType())->where('source_id', $bill->id);
        $transport = Account::query()->where('code', StandardChart::TRANSPORT_PAYABLE)->value('id');
        $inventory = Account::query()->where('code', StandardChart::INVENTORY)->value('id');

        $this->assertSame(0, bccomp((string) (clone $entries)->where('account_id', $transport)->sum('credit'), '500', 4),
            '⛔ চালানের মালের ৫০০ টাকা ভাড়া গাড়িওয়ালার পাওনায় বসেনি — বিলে আছে, খাতায় নেই।');
        $this->assertSame(0, bccomp((string) (clone $entries)->where('account_id', $inventory)->sum('debit'), '0', 4),
            '⛔ ভাড়াটা মজুদের খাতায় ঢুকল, অথচ গুদামের স্তর বাড়েনি — দুই খাতা আলাদা হলো।');
    }
}
