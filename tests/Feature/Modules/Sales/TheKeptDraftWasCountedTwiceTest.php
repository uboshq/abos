<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * রাখা খসড়া পাকা করতে গেলে সারাংশ বিলটা দুবার গুনত — মালিক, ৪ অক্টোবর ২০২৬ (DRF-0014, M/S Bokthiyar Enterprise:
 * অগ্রিম ৪৪,৫৮৯.৫৫, বিল ৪৪,৫০৩.৭৩, তবু "সীমা পার ৪৪,৪১৭.৯১ — নিশ্চিত হবে না")।
 *
 * ⛔ খসড়া বিল "আটকে থাকা"-য় গোনা হয় ([[CreditExposure::pending()]]), আর পাকা করার সময় একই বিল "এই বিলে বাকি"-তেও।
 * সেবা নিজে খসড়াটা বাদ দেয় (`exceptInvoiceId`), সারাংশ দিত না — তাই পপ-আপ "নিশ্চিত" বন্ধ রাখত।
 *
 * দাবি — একই মানুষ, একই খসড়া: সীমার ভিতরের বিল খসড়া থেকে খুললেও "থামবে" নয়; আর সীমা সত্যিই ছোট হলে তখনো থামে।
 */
final class TheKeptDraftWasCountedTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private Batch $lot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->update(['track_batch' => true]);
        $this->lot = Batch::query()->create([
            'company_id' => $company->id, 'product_id' => $this->product->id,
            'batch_no' => 'LOT-DRF', 'expiry_date' => '2028-01-01',
        ]);
        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse, sourceType: 'opening', sourceId: $this->lot->id,
            floor: '100', date: now()->toDateString(), documentNo: 'TEST-LOT-DRF', batch: $this->lot,
        );
    }

    public function test_a_kept_draft_within_the_limit_opens_to_a_confirm_that_does_not_stop(): void
    {
        // ⓘ সীমা = আজকের বাকি + ১,৫০০ — বিল ১,০০০ ভিতরে, কিন্তু দুবার গুনলে ২,০০০ বাইরে
        $due = bcadd($this->customer->outstanding(), '0', 4);
        $this->customer->forceFill(['credit_limit' => bcadd($due, '1500', 4)])->save();

        $this->post(route('sales.direct.store'), [...$this->form(), 'save_as_draft' => '1'])->assertSessionHasNoErrors();
        $draft = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $draft->status, 'প্রস্তুতিটাই ভুল — খসড়া রাখা হয়নি।');

        $this->post(route('sales.direct.overview'), [...$this->form(), 'resume_invoice_id' => $draft->id])->assertOk()
            ->assertSee('data-overview-blocks="0"', false);

        // ⭐ একই খসড়া, সীমা এখন বিলের চেয়ে ছোট — তখন সত্যিই থামে (নইলে দাবিটা "কখনো থামে না" দিয়েও সবুজ হত)
        $this->customer->forceFill(['credit_limit' => bcadd($due, '500', 4)])->save();
        $this->post(route('sales.direct.overview'), [...$this->form(), 'resume_invoice_id' => $draft->id])->assertOk()
            ->assertSee('data-overview-blocks="1"', false);
    }

    /**
     * ⭐ পর্দার নিজের হিসাবও — মালিক, ৪ অক্টোবর ২০২৬: সারাংশ ঠিক হওয়ার পরেও DRF-0014 খুললে কাউন্টারের পপ-আপ "অবশিষ্ট সীমা
     * ৳85.82, বিল ৳44,417.91 বেশি" দেখাত — `customerTerms.held`-এ খসড়াটা নিজেই বসে ছিল ([[direct-sale.js]] `creditLeft`)।
     * দাবি — একই খসড়া: খুললে নিজের অঙ্ক `held`-এ নেই; না খুলে নতুন বিলে খসড়াটা তখনো আটকায়।
     */
    public function test_the_counter_opened_on_the_draft_does_not_hold_the_draft_against_itself(): void
    {
        $this->post(route('sales.direct.store'), [...$this->form(), 'save_as_draft' => '1'])->assertSessionHasNoErrors();
        $draft = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $draft->status, 'প্রস্তুতিটাই ভুল — খসড়া রাখা হয়নি।');

        $fresh = $this->get(route('sales.direct.create'))->assertOk()->viewData('customerTerms');
        $this->assertEqualsWithDelta((float) $draft->total, $fresh[$this->customer->id]['held'], 0.001,
            'নতুন বিলে রাখা খসড়াটা সীমা আটকায় — এটা থাকতেই হবে।');

        $opened = $this->get(route('sales.direct.create', ['draft' => $draft->id]))->assertOk()->viewData('customerTerms');
        $this->assertEqualsWithDelta(0.0, $opened[$this->customer->id]['held'], 0.001,
            'খসড়াটা খুলে পাকা করার সময় সে নিজেকেই আটকে রাখছে — বিলটা দুবার গোনা হচ্ছে।');
    }

    /** @return array<string, mixed> */
    private function form(): array
    {
        return [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => '1',
            'lines' => [['product_id' => $this->product->id, 'batch_id' => $this->lot->id, 'qty' => '10', 'rate' => '100', 'free_qty' => '0']],
        ];
    }
}
