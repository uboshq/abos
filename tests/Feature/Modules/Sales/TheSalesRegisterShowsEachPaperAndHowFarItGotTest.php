<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিক্রয়ের কাগজের খাতা — রিপোর্ট সেন্টার ধাপ ৬, বিক্রয়ের অর্ধেক ([[SalesRegisterReports]])।
 *
 * ১০টার অর্ডার, চালানে ৬টা গেল, সেই চালানের ৬টার বিল → অর্ডারের সারি: ডেলিভারি ৬০%, বিল ৬০%; ইনভয়েসের সারি বিল ১০০%।
 * ⓘ কাউন্টারের বিক্রি "সরাসরি" নামে; খোলা কাগজের বয়স আছে; আর এক শাখা বাছলে অন্য শাখার কাগজ খাতায় নেই।
 */
final class TheSalesRegisterShowsEachPaperAndHowFarItGotTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_paper_is_one_row_with_its_match_and_age_and_the_branch_wall_holds(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $product = Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail();
        $head = ['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()];

        $orders = app(SalesOrderService::class);
        $order = $orders->confirm($orders->create($head,
            [['product_id' => $product->id, 'ordered_qty' => '10', 'rate' => '100']]))->load('lines');

        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create([...$head, 'sales_order_id' => $order->id, 'own_transport' => true], [[
            'product_id' => $product->id, 'delivered_qty' => '6', 'rate' => '100',
            'sales_order_line_id' => $order->lines->first()->id,
        ]]))->load('lines');

        $invoice = app(SalesInvoiceService::class)->create($head, [[
            'product_id' => $product->id, 'qty' => '6', 'rate' => '100',
            'delivery_challan_line_id' => $challan->lines->first()->id,
        ]]);

        // ⓘ কাউন্টারের বিক্রি — পর্দার ছবি থাকে ([[DirectSaleService]])
        $counter = app(SalesInvoiceService::class)->create($head, [['product_id' => $product->id, 'qty' => '1', 'rate' => '100']]);
        $counter->forceFill(['counter_screen' => ['lines' => []]])->saveQuietly();

        $rows = $this->rows([]);

        $this->assertSame(['60', '60'], $this->pct($rows, $order->document_no), 'অর্ডারের সারি: ডেলিভারি ৬০%, বিল ৬০% নয়।');
        $this->assertSame(['', '100'], $this->pct($rows, $invoice->document_no), 'ইনভয়েসের সারি: বিল ১০০% নয়।');
        $this->assertSame((string) __('sales::register.kind_invoice'), $this->row($rows, $invoice->document_no)['kind']);
        $this->assertSame((string) __('sales::register.kind_direct'), $this->row($rows, $counter->document_no)['kind'],
            '⛔ কাউন্টারের বিক্রি "সরাসরি" নামে আসেনি।');
        $this->assertSame('0', (string) $this->row($rows, $order->document_no)['age_days'], 'খোলা অর্ডারের বয়স নেই।');

        // ⛔ অন্য শাখার কাগজ — এক শাখা বাছলে খাতায় নেই, "সব শাখা"-য় আছে
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('id', '<>', $company->defaultBranch()->id)->firstOrFail();
        $order->forceFill(['branch_id' => $other->id])->saveQuietly();

        $this->assertNull($this->row($this->rows(['branch_id' => $company->defaultBranch()->id]), $order->document_no, false),
            '⛔ অন্য শাখার অর্ডার এই শাখার খাতায়।');
        $this->assertNotNull($this->row($this->rows([]), $order->document_no, false));
    }

    /** @return list<array<string, mixed>> */
    private function rows(array $extra): array
    {
        return app(ReportEngine::class)->run('sales.register', [
            'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString(), ...$extra,
        ], perPage: 500)->rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function row(array $rows, string $documentNo, bool $mustExist = true): ?array
    {
        foreach ($rows as $row) {
            if (($row['document_no'] ?? null) === $documentNo) {
                return $row;
            }
        }

        $mustExist && $this->fail("{$documentNo} খাতায় নেই।");

        return null;
    }

    /** @param list<array<string, mixed>> $rows */
    private function pct(array $rows, string $documentNo): array
    {
        $row = $this->row($rows, $documentNo);

        return array_map(fn ($v) => $v === null ? '' : (string) (int) $v, [$row['delivered_pct'], $row['invoiced_pct']]);
    }
}
