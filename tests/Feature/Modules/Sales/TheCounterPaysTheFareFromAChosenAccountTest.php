<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
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
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\FarePayment;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * কাউন্টারে ভাড়া — কোন খাত থেকে, কে দিলেন, আর পূর্ণাঙ্গ খরচ ভাউচার (মালিক, ৭ অক্টোবর ২০২৬; [[FarePayment]])।
 *
 * দাবি:
 *  - কাউন্টারের পাতায় ঘরগুলো আছে (কখন, খাত, TrxID, কে দিলেন)।
 *  - "আমরা দেব, এখনই": বাছা টিল থেকে EV, চালানে বাঁধা; চালানের নিজের দাখিলায় ভাড়া নেই; বিলের মোটে ভাড়া নেই।
 *  - "আমরা দিয়ে বিলে যোগ": EV, আর বিলে ভাড়ার সারি — ৪৩৬০ আয়ে।
 *  - খাত না বাছলে বিক্রিই হয় না (কিছুই বসে না); পরে দেব বাহক ছাড়া হয় না, বাহকসহ হলে ২১১৬-এ দেনা।
 */
final class TheCounterPaysTheFareFromAChosenAccountTest extends TestCase
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
        app(StandardChart::class)->install();
        Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->forceFill(['credit_limit' => '1000000'])->save();

        $till = app(CashTillService::class)->create(['code' => 'CTR-F', 'name_en' => 'Counter fare till']);
        $this->till = Account::query()->findOrFail($till->account_id);
        $this->putMoneyIn($this->till, '1000');
    }

    public function test_the_counter_page_asks_where_the_fare_came_from(): void
    {
        $html = $this->get(route('sales.direct.create'))->assertOk()->getContent();

        foreach (['name="fare_when"', 'name="fare_account_id"', 'name="fare_reference"', 'name="fare_payer_id"'] as $field) {
            $this->assertStringContainsString($field, $html, "⛔ কাউন্টারের পাতায় {$field} নেই।");
        }
    }

    public function test_paid_now_is_an_expense_voucher_from_the_chosen_till_and_not_on_the_bill(): void
    {
        [$challan, $invoice] = $this->sell('us', ['fare_when' => 'now', 'fare_account_id' => $this->till->id]);

        $voucher = Voucher::query()->with('lines.account')->find($challan->fare_voucher_id);
        $this->assertNotNull($voucher, '⛔ কাউন্টারের ভাড়ায় কোনো ভাউচার নেই।');
        $this->assertSame([Voucher::EXPENSE, DocumentStatus::CONFIRMED], [$voucher->type, $voucher->status]);
        $this->assertSame($this->till->id, (int) $voucher->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 4) > 0)->account_id,
            '⛔ টাকা বাছা টিল থেকে নয়।');
        $this->assertSame('0.0000', $this->booked(StandardChart::VEHICLE_HIRE, 'debit', DeliveryChallan::STOCK_SOURCE, $challan->id),
            '⛔ ভাউচারের সাথে চালানের নিজের দাখিলাতেও ভাড়া — দুইবার খরচ।');
        $this->assertSame('20.0000', (string) $invoice->total, '⛔ "আমরা দেব"-তে বিলে ভাড়া এল।');
    }

    public function test_paid_and_added_to_the_bill_is_a_voucher_and_a_fare_line_on_the_bill(): void
    {
        [$challan, $invoice] = $this->sell('us_add_to_bill', ['fare_when' => 'now', 'fare_account_id' => $this->till->id]);

        $this->assertNotNull($challan->fare_voucher_id, '⛔ "বিলে যোগ"-এ ভাড়ার ভাউচার নেই।');
        $this->assertSame('100.0000', (string) $invoice->freight_charge);
        $this->assertSame('100.0000', $this->booked(StandardChart::FREIGHT_INCOME, 'credit', SalesInvoice::drillSourceType(), $invoice->id),
            '⛔ বিলের ভাড়া আয়ে (৪৩৬০) বসেনি।');
    }

    public function test_no_account_stops_the_sale_and_pay_later_needs_a_carrier(): void
    {
        $challans = DeliveryChallan::query()->count();

        $this->post(route('sales.direct.store'), $this->form('us', ['fare_when' => 'now']))
            ->assertSessionHasErrors(['fare_account_id' => __('sales::fare.needs_account')]);
        $this->post(route('sales.direct.store'), $this->form('us', ['fare_when' => 'later', 'confirm_duplicate' => '1']))
            ->assertSessionHasErrors(['carrier_id' => __('sales::fare.later_needs_carrier')]);
        $this->assertSame($challans, DeliveryChallan::query()->count(), '⛔ থেমে যাওয়া বিক্রির চালান থেকে গেল।');

        $carrier = Supplier::query()->create(['code' => 'TR-CTR', 'name_en' => 'Counter Transport',
            'party_type_id' => PartyType::query()->where('code', 'TRANSPORT')->firstOrFail()->id]);
        [$challan] = $this->sell('us', ['fare_when' => 'later', 'carrier_id' => $carrier->id, 'confirm_duplicate' => '1']);

        $this->assertSame(FarePayment::DUE, $challan->fare_status);
        $this->assertSame('100.0000', $this->booked(StandardChart::TRANSPORT_PAYABLE, 'credit', DeliveryChallan::STOCK_SOURCE, $challan->id),
            '⛔ পরে-দেওয়া ভাড়া বাহকের দেনায় (২১১৬) বসেনি।');
    }

    // ── যন্ত্রপাতি ──

    /** @return array{0: DeliveryChallan, 1: SalesInvoice} */
    private function sell(string $farePaidBy, array $extra = []): array
    {
        $this->post(route('sales.direct.store'), $this->form($farePaidBy, $extra))->assertSessionHasNoErrors();

        return [DeliveryChallan::query()->latest('id')->firstOrFail(), SalesInvoice::query()->latest('id')->firstOrFail()];
    }

    /** @return array<string, mixed> */
    private function form(string $farePaidBy, array $extra = []): array
    {
        return [
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'vehicle_owner' => 'hired',
            'vehicle_no' => 'ঢাকা মেট্রো ন ১২-৩৪৫৬',
            'driver_name' => 'রহিম',
            'driver_phone' => '01711000000',
            'transport_cost' => '100',
            'fare_paid_by' => $farePaidBy,
            'lines' => [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
            ...$extra,
        ];
    }

    private function booked(string $code, string $side, string $sourceType, int $sourceId): string
    {
        return bcadd((string) DB::table('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('a.code', $code)->where('le.source_type', $sourceType)->where('le.source_id', $sourceId)->sum('le.'.$side), '0', 4);
    }
}
