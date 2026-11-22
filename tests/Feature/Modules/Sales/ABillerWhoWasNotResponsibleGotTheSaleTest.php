<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesResponsibility;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ অফিসে বিল যিনি কাটলেন, বিক্রিটা তাঁর খাতায় উঠত — মালিক, ২৩ নভেম্বর ২০২৬ ([[SalesResponsibility]])।
 *
 * ⓘ পাকা হওয়ার মুহূর্তে `sales_person_id` বসে — দায়িত্বের মানুষ; `created_by` ("বিল করেছেন") যেমন ছিল তেমন।
 * ⓘ ফেরত মূল বিলের মানুষের খাতায় — পরে ডিলার হাতবদল হলেও।
 */
final class ABillerWhoWasNotResponsibleGotTheSaleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $biller;

    private Product $biscuit;

    private Warehouse $warehouse;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->biller = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->biller);

        DB::table('dealer_bindings')->where('company_id', $this->company->id)->delete();
        DB::table('area_bindings')->where('company_id', $this->company->id)->delete();
        app(SalesResponsibility::class)->forget();

        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = (int) Customer::query()->where('name_en', 'Rahim Traders')->value('id');
    }

    public function test_the_responsible_person_is_stamped_at_confirm_and_the_biller_stays_the_biller(): void
    {
        $sr = $this->person();
        DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id, 'customer_id' => $this->customer, 'starts_on' => now()->subYear()->toDateString()]);

        $sale = $this->sell('2');

        $this->assertSame(DocumentStatus::CONFIRMED, $sale->status, 'প্রস্তুতিটাই ভুল — বিক্রি পাকা হয়নি।');
        $this->assertSame($sr->id, (int) $sale->sales_person_id, '⛔ পাকা হওয়ার সময় দায়িত্বের SR বসেননি।');
        $this->assertSame($this->biller->id, (int) $sale->created_by, '⛔ "বিল করেছেন" বদলে গেল।');
        $this->assertSame($sr->id, $sale->salesPerson?->id);
    }

    public function test_a_biller_who_is_not_responsible_gets_nothing(): void
    {
        $sale = $this->sell('1');

        $this->assertNull($sale->sales_person_id, '⛔ কেউ দায়িত্বে নেই, অথচ বিল যিনি কাটলেন তিনি কৃতিত্ব পেলেন।');
        $this->assertSame($this->biller->id, (int) $sale->created_by);
    }

    public function test_a_return_goes_to_the_person_of_the_original_bill(): void
    {
        $first = $this->person();
        $binding = DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $first->id, 'customer_id' => $this->customer, 'starts_on' => now()->subYear()->toDateString()]);
        $sale = $this->sell('3');
        $this->assertSame($first->id, (int) $sale->sales_person_id, 'প্রস্তুতিটাই ভুল — বিলে প্রথম জন বসেননি।');

        // ⓘ ফেরতের আগেই ডিলার হাতবদল — আজ থেকে নতুন জন
        $binding->update(['ends_on' => now()->subDay()->toDateString()]);
        $next = $this->person();
        DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $next->id, 'customer_id' => $this->customer, 'starts_on' => now()->toDateString()]);
        app(SalesResponsibility::class)->forget();

        $service = app(SalesReturnService::class);
        $return = $service->create([
            'customer_id' => $this->customer,
            'warehouse_id' => $this->warehouse->id,
            'sales_invoice_id' => $sale->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->biscuit->id, 'sales_invoice_line_id' => $sale->lines()->value('id'), 'qty' => '1']]);
        $return = $service->confirm($return);

        $this->assertSame(DocumentStatus::CONFIRMED, $return->status, 'প্রস্তুতিটাই ভুল — ফেরত পাকা হয়নি।');
        $this->assertSame($first->id, (int) $return->sales_person_id, '⛔ ফেরত মূল বিলের মানুষের খাতায় নয় — একজনের বিক্রি আরেকজনের ফেরতে কাটা গেল।');
        $this->assertSame($this->biller->id, (int) $return->created_by);
    }

    private function sell(string $qty): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer, 'warehouse_id' => $this->warehouse->id, 'own_transport' => '1'],
            [['product_id' => $this->biscuit->id, 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
    }

    private function person(): User
    {
        $u = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $u->companies()->attach($this->company->id, ['is_active' => true]);

        return $u->fresh();
    }
}
