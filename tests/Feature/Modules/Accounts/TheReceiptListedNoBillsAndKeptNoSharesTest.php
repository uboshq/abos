<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Contracts\PartyOpenBills;
use App\Core\Services\NoPartyOpenBills;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ম১ — রসিদের "কোন বিলের বিপরীতে" (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে তালিকাটা সবসময় খালি ছিল (কোয়েরি খুঁজত 'posted', পাকা বিক্রয় বিল 'confirmed'), আর ফর্ম বহু বিলে ভাগ পাঠাত যা
 * সার্ভার কোথাও রাখত না। ⭐ ৬৩-এর সিদ্ধান্ত (খ): রসিদে একটাই বিল, বাকি বিলের নিজের নিয়মে; বহু বিল "আদায়" পর্দায়।
 */
final class TheReceiptListedNoBillsAndKeptNoSharesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = $this->newCustomer('Bill List Customer');
    }

    public function test_a_confirmed_bill_is_listed_with_its_own_outstanding(): void
    {
        $invoice = $this->confirmedInvoice($this->customer, '2', '100');

        $bills = $this->billsFor($this->customer);

        $this->assertCount(1, $bills, '⛔ পাকা (confirmed) বিল রসিদের তালিকায় আসেনি — আগের ভুল: খুঁজত posted।');
        $this->assertSame((int) $invoice->id, (int) $bills[0]['id']);
        $this->assertSame(SalesInvoice::drillSourceType(), $bills[0]['against_type']);
        $this->assertSame(0, bccomp((string) $bills[0]['outstanding'], $invoice->fresh()->dueAmount(), 4),
            '⛔ তালিকার বাকি বিলের নিজের বাকির সাথে মেলে না।');
    }

    public function test_another_partys_bill_a_paid_bill_and_a_draft_are_not_listed(): void
    {
        $mine = $this->confirmedInvoice($this->customer, '1', '100');
        $this->confirmedInvoice($this->newCustomer('Somebody Else'), '1', '100');
        $this->draftInvoice($this->customer);

        $paid = $this->confirmedInvoice($this->customer, '1', '50');
        $this->receiptAgainst($paid, '50.00')->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp($paid->fresh()->dueAmount(), '0', 4), 'প্রস্তুতি: বিলটা পুরো শোধ হয়নি।');

        $ids = array_map(fn (array $b) => (int) $b['id'], $this->billsFor($this->customer));

        $this->assertSame([(int) $mine->id], $ids, '⛔ তালিকায় অন্যের বিল, শোধ হওয়া বিল বা খসড়া এসেছে।');
    }

    /** ⓘ বাছা বিলটা রসিদের "বিপরীতে" ঘরে যায়, আর বিলের বাকি সেই অঙ্কে কমে */
    public function test_a_receipt_against_the_picked_bill_reduces_that_bill(): void
    {
        $invoice = $this->confirmedInvoice($this->customer, '3', '100');
        $before = $invoice->fresh()->dueAmount();

        $this->receiptAgainst($invoice, '120.00')->assertSessionHasNoErrors();

        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $this->assertSame(SalesInvoice::drillSourceType(), $voucher->against_type);
        $this->assertSame((int) $invoice->id, (int) $voucher->against_id);
        $this->assertSame(0, bccomp(bcsub($before, $invoice->fresh()->dueAmount(), 4), '120', 4), '⛔ রসিদের পরে বিলের বাকি কমেনি।');

        $bills = $this->billsFor($this->customer);
        $this->assertSame(0, bccomp((string) $bills[0]['outstanding'], bcsub($before, '120', 4), 4));
    }

    public function test_the_form_offers_one_bill_and_no_dead_share_fields(): void
    {
        $page = $this->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))->assertOk();

        $page->assertDontSee('bill_allocs', false);
        $page->assertSee('name="against_id"', false);
        $page->assertSee(__('accounts::message.many_bills_on_collection'));
    }

    public function test_the_collection_screen_offers_oldest_bills_first(): void
    {
        $this->get(route('sales.collection.create'))->assertOk()
            ->assertSee(__('sales::action.oldest_bills_first'));
    }

    /** ⓘ বিক্রয় বন্ধ থাকলে খালি তালিকা — মিথ্যা তালিকা নয় */
    public function test_without_sales_the_list_is_empty(): void
    {
        $this->confirmedInvoice($this->customer, '1', '100');
        $this->app->bind(PartyOpenBills::class, NoPartyOpenBills::class);

        $this->assertSame([], $this->billsFor($this->customer));
        $this->assertSame([], app(PartyOpenBills::class)->openBills('supplier', 1));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function billsFor(Customer $customer): array
    {
        return $this->getJson(route('accounts.voucher.due', ['party_type' => 'customer', 'party_id' => $customer->id]))
            ->assertOk()->json('bills');
    }

    private function receiptAgainst(SalesInvoice $invoice, string $amount): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'party_type' => 'customer',
            'party_id' => $invoice->customer_id,
            'from_account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id,
            'to_account_id' => Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('code')->firstOrFail()->id,
            'against_type' => SalesInvoice::drillSourceType(),
            'against_id' => $invoice->id,
        ]);
    }

    private function newCustomer(string $name): Customer
    {
        $customer = app(CustomerService::class)->create(['name_en' => $name, 'credit_limit' => 0, 'credit_days' => 0]);
        $customer->forceFill(['credit_limit' => '1000000000'])->save();

        return $customer;
    }

    private function draftInvoice(Customer $customer, string $qty = '1', string $rate = '100'): SalesInvoice
    {
        return app(SalesInvoiceService::class)->create(
            ['customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => $qty, 'rate' => $rate]],
        );
    }

    private function confirmedInvoice(Customer $customer, string $qty, string $rate): SalesInvoice
    {
        return app(SalesInvoiceService::class)->confirm($this->draftInvoice($customer, $qty, $rate));
    }
}
