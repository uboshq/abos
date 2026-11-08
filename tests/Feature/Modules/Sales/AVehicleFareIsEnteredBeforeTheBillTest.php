<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ গাড়ি ভাড়া বসানো বাধ্যতামূলক — মালিক, ৭ নভেম্বর ২০২৬ (নমুনা "ok"):
 * *"delivary te coroch ba vara dite hole obosoi ta bosiye inv print r confm korte hobe"*;
 * *"gari lagbe na, kreta diben, vara nai — egulote zegor gulo lagena segulo zate na ase"*।
 *
 * আমাদের বা ভাড়ার গাড়ি আর ভাড়া আমরা দিলে অঙ্ক ছাড়া কাউন্টারে বিক্রি হয় না, বিল ছাপা আর নিশ্চিত হয় না। ক্রেতার গাড়ি বা ক্রেতা
 * দিলে কিছুই লাগে না। চালান আর বিলের পাতায় "গাড়ি ও ভাড়া", ভাউচার থেকে চালানে ফেরা, হাতে লেখা বাহকের মোবাইল।
 * ⛔ আগে অঙ্ক খালি রাখলেও বিক্রি নিশ্চিত হত, বিল ছাপা হত, ভাড়া খাতার বাইরে থেকে যেত।
 */
final class AVehicleFareIsEnteredBeforeTheBillTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        // ⓘ নিয়মটা ৮ নভেম্বর থেকে ([[FarePayment::AMOUNT_REQUIRED_FROM]]) — তারিখ বাঁধা, যাতে মধ্যরাতে লাল না হয়
        Carbon::setTestNow(Carbon::parse('2026-11-09 11:00:00'));
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->forceFill(['credit_limit' => '1000000'])->save();

        $till = app(CashTillService::class)->create(['code' => 'CTR-V', 'name_en' => 'Vehicle fare till']);
        $this->till = Account::query()->findOrFail($till->account_id);
        $this->putMoneyIn($this->till, '1000', '2026-11-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_our_or_a_hired_vehicle_paid_by_us_needs_the_amount_at_the_counter(): void
    {
        foreach (['hired', 'own'] as $vehicle) {
            foreach (['us', 'us_add_to_bill'] as $who) {
                $before = DeliveryChallan::query()->count();
                $this->post(route('sales.direct.store'), $this->form($vehicle, $who, '', ['fare_when' => 'now', 'fare_account_id' => $this->till->id]))
                    ->assertSessionHasErrors(['transport_cost' => __('sales::fare.needs_amount_vehicle')]);
                $this->assertSame($before, DeliveryChallan::query()->count(), "⛔ {$vehicle} গাড়ি, ভাড়া {$who}, অঙ্ক নেই — তবু বিক্রি হয়ে গেল।");
            }
        }
    }

    public function test_the_customer_vehicle_or_the_customer_paying_needs_no_amount(): void
    {
        foreach ([['customer', ''], ['none', ''], ['hired', 'customer'], ['own', 'none']] as $i => [$vehicle, $who]) {
            // ⓘ প্রতিবার আলাদা পরিমাণ — একই বিল পরপর দুবার "আবার করবেন?" পাহারায় থামে
            $this->post(route('sales.direct.store'), $this->form($vehicle, $who, '', ['qty_bump' => $i]))->assertSessionHasNoErrors();
            $challan = DeliveryChallan::query()->latest('id')->firstOrFail();
            $this->assertSame($vehicle, $challan->vehicle_owner, 'দৃশ্যটাই ভুল — চালানে গাড়ির মালিক বসেনি।');
            $this->assertNull($challan->fare_voucher_id, "⛔ {$vehicle} / {$who}: ভাড়া নেই, তবু ভাড়ার ভাউচার।");
        }
    }

    public function test_a_bill_whose_fare_is_owed_neither_prints_nor_confirms(): void
    {
        [$challan, $invoice] = $this->sell();
        // ⓘ অঙ্কটা মুছে ভাড়া-বাকির অবস্থা — যেমন অফিসের চালানে পরে লেখার কথা ছিল
        $challan->forceFill(['transport_cost' => null, 'fare_voucher_id' => null, 'fare_rule' => null, 'fare_status' => null])->save();

        $this->get(route('sales.print.invoice', $invoice))
            ->assertSessionHasErrors(['transport_cost' => __('sales::fare.amount_before_bill', ['challan' => $challan->document_no])]);

        $html = (string) $this->get(route('sales.invoice.show', $invoice))->assertOk()->getContent();
        $this->assertStringContainsString('data-vehicle-fare="owed"', $html, '⛔ বিলের পাতায় লাল "গাড়ি ও ভাড়া" নেই — কেন ছাপা আটকে, বোঝা যায় না।');
        // ⓘ ছাপার মেনুর নিজের চিহ্ন (`data-print-lang`) — ঠিকানাটা মেনু অন্যভাবে লেখে, তাই সেটা খুঁজলে কিছুই মিলত না (Mac, ৮ নভেম্বর: V7 বেঁচে ছিল)
        $this->assertStringNotContainsString('data-print-lang', $html, '⛔ ভাড়া বাকি, তবু বিলের পাতায় ছাপার বোতাম।');

        $invoice->forceFill(['status' => DocumentStatus::DRAFT])->save();
        try {
            app(SalesInvoiceService::class)->confirm($invoice->fresh());
            $this->fail('⛔ ভাড়া বাকি, তবু বিল নিশ্চিত হল।');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(ValidationException::class, $e, '⛔ ভাড়া বাকি, তবু বিল নিশ্চিত করার পথে এগোল (থামল অন্য কারণে: '.class_basename($e).')।');
            $this->assertSame(__('sales::fare.amount_before_bill', ['challan' => $challan->document_no]), $e->errors()['transport_cost'][0] ?? null,
                '⛔ বিল থামল, কিন্তু ভাড়ার কারণে নয়।');
        }
    }

    public function test_a_challan_from_before_the_rule_still_prints(): void
    {
        [$challan, $invoice] = $this->sell();
        $challan->forceFill(['transport_cost' => null, 'fare_voucher_id' => null, 'fare_rule' => null, 'fare_status' => null,
            'created_at' => Carbon::parse('2026-11-05 10:00:00')])->save();

        $this->get(route('sales.print.invoice', $invoice))->assertSessionHasNoErrors()->assertOk();
    }

    public function test_the_challan_bill_and_voucher_pages_link_the_fare(): void
    {
        [$challan, $invoice] = $this->sell();
        $this->assertNotNull($challan->fare_voucher_id, 'দৃশ্যটাই ভুল — ভাড়ার ভাউচার হয়নি।');
        $voucherUrl = route('accounts.voucher.show', $challan->fare_voucher_id);

        foreach ([route('sales.challan.show', $challan), route('sales.invoice.show', $invoice)] as $page) {
            $html = (string) $this->get($page)->assertOk()->getContent();
            $this->assertStringContainsString('data-vehicle-fare="set"', $html, "⛔ {$page}: \"গাড়ি ও ভাড়া\" নেই।");
            // ⓘ উল্টো যাচাই — ভাড়া বসানো বিলে ছাপার মেনু থাকে, তাই উপরের "নেই" চিহ্নটা সত্যিই মেনুর
            if ($page === route('sales.invoice.show', $invoice)) {
                $this->assertStringContainsString('data-print-lang', $html, 'দৃশ্যটাই ভুল — ভাড়া বসানো বিলেও ছাপার মেনুর চিহ্ন নেই।');
            }
            $this->assertStringContainsString('href="'.e($voucherUrl).'"', $html, "⛔ {$page}: ভাড়ার ভাউচারের লিংক নেই।");
            $this->assertStringContainsString('ঢাকা মেট্রো ন ১২-৩৪৫৬', $html, "⛔ {$page}: গাড়ির নম্বর নেই।");
        }

        $voucher = (string) $this->get($voucherUrl)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-voucher-for-paper.*?href="'.preg_quote(e(route('sales.challan.show', $challan)), '/').'"/s', $voucher,
            '⛔ ভাড়ার ভাউচার থেকে চালানে ফেরার লিংক নেই।');
    }

    public function test_a_carrier_typed_by_hand_keeps_its_mobile(): void
    {
        [$challan] = $this->sell(['carrier_name' => 'নতুন বাহক', 'carrier_phone' => '01700-111222']);
        $this->assertSame('01700-111222', $challan->carrier_phone, '⛔ হাতে লেখা বাহকের মোবাইল চালানে জমা হয়নি।');

        $html = (string) $this->get(route('sales.challan.show', $challan))->assertOk()->getContent();
        $this->assertStringContainsString('01700-111222', $html, '⛔ চালানের পাতায় বাহকের মোবাইল নেই।');
    }

    /** @return array{0: DeliveryChallan, 1: SalesInvoice} */
    private function sell(array $extra = []): array
    {
        $this->post(route('sales.direct.store'), $this->form('hired', 'us', '100', ['fare_when' => 'now', 'fare_account_id' => $this->till->id, ...$extra]))
            ->assertSessionHasNoErrors();

        return [DeliveryChallan::query()->latest('id')->firstOrFail(), SalesInvoice::query()->latest('id')->firstOrFail()];
    }

    /** @return array<string, mixed> */
    private function form(string $vehicle, string $who, string $amount, array $extra = []): array
    {
        return [
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'vehicle_owner' => $vehicle,
            'vehicle_no' => 'ঢাকা মেট্রো ন ১২-৩৪৫৬',
            'driver_name' => 'রহিম',
            'driver_phone' => '01711000000',
            'transport_cost' => $amount,
            'fare_paid_by' => $who,
            'lines' => [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => (string) (2 + (int) ($extra['qty_bump'] ?? 0)), 'rate' => '10', 'free_qty' => '0']],
            ...array_diff_key($extra, ['qty_bump' => true]),
        ];
    }
}
