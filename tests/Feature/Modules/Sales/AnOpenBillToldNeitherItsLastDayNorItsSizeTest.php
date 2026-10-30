<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ রসিদের খোলা বিল শেষ দিন, দেরি আর মূল টাকা বলত না — মালিকের রসিদ-পরিশোধ নকশা (মিল-তালিকা দফা ৩৪, ২৪ অক্টোবর ২০২৬; fe, cb-র
 * সাথে মেলানো চুক্তি; [[PartyOpenBills]])।
 *
 * দাবি (রসিদের আসল দরজা `accounts.voucher.due` দিয়ে, স্থির তারিখে):
 *  - শেষ দিন = বিলের `due_on`; না থাকলে বিলের দিন (ঋণ নিয়ন্ত্রণের বকেয়া রিপোর্টের একই নিয়ম);
 *  - দেরি = শেষ দিন পেরিয়ে কত দিন; শেষ দিন আজ বা সামনে হলে ০ — কখনো ঋণাত্মক বা "সামনের দিন" দেরি নয়;
 *  - মূল টাকা = বিলের মোট, বাকি নয় (আংশিক শোধের পরেও);
 *  - `public_id` = বিলের নিজের (এক রসিদে বহু বিলের চাবি, cb-ma-4)।
 */
final class AnOpenBillToldNeitherItsLastDayNorItsSizeTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 11:00:00'));
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = app(CustomerService::class)->create(['name_en' => 'Due Day Customer', 'credit_limit' => 0, 'credit_days' => 0]);
        $this->customer->forceFill(['credit_limit' => '1000000000'])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_bill_past_its_last_day_says_how_late_and_its_full_size(): void
    {
        $invoice = $this->confirmedInvoice('3', '100');
        $invoice->forceFill(['due_on' => '2026-10-10'])->save();
        $this->receiptAgainst($invoice, '50');

        $bill = $this->billOf($invoice);

        $this->assertSame('2026-10-10', $bill['due_date'], '⛔ খোলা বিলের শেষ দিন বিলের নিজের শেষ দিন নয়।');
        $this->assertSame(10, $bill['days_late'], '⛔ শেষ দিন ১০ অক্টোবর, আজ ২০ — দেরি ১০ দিন বলা হলো না।');
        $this->assertSame(0, bccomp((string) $bill['total'], (string) $invoice->fresh()->total, 4),
            '⛔ মূল টাকার ঘরে বিলের মোট নেই (আংশিক শোধের পরে বাকিটা বসেছে?): '.$bill['total']);
        $this->assertNotSame(0, bccomp((string) $bill['total'], (string) $bill['outstanding'], 4),
            'দৃশ্যটাই ভুল — আংশিক শোধের পরেও মোট আর বাকি সমান।');
        $this->assertSame((string) $invoice->public_id, $bill['public_id'] ?? null, '⛔ খোলা বিলে বিলের public_id নেই।');
    }

    public function test_a_bill_not_yet_due_is_never_late(): void
    {
        $soon = $this->confirmedInvoice('1', '100');
        $soon->forceFill(['due_on' => '2026-10-30'])->save();
        $today = $this->confirmedInvoice('1', '100');
        $today->forceFill(['due_on' => '2026-10-20'])->save();

        $this->assertSame('2026-10-30', $this->billOf($soon)['due_date'], '⛔ সামনের শেষ দিন ঠিক বসেনি।');
        $this->assertSame(0, $this->billOf($soon)['days_late'], '⛔ শেষ দিন সামনে, তবু বিলটা "দেরি" দেখাচ্ছে।');
        $this->assertSame(0, $this->billOf($today)['days_late'], '⛔ শেষ দিন আজ, তবু বিলটা "দেরি" দেখাচ্ছে।');
    }

    public function test_a_bill_with_no_last_day_is_due_on_its_own_day(): void
    {
        $cash = $this->confirmedInvoice('1', '100');
        $cash->forceFill(['due_on' => null, 'trx_date' => '2026-10-17'])->save();

        $bill = $this->billOf($cash);

        $this->assertSame('2026-10-17', $bill['due_date'], '⛔ শেষ দিন ছাড়া বিলের শেষ দিন বিলের দিন নয়।');
        $this->assertSame(3, $bill['days_late'], '⛔ শেষ দিন ছাড়া বিল ১৭ অক্টোবরের, আজ ২০ — দেরি ৩ দিন বলা হলো না।');
    }

    /** @return array<string, mixed> */
    private function billOf(SalesInvoice $invoice): array
    {
        $bills = $this->getJson(route('accounts.voucher.due', ['party_type' => 'customer', 'party_id' => $this->customer->id]))
            ->assertOk()->json('bills');

        foreach ((array) $bills as $bill) {
            if ((int) $bill['id'] === (int) $invoice->id) {
                return $bill;
            }
        }

        $this->fail('দৃশ্যটাই ভুল — বিলটা খোলা বিলের তালিকায় নেই।');
    }

    private function confirmedInvoice(string $qty, string $rate): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => $qty, 'rate' => $rate]],
        ))->fresh();
    }

    private function receiptAgainst(SalesInvoice $invoice, string $amount): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'আংশিক শোধ',
            'amount' => $amount,
            'party_type' => 'customer',
            'party_id' => $invoice->customer_id,
            'from_account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id,
            'to_account_id' => Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('code')->firstOrFail()->id,
            'against_type' => SalesInvoice::drillSourceType(),
            'against_id' => $invoice->id,
        ]);
    }
}
