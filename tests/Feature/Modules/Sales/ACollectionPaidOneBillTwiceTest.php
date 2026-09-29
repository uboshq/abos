<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একটা আদায় একটা বিলে তার বাকির বেশি বসায় না, আর বাতিল বিলে পাকা হয় না — ২৯ সেপ্টেম্বর ২০২৬ (অডিট)।
 *
 * ── ⛔ সন্দেহ (৫) ─────────────────────────────────────────────────────────
 * আদায়ের সারি প্রতিটা আলাদা করে বিলের বাকির সাথে মেলানো হত। একই বিল দুই সারিতে
 * দিলে (২,০০০ + ২,০০০, বাকি ৩,৫৫০) প্রতিটা পার হত, অথচ বিলে বসত ৪,০০০ —
 * বিলটা "অতিরিক্ত শোধ", আর গ্রাহকের অন্য বিল বাকিই থাকত।
 *
 * ── ⛔ সন্দেহ (৬) ─────────────────────────────────────────────────────────
 * আদায় খসড়া থাকতে বিল বাতিল হলে, নিশ্চিত করার সময় বিলের অবস্থা আর দেখা হত না —
 * আদায়টা বাতিল বিলেই পাকা হত।
 */
class ACollectionPaidOneBillTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $dealer;

    private Warehouse $warehouse;

    private Product $rice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set('customer.zero_limit_blocks', false);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->rice = Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail();

        $dealer = app(CustomerService::class)->create([
            'name_en' => 'Twice Traders', 'name_bn' => 'দুইবার ট্রেডার্স', 'credit_limit' => '0', 'credit_days' => 30,
        ]);
        $dealer->forceFill(['credit_limit' => '100000'])->save();
        $this->dealer = $dealer->fresh();
    }

    /** (৫) একই বিল দুই সারিতে — মোট বাকির বেশি হলে ফেরে। */
    public function test_one_bill_on_two_lines_cannot_take_more_than_it_owes(): void
    {
        $bill = $this->sell();
        $due = $bill->dueAmount();

        $this->assertSame(0, bccomp($due, '3550', 4), 'প্রস্তুতিটাই ভুল — বিলের বাকি ৩,৫৫০ নয়।');

        $refused = false;

        try {
            app(CollectionService::class)->create(
                ['customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(), 'amount' => '4000'],
                [
                    ['sales_invoice_id' => $bill->id, 'amount' => '2000'],
                    ['sales_invoice_id' => $bill->id, 'amount' => '2000'],
                ],
            );
        } catch (ValidationException $e) {
            $refused = array_key_exists('lines', $e->errors());
        }

        $this->assertTrue($refused, '⛔ একই বিলে দুই সারিতে মোট ৪,০০০ বসল, অথচ বাকি ৩,৫৫০।');
        $this->assertSame(0, Collection::query()->where('customer_id', $this->dealer->id)->count(),
            '⛔ ফেরানো আদায়ের সারি রয়ে গেছে।');
    }

    /** (৬) খসড়ার পরে বিল বাতিল — নিশ্চিত করা থামে, খাতায় কিছু বসে না। */
    public function test_a_draft_collection_does_not_post_against_a_bill_cancelled_meanwhile(): void
    {
        $bill = $this->sell();

        $draft = app(CollectionService::class)->create(
            ['customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(), 'amount' => '3550'],
            [['sales_invoice_id' => $bill->id, 'amount' => '3550']],
        );

        app(SalesInvoiceService::class)->cancel($bill->fresh(), 'অডিট: আদায়ের খসড়ার পরে বাতিল');
        $this->assertSame(DocumentStatus::CANCELLED, $bill->fresh()->status, 'প্রস্তুতিটাই ভুল — বিলটা বাতিল হয়নি।');

        $refused = false;

        try {
            app(CollectionService::class)->confirm($draft->fresh());
        } catch (ValidationException) {
            $refused = true;
        }

        $this->assertTrue($refused, '⛔ বাতিল বিলের আদায় পাকা হয়ে গেল।');
        $this->assertSame(DocumentStatus::DRAFT, $draft->fresh()->status, '⛔ আদায়টা খসড়া থাকার কথা।');
        $this->assertSame(0, LedgerEntry::query()
            ->where('source_type', Collection::drillSourceType())
            ->where('source_id', $draft->id)
            ->count(), '⛔ বাতিল বিলের আদায় খাতায় বসেছে।');
    }

    /** এক বস্তা, জমা শূন্য — বাকি ৩,৫৫০। */
    private function sell(): SalesInvoice
    {
        $sale = app(DirectSaleService::class)->complete(
            ['customer_id' => $this->dealer->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(), 'deposit' => '0'],
            [['product_id' => $this->rice->id, 'qty' => '1', 'rate' => '3550']],
        );

        return $sale['invoice']->fresh();
    }
}
