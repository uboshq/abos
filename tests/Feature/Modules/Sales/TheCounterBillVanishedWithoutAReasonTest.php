<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারের বিল কারণ ছাড়া মুছে যেত — মালিক, ৪ অক্টোবর ২০২৬ ("সব মুছুন" বাদ, তার জায়গায় "বিল বাতিল", Ctrl+X)।
 *
 * ⭐ পাকা হওয়ার আগে বাতিল চলে, কিন্তু কারণসহ আর অডিটে: না রাখা কার্টে ক্রেতার অডিটে (কাগজ নেই বলে), রাখা
 * খসড়ায় বিলের নিজের অডিটে।
 */
final class TheCounterBillVanishedWithoutAReasonTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
    }

    public function test_an_unsaved_cart_is_voided_only_with_a_reason_and_the_customers_audit_keeps_it(): void
    {
        $this->post(route('sales.direct.void'), ['customer_id' => $this->customer->id, 'lines' => 3, 'total' => '450'])
            ->assertSessionHasErrors('reason');
        $this->assertSame(0, $this->voids());

        $this->post(route('sales.direct.void'), [
            'customer_id' => $this->customer->id, 'lines' => 3, 'total' => '450', 'reason' => 'ক্রেতা মত বদলালেন',
        ])->assertSessionHasNoErrors()->assertRedirect(route('sales.direct.create'));

        $trail = AuditTrail::query()->where('action', 'counter_bill_voided')->sole();
        $this->assertSame(Customer::class, $trail->auditable_type);
        $this->assertSame((int) $this->customer->id, (int) $trail->auditable_id);
        $this->assertSame('ক্রেতা মত বদলালেন', $trail->reason);
    }

    public function test_a_kept_draft_is_cancelled_with_its_reason(): void
    {
        $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'own_transport' => '1',
            'save_as_draft' => '1',
            'lines' => [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        ])->assertSessionHasNoErrors();

        $draft = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $draft->status);

        $this->post(route('sales.direct.void'), ['resume_invoice_id' => $draft->id, 'reason' => 'ভুল ক্রেতা'])
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CANCELLED, $draft->fresh()->status, '⛔ রাখা খসড়া বাতিল হয়নি।');
        $this->assertTrue(AuditTrail::query()->where('auditable_type', SalesInvoice::class)->where('auditable_id', $draft->id)
            ->where('reason', 'ভুল ক্রেতা')->exists(), '⛔ খসড়ার অডিটে কারণ নেই।');
    }

    private function voids(): int
    {
        return AuditTrail::query()->where('action', 'counter_bill_voided')->count();
    }
}
