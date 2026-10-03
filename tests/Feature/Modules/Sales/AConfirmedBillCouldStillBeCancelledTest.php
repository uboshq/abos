<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * নিশ্চিত বিল তখনো বাতিল করা যেত — মালিক, ২ অক্টোবর ২০২৬ ([[docs/বিক্রয়ের কাজের ধারা — ২ অক্টোবর.md]] §৪)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * নিশ্চিত বিল কারণ লিখে বাতিল করা যেত — খাতা উল্টে যেত, বিক্রিটা নেই হয়ে যেত, গেট পাস হয়ে থাকলেও।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * *"ইনভয়েসের পরে … মোছা কখনো নয়"*। গেট পাসের আগে কেবল সম্পাদনা, পরে ফেরত বা ক্রেডিট নোট। খসড়া বাতিল চলে।
 * ⓘ দেয়াল সেবায় ([[SalesInvoiceService::cancel()]]) — প্রতিটা দরজা সেই পথে; এখানে সেবা আর ওয়েবের দরজা দুটোই মাপা।
 */
final class AConfirmedBillCouldStillBeCancelledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_service_refuses_to_cancel_a_confirmed_bill(): void
    {
        $invoice = $this->confirmed();
        $ledger = LedgerEntry::query()->count();

        try {
            app(SalesInvoiceService::class)->cancel($invoice, 'ভুল দামে কাটা হয়েছিল');
            $this->fail('⛔ নিশ্চিত বিল বাতিল হয়ে গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->fresh()->status, '⛔ বিলের অবস্থা বদলে গেছে।');
        $this->assertSame($ledger, LedgerEntry::query()->count(), '⛔ খাতায় উল্টো এন্ট্রি বসে গেছে।');
    }

    public function test_the_web_door_refuses_too(): void
    {
        $invoice = $this->confirmed();

        $this->post(route('sales.invoice.cancel', $invoice), ['reason' => 'ক্রেতা নেননি'])
            ->assertSessionHasErrors('status');

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->fresh()->status);
    }

    /** ⭐ খসড়া আগের মতোই বাতিল হয় — দেয়ালটা কেবল নিশ্চিত বিলে */
    public function test_a_draft_bill_still_cancels(): void
    {
        $draft = app(SalesInvoiceService::class)->create($this->paper(), $this->lines());

        app(SalesInvoiceService::class)->cancel($draft, 'ভুল ক্রেতা');

        $this->assertSame(DocumentStatus::CANCELLED, $draft->fresh()->status);
    }

    /** ⓘ পাতায় বাতিলের ঘর কেবল খসড়ায় — নিশ্চিত বিলে মরা বোতাম নয় */
    public function test_the_page_offers_cancel_only_on_a_draft(): void
    {
        $action = fn (SalesInvoice $i) => 'action="'.route('sales.invoice.cancel', $i).'"';

        $confirmed = $this->confirmed();
        $this->get(route('sales.invoice.show', $confirmed))->assertOk()
            ->assertDontSee($action($confirmed), false);

        $draft = app(SalesInvoiceService::class)->create($this->paper(), $this->lines());
        $this->get(route('sales.invoice.show', $draft))->assertOk()
            ->assertSee($action($draft), false);
    }

    private function confirmed(): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create($this->paper(), $this->lines()));
    }

    /** @return array<string, mixed> */
    private function paper(): array
    {
        return [
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => '100']];
    }
}
