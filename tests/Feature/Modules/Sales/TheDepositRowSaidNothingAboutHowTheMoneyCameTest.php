<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ⛔ বিলের জমার ছকে "কোন পথে" ঘরটা ফাঁকা — মালিকের পাঠানো লাইভের ছবি, ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ঘটেছিল ─────────────────────────────────────────────────────────
 * ⓘ RCV-0006: কাউন্টারের নগদ ৫০,০০০, "Payment Method" খালি। ছকের সারি পড়ত কেবল
 * `money_account_id`, আর ঘরটা নকশা অনুযায়ীই বসে কেবল ব্যাংক-রেফারেন্সওয়ালা ভাউচারে
 * ([[VoucherService]], TrxID-এর অনন্যতার জন্য)। ⛔ কাউন্টারের প্রতিটা জমা তাই ফাঁকা ছাপত।
 *
 * ⭐ এখন টাকার খাত আসে ভাউচারের নিজের debit সারি থেকে ([[SalesPrintController::methodOf()]])।
 * ⓘ দাবিগুলো ছাপার **সারি** মাপে ([[paymentsAgainst()]]) — ক্লাসিক আর চলতি দুই নকশাই এই একই
 * সারি ছাপে, তাই একবার মাপাই দুইটার মাপা।
 */
final class TheDepositRowSaidNothingAboutHowTheMoneyCameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        /* ⓘ বাকির দেয়াল এই পরীক্ষার বিষয় নয় — ডেমো গ্রাহকের সীমা তুলে দেওয়া, দেয়াল নয় */
        // ⛔ ১ অক্টোবর ২০২৬ থেকে শূন্য সীমা মানে বাকি নেই (মালিকের চূড়ান্ত কথা) — তাই এই পরীক্ষার গ্রাহকের সত্যিকারের বড় সীমা
        Customer::query()->firstOrFail()->forceFill(['credit_limit' => '10000000000'])->save();
    }

    /** ⛔ ঠিক লাইভের অবস্থা: কাউন্টারের নগদ, `money_account_id` খালি, পদ্ধতির কোড `CASH` */
    public function test_a_counter_cash_deposit_names_the_cash_account(): void
    {
        $cash = $this->moneyAccount(Account::CASH);
        $invoice = $this->anInvoice();

        $this->aDeposit($invoice, $cash, 'CASH');

        $this->assertSame($cash->name(), $this->methodOn($invoice),
            '⛔ কাউন্টারের নগদ জমায় টাকার খাতের নাম আসেনি।');
    }

    /** ⛔ বিকাশ: পদ্ধতির কোড `BKASH` — কাঁচা কোড নয়, বিকাশের খাতের নাম */
    public function test_a_counter_bkash_deposit_names_the_mfs_account_not_the_code(): void
    {
        $mfs = $this->moneyAccount(Account::MFS);
        $invoice = $this->anInvoice();

        $this->aDeposit($invoice, $mfs, 'BKASH');

        $method = $this->methodOn($invoice);

        $this->assertSame($mfs->name(), $method, '⛔ বিকাশের জমায় খাতের নাম আসেনি।');
        $this->assertNotSame('BKASH', $method);
    }

    /**
     * ⓘ `money_account_id` বসা থাকলে সেটাই — এখানে debit সারির খাত ইচ্ছা করে আলাদা, যাতে
     * দুই ধাপের কোনটা উত্তর দিল তা আলাদা করে দেখা যায়।
     */
    public function test_the_money_account_wins_when_it_is_set(): void
    {
        $cash = $this->moneyAccount(Account::CASH);
        $mfs = $this->moneyAccount(Account::MFS);
        $invoice = $this->anInvoice();

        $voucher = $this->aDeposit($invoice, $cash, null);
        $voucher->forceFill(['money_account_id' => $mfs->id])->saveQuietly();

        $this->assertSame($mfs->name(), $this->methodOn($invoice));
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────

    private function moneyAccount(string $kind): Account
    {
        $found = Account::query()->postable()->ofMoneyKind($kind)->orderBy('id')->first();

        if ($found !== null) {
            return $found;
        }

        /*
         * ⓘ ডেমো ছকে কোনো MFS খাত নেই — কোম্পানি নিজে খোলে। তাই বিকাশের খাতটা এখানে,
         * মোবাইল মানির মাথার নিচে ([[MoneyAtTheCounterTest]]-এর একই সারি)।
         */
        return Account::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => '1105-BKASH',
            'name_en' => 'bKash Merchant',
            'name_bn' => 'বিকাশ মার্চেন্ট',
            'parent_id' => StandardChart::find(StandardChart::MOBILE_MONEY)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => $kind,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function anInvoice(): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            [
                'customer_id' => Customer::query()->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => '1', 'rate' => '5000.00']],
        ));
    }

    /** ⓘ কাউন্টারের মতো — `money_account_id` খালি, কেবল debit সারিতে টাকার খাত */
    private function aDeposit(SalesInvoice $invoice, Account $into, ?string $instrument): Voucher
    {
        $service = app(VoucherService::class);
        $receivable = Account::query()->postable()->where('code', StandardChart::RECEIVABLE)->firstOrFail();

        $voucher = $service->post($service->create(
            [
                'type' => Voucher::RECEIPT,
                'trx_date' => now()->toDateString(),
                'party_type' => 'customer',
                'party_id' => $invoice->customer_id,
                'narration' => 'Counter deposit',
                'against_type' => SalesInvoice::drillSourceType(),
                'against_id' => $invoice->id,

                /* ⓘ ব্যাংক/বিকাশে টাকা ঢুকলে লেনদেন নম্বর লাগে ([[VoucherService]]); নগদে সেটা অর্থহীন */
                'instrument_no' => $into->isCash() ? null : 'TRX-'.$invoice->id,
            ],
            $service->twoLineEntry(Voucher::RECEIPT, (int) $receivable->id, (int) $into->id, '2000', 'Deposit'),
        ));

        $voucher->forceFill(['money_account_id' => null, 'instrument' => $instrument])->saveQuietly();

        return $voucher;
    }

    private function methodOn(SalesInvoice $invoice): string
    {
        $rows = (new ReflectionMethod(SalesPrintController::class, 'paymentsAgainst'))
            ->invoke(app(SalesPrintController::class), $invoice->fresh());

        $this->assertCount(1, $rows, 'জমার ছকে ঠিক একটা সারি থাকার কথা।');

        return (string) $rows[0]['method'];
    }
}
