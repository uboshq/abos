<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Security\LedgerChain;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ পুরনো বিক্রিও একটা নম্বর পায় — [[GiveOldSalesOneNumber]] (মালিক, ২৯ সেপ্টেম্বর ২০২৬:
 * *"egulo to test demo, somossa nai"*)।
 *
 * ⓘ আগের দিনের একটা বিক্রি বানানো হয় (চালান DC-0099, বিল INV-0099, খাতা আর মজুদে ঐ নম্বর),
 * তারপর কমান্ড: দেখানোয় কিছু বদলায় না; `--force`-এ চালান আর বিল একই S-নম্বর, খাতা আর
 * মজুদ নতুন নম্বরে, আর খাতার সিল অক্ষত।
 */
final class OldSalesGetOneNumberTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->companyId = (int) $company->id;
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_an_old_sale_gets_one_number_everywhere_and_the_seal_holds(): void
    {
        [$challan, $invoice] = $this->oldSale();

        $this->artisan('abos:one-sale-number', ['--company' => $this->companyId])->assertSuccessful();
        $this->assertSame('DC-0099', DeliveryChallan::query()->whereKey($challan)->value('document_no'),
            '⛔ --force ছাড়াই নম্বর বদলে গেছে।');

        $this->artisan('abos:one-sale-number', ['--company' => $this->companyId, '--force' => true])->assertSuccessful();

        $c = DeliveryChallan::query()->findOrFail($challan);
        $i = SalesInvoice::query()->findOrFail($invoice);

        $this->assertMatchesRegularExpression('/^S-\d+$/', (string) $c->document_no, '⛔ পুরনো চালান S-নম্বর পায়নি।');
        $this->assertSame($c->document_no, $c->sale_no);
        $this->assertSame($c->document_no, $i->document_no, '⛔ পুরনো বিল আর চালান আলাদা নম্বরে রয়ে গেছে।');
        $this->assertSame($c->document_no, $i->sale_no);

        foreach (['ledger_entries', 'inv_stock_movements'] as $table) {
            $this->assertSame(0, DB::table($table)->where('company_id', $this->companyId)
                ->whereIn('document_no', ['DC-0099', 'INV-0099'])->count(), "⛔ {$table}-এ পুরনো নম্বর রয়ে গেছে।");
        }

        $this->assertTrue(DB::table('ledger_entries')->where('company_id', $this->companyId)
            ->where('document_no', $i->document_no)->exists(), '⛔ বিলের খাতা নতুন নম্বর পায়নি।');
        $this->assertTrue(LedgerChain::verify($this->companyId)['ok'], '⛔ নম্বর বদলে খাতার সিল ভেঙেছে।');

        // ⓘ দ্বিতীয়বার চালালে কিছুই বদলায় না
        $this->artisan('abos:one-sale-number', ['--company' => $this->companyId, '--force' => true])->assertSuccessful();
        $this->assertSame($c->document_no, DeliveryChallan::query()->whereKey($challan)->value('document_no'));
    }

    /** @return array{0: int, 1: int} আগের দিনের নম্বরে একটা পাকা বিক্রি — চালান আর বিল */
    private function oldSale(): array
    {
        $product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $customer = Customer::query()->firstOrFail();

        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $product->id, 'delivered_qty' => '5', 'rate' => '10']]));

        $invoices = app(SalesInvoiceService::class);
        $invoice = $invoices->confirm($invoices->create([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $product->id,
            'delivery_challan_line_id' => $challan->lines()->value('id'),
            'qty' => '5',
            'rate' => '10',
        ]]));

        // ⓘ আগের দিনের চেহারায় ফেরানো — নিজের সারির নম্বর, sale_no নেই
        foreach ([['sal_challans', $challan->id, $challan->document_no, 'DC-0099'], ['sal_invoices', $invoice->id, $invoice->document_no, 'INV-0099']] as [$table, $id, $now, $old]) {
            DB::table($table)->where('company_id', $this->companyId)->where('id', $id)->update(['document_no' => $old, 'sale_no' => null]);

            foreach (['ledger_entries', 'inv_stock_movements'] as $ref) {
                DB::table($ref)->where('company_id', $this->companyId)->where('document_no', $now)->where('source_id', $id)->update(['document_no' => $old]);
            }
        }

        $this->assertTrue(DB::table('inv_stock_movements')->where('company_id', $this->companyId)->where('document_no', 'DC-0099')->exists()
            || DB::table('inv_stock_movements')->where('company_id', $this->companyId)->where('document_no', 'INV-0099')->exists(),
            'দৃশ্যটাই বানানো যায়নি — মজুদের চলাচলে পুরনো নম্বর বসেনি।');
        $this->assertTrue(DB::table('ledger_entries')->where('company_id', $this->companyId)->where('document_no', 'INV-0099')->exists(),
            'দৃশ্যটাই বানানো যায়নি — খাতায় পুরনো নম্বর বসেনি।');

        return [(int) $challan->id, (int) $invoice->id];
    }
}
