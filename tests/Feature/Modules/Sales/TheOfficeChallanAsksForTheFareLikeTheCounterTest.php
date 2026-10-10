<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\FarePayment;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * অফিসের চালানের পর্দাতেও ভাড়া কাউন্টারের মতো — মালিক, ৭ অক্টোবর ২০২৬: *"যা-ই করো, সব জায়গায় একই রকম"* ([[FarePayment]])।
 *
 * ⛔ আগে এই পর্দায় ভাড়ার অঙ্ক আর বাহকের নাম লেখা যেত, কিন্তু কে দেবে বা কোন খাত থেকে — না। পাকা করলে ভাড়া চুপচাপ
 * Main Counter থেকে কাটত।
 *
 * দাবি:
 *  - পর্দায় ঘরগুলো আছে (কে দেবে, বাহক, কখন, খাত, TrxID, কে দিলেন)।
 *  - "আমরা, এখনই": খসড়ায় নতুন নিয়ম বসে; পাকা করলে বাছা টিল থেকে EV — Main Counter নয়।
 *  - খাত ছাড়া থামে, কিছুই বসে না; পরে দেব বাহক ছাড়া থামে, বাহকসহ পাকা করলে ২১১৬-এ বাহকের নামে।
 *  - সম্পাদনায় "ক্রেতা দেবেন" বাছলে নতুন নিয়ম উঠে যায়, খাতায় কিছু নয়।
 *  - পর্দার ঘর ছাড়া অনুরোধ — ভাড়া আমাদের, বাহক নেই — তৈরি আর সম্পাদনায় থামে; প্রধান টিল অক্ষত।
 */
final class TheOfficeChallanAsksForTheFareLikeTheCounterTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $till = app(CashTillService::class)->create(['code' => 'OFC-F', 'name_en' => 'Office fare till']);
        $this->till = Account::query()->findOrFail($till->account_id);
        $this->putMoneyIn($this->till, '1000');
    }

    public function test_the_challan_form_asks_who_pays_and_from_which_account(): void
    {
        $html = $this->get(route('sales.challan.create'))->assertOk()->getContent();

        foreach (['data-challan-fare-form', 'name="fare_paid_by"', 'name="carrier_id"', 'name="fare_when"', 'name="fare_account_id"', 'name="fare_reference"', 'name="fare_payer_id"'] as $field) {
            $this->assertStringContainsString($field, $html, "⛔ চালানের ফর্মে {$field} নেই।");
        }
    }

    public function test_paid_now_from_the_form_is_an_expense_voucher_from_the_chosen_till_when_confirmed(): void
    {
        $this->post(route('sales.challan.store'), $this->form(['fare_paid_by' => 'us', 'fare_when' => 'now', 'fare_account_id' => $this->till->id]))
            ->assertSessionHasNoErrors();
        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();
        $this->assertSame([FarePayment::RULE, FarePayment::NOW], [$challan->fare_rule, $challan->fare_status], '⛔ ফর্মের ভাড়া নতুন নিয়মে বসেনি।');
        // ⓘ কে দেবে — চালানে থাকে, গেট পাস আর কাগজে ছাপা হয়; null আর "আমরা" খাতায় এক রকম চললেও কাগজে নয়
        $this->assertSame('us', $challan->fare_paid_by, '⛔ "ভাড়া কে দেবে" চালানে বসেনি।');

        $before = $this->till->balanceOn();
        $this->post(route('sales.challan.confirm', $challan))->assertSessionHasNoErrors();

        $voucher = Voucher::query()->with('lines')->find($challan->fresh()->fare_voucher_id);
        $this->assertNotNull($voucher, '⛔ অফিসের চালানের ভাড়ার ভাউচার নেই — Main Counter-এ গেল?');
        $this->assertSame([Voucher::EXPENSE, DocumentStatus::CONFIRMED], [$voucher->type, $voucher->status]);
        $this->assertSame(0, bccomp(bcsub($before, $this->till->fresh()->balanceOn(), 4), '150', 4), '⛔ বাছা টিল থেকে ১৫০ কমেনি।');
        $this->assertFalse(LedgerEntry::query()->where('source_type', DeliveryChallan::STOCK_SOURCE)->where('source_id', $challan->id)->exists(),
            '⛔ ভাউচারের সাথে চালানের নিজের দাখিলাতেও ভাড়া (Main Counter-এর পুরনো পথ)।');
    }

    public function test_no_account_stops_and_pay_later_needs_a_carrier(): void
    {
        $count = DeliveryChallan::query()->count();

        $this->post(route('sales.challan.store'), $this->form(['fare_paid_by' => 'us', 'fare_when' => 'now']))
            ->assertSessionHasErrors(['fare_account_id' => __('sales::fare.needs_account')]);
        $this->post(route('sales.challan.store'), $this->form(['fare_paid_by' => 'us', 'fare_when' => 'later']))
            ->assertSessionHasErrors(['carrier_id' => __('sales::fare.later_needs_carrier')]);
        $this->assertSame($count, DeliveryChallan::query()->count(), '⛔ থেমে যাওয়া চালান থেকে গেল।');

        $carrier = Supplier::query()->create(['code' => 'TR-OFC', 'name_en' => 'Office Transport',
            'party_type_id' => PartyType::query()->where('code', 'TRANSPORT')->firstOrFail()->id]);
        $this->post(route('sales.challan.store'), $this->form(['fare_paid_by' => 'us', 'fare_when' => 'later', 'carrier_id' => $carrier->id]))
            ->assertSessionHasNoErrors();
        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();
        $this->post(route('sales.challan.confirm', $challan))->assertSessionHasNoErrors();

        $this->assertTrue(LedgerEntry::query()->join('accounts as a', 'a.id', '=', 'ledger_entries.account_id')
            ->where('a.code', StandardChart::TRANSPORT_PAYABLE)->where('ledger_entries.source_type', DeliveryChallan::STOCK_SOURCE)
            ->where('ledger_entries.source_id', $challan->id)->where('ledger_entries.party_id', $carrier->id)->where('ledger_entries.credit', '150.0000')->exists(),
            '⛔ পরে-দেওয়া ভাড়া বাহকের দেনায় বসেনি।');
    }

    public function test_switching_to_customer_pays_on_edit_drops_the_rule_and_books_nothing(): void
    {
        $this->post(route('sales.challan.store'), $this->form(['fare_paid_by' => 'us', 'fare_when' => 'now', 'fare_account_id' => $this->till->id]))
            ->assertSessionHasNoErrors();
        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();

        $this->put(route('sales.challan.update', $challan), $this->form(['fare_paid_by' => 'customer', 'fare_when' => 'now']))->assertSessionHasNoErrors();
        $this->assertNull($challan->fresh()->fare_rule, '⛔ "ক্রেতা দেবেন" বাছার পরেও নতুন নিয়ম রয়ে গেল।');

        $this->post(route('sales.challan.confirm', $challan))->assertSessionHasNoErrors();
        $this->assertNull($challan->fresh()->fare_voucher_id);
        $this->assertFalse(LedgerEntry::query()->where('source_type', DeliveryChallan::STOCK_SOURCE)->where('source_id', $challan->id)->exists(),
            '⛔ ক্রেতার ভাড়া আমাদের খাতায় বসল।');
    }

    /**
     * ⛔ পর্দার ঘর ছাড়া অনুরোধ (হাতে বানানো, বা পুরনো খোলা পাতা) — ভাড়া আমাদের, বাহক নেই: আগে পাকা হলে প্রধান টিল থেকে
     * নিজে থেকে কাটত। এখন তৈরি আর সম্পাদনা, দুই দরজাতেই থামে; বাহক থাকলে বা ক্রেতা দিলে চলে, প্রধান টিল অক্ষত
     * (পুরো ভাড়া-প্রবাহ যাচাই, ১০ অক্টোবর ২০২৬)।
     */
    public function test_a_request_without_the_fare_fields_cannot_reach_the_main_counter(): void
    {
        $main = app(CashTillService::class)->ensurePrimaryTill()->account;
        $mainBefore = $main->balanceOn();
        $count = DeliveryChallan::query()->count();

        $this->post(route('sales.challan.store'), $this->form(['fare_paid_by' => 'us']))
            ->assertSessionHasErrors(['fare_account_id' => __('sales::fare.needs_account')]);
        // ⓘ "কে দেবে" না পাঠালেও খালি মানে আমরা — একই থামা
        $this->post(route('sales.challan.store'), $this->form([]))
            ->assertSessionHasErrors(['fare_account_id' => __('sales::fare.needs_account')]);
        $this->assertSame($count, DeliveryChallan::query()->count(), '⛔ থেমে যাওয়া চালান থেকে গেল।');

        // ⓘ সম্পাদনার দরজাও — ক্রেতার ভাড়ার খসড়ায় ঘর ছাড়া "আমরা" পাঠালে থামে, খসড়া বদলায় না
        $this->post(route('sales.challan.store'), $this->form(['fare_paid_by' => 'customer']))->assertSessionHasNoErrors();
        $customerPays = DeliveryChallan::query()->latest('id')->firstOrFail();
        $this->put(route('sales.challan.update', $customerPays), $this->form(['fare_paid_by' => 'us']))
            ->assertSessionHasErrors(['fare_account_id' => __('sales::fare.needs_account')]);
        $this->assertSame('customer', $customerPays->fresh()->fare_paid_by, '⛔ থেমে যাওয়া সম্পাদনা খসড়া বদলে দিল।');
        $this->post(route('sales.challan.confirm', $customerPays))->assertSessionHasNoErrors();

        $carrier = Supplier::query()->create(['code' => 'TR-OLD', 'name_en' => 'Old Door Transport',
            'party_type_id' => PartyType::query()->where('code', 'TRANSPORT')->firstOrFail()->id]);
        $this->post(route('sales.challan.store'), $this->form(['fare_paid_by' => 'us', 'carrier_id' => $carrier->id]))->assertSessionHasNoErrors();
        $withCarrier = DeliveryChallan::query()->latest('id')->firstOrFail();
        $this->post(route('sales.challan.confirm', $withCarrier))->assertSessionHasNoErrors();
        $this->assertTrue(LedgerEntry::query()->join('accounts as a', 'a.id', '=', 'ledger_entries.account_id')
            ->where('a.code', StandardChart::TRANSPORT_PAYABLE)->where('ledger_entries.source_type', DeliveryChallan::STOCK_SOURCE)
            ->where('ledger_entries.source_id', $withCarrier->id)->where('ledger_entries.party_id', $carrier->id)->where('ledger_entries.credit', '150.0000')->exists(),
            '⛔ বাহকসহ পুরনো দরজার ভাড়া বাহকের দেনায় বসেনি।');

        $this->assertSame(0, bccomp($mainBefore, $main->fresh()->balanceOn(), 4), '⛔ প্রধান টিল থেকে ভাড়া কাটল।');
    }

    /**
     * ⓘ অফিসের চালান কেবল আদেশ থেকে ([[DeliveryChallanRequest::authorize()]]) — তাই প্রতিটা ফর্মের নিজের নিশ্চিত আদেশ।
     * ⚠️ আদেশ ছাড়া ৪০৩ আসত, আর সেটা "ত্রুটি নেই" দেখাত — দাবি অন্ধ থাকত।
     *
     * @return array<string, mixed>
     */
    private function form(array $extra): array
    {
        $customer = Customer::query()->firstOrFail();
        $warehouse = Warehouse::query()->firstOrFail();
        $product = Product::query()->orderBy('id')->firstOrFail();
        $orders = app(\App\Modules\Sales\Services\SalesOrderService::class);
        $order = $orders->confirm($orders->create(
            ['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'ordered_qty' => '1', 'rate' => '100']],
        ));

        return [
            'sales_order_id' => $order->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => '0',
            'vehicle_no' => 'ঢাকা-অ ১২',
            'transport_cost' => '150',
            'lines' => [['product_id' => $product->id, 'delivered_qty' => '1', 'rate' => '100']],
            ...$extra,
        ];
    }
}
