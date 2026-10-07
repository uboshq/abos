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
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\FarePayment;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * চালানের পরিবহন পর্দায় ভাড়া — পাকা চালানে লেখা, পরে-দেব ভাড়া দেওয়া, "ভাড়া বাকি" তালিকা (মালিক, ৭ অক্টোবর ২০২৬; [[FarePayment]])।
 *
 * দাবি:
 *  - পাকা চালানে ভাড়া নেই → পর্দায় লেখার ফর্ম; "এখনই" লিখলে বাছা টিল থেকে EV, দ্বিতীয়বার লেখা যায় না।
 *  - "পরে দেব" লিখলে বাহকের নামে ২১১৬; বোর্ডের "ভাড়া বাকি" ট্যাবে আসে; পর্দায় দেওয়ার ফর্ম।
 *  - দিলে PV: Dr ২১১৬ বাহকের নামে / Cr বাছা টিল; বাকির তালিকা থেকে সরে।
 *  - লেখক ≠ পাকাকারী: কর্মী দিলে PV খসড়া, অন্যের অপেক্ষায় — ভাড়া তখনো বাকি।
 *  - পরিবহন পর্দা সেভ করলে বাকি-ভাড়ার বাহক মোছে না; ভাউচারের চাবি ছাড়া দেওয়া যায় না (৪০৩)।
 */
final class TheTransportScreenRecordsAndPaysTheFareTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    private Account $till;

    private Supplier $carrier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $till = app(CashTillService::class)->create(['code' => 'TRN-F', 'name_en' => 'Transport fare till']);
        $this->till = Account::query()->findOrFail($till->account_id);
        $this->putMoneyIn($this->till, '2000');
        $this->carrier = Supplier::query()->create(['code' => 'TR-TRN', 'name_en' => 'Board Transport',
            'party_type_id' => PartyType::query()->where('code', 'TRANSPORT')->firstOrFail()->id]);
    }

    public function test_a_fare_recorded_now_on_a_confirmed_challan_is_an_expense_voucher_and_cannot_be_recorded_twice(): void
    {
        $challan = $this->confirmedChallan();

        $this->get(route('sales.challan.transport', $challan))->assertOk()->assertSee('data-fare-record', false);

        $this->post(route('sales.challan.fare.record', $challan), ['fare_paid_by' => 'us', 'transport_cost' => '250',
            'fare_when' => 'now', 'fare_account_id' => $this->till->id])->assertSessionHasNoErrors();

        $voucher = Voucher::query()->with('lines')->find($challan->fresh()->fare_voucher_id);
        $this->assertNotNull($voucher, '⛔ পর্দায় লেখা ভাড়ার ভাউচার নেই।');
        $this->assertSame([Voucher::EXPENSE, DocumentStatus::CONFIRMED], [$voucher->type, $voucher->status]);
        $this->assertSame($this->till->id, (int) $voucher->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 4) > 0)->account_id);

        $this->post(route('sales.challan.fare.record', $challan), ['fare_paid_by' => 'us', 'transport_cost' => '250',
            'fare_when' => 'now', 'fare_account_id' => $this->till->id])->assertSessionHasErrors(['fare' => __('sales::fare.already_recorded')]);
        $this->assertSame(1, Voucher::query()->where('against_type', DeliveryChallan::drillSourceType())->where('against_id', $challan->id)->count(),
            '⛔ একই চালানে ভাড়া দুইবার।');
    }

    public function test_a_fare_due_is_listed_paid_with_a_payment_voucher_and_leaves_the_list(): void
    {
        $challan = $this->confirmedChallan();
        $this->post(route('sales.challan.fare.record', $challan), ['fare_paid_by' => 'us', 'transport_cost' => '400',
            'fare_when' => 'later', 'carrier_id' => $this->carrier->id])->assertSessionHasNoErrors();

        $this->assertTrue(LedgerEntry::query()->where('source_type', DeliveryChallan::STOCK_SOURCE)->where('source_id', $challan->id)
            ->where('party_id', $this->carrier->id)->where('credit', '400.0000')->exists(), '⛔ পরে-দেব ভাড়া বাহকের দেনায় বসেনি।');
        $this->assertContains($challan->id, $this->dueOnBoard(), '⛔ "ভাড়া বাকি" ট্যাবে চালানটা নেই।');

        // পরিবহন পর্দা সেভ করলেও বাহক থাকে
        $this->put(route('sales.challan.transport.update', $challan), ['mode' => 'vehicle', 'vehicle_no' => 'ঢাকা-ট ১১'])->assertSessionHasNoErrors();
        $this->assertSame($this->carrier->id, (int) $challan->fresh()->carrier_id, '⛔ পরিবহন পর্দা বাকি-ভাড়ার বাহক মুছে দিল।');

        $this->get(route('sales.challan.transport', $challan))->assertOk()->assertSee('data-fare-pay', false);
        $before = $this->till->balanceOn();
        $this->post(route('sales.challan.fare.pay', $challan), ['fare_account_id' => $this->till->id])->assertSessionHasNoErrors();

        $voucher = Voucher::query()->with('lines.account')->find($challan->fresh()->fare_voucher_id);
        $this->assertSame([Voucher::PAYMENT, DocumentStatus::CONFIRMED], [$voucher->type, $voucher->status], '⛔ বাকি ভাড়া PV-তে পাকা হয়নি।');
        $debit = $voucher->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 4) > 0);
        $this->assertSame([StandardChart::TRANSPORT_PAYABLE, $this->carrier->id], [$debit->account->code, (int) $debit->party_id], '⛔ দেনা বাহকের নামে মোছেনি।');
        $this->assertSame(0, bccomp(bcsub($before, $this->till->fresh()->balanceOn(), 4), '400', 4), '⛔ বাছা টিল থেকে ৪০০ কমেনি।');
        $this->assertNotContains($challan->id, $this->dueOnBoard(), '⛔ দেওয়ার পরেও "ভাড়া বাকি" ট্যাবে।');
        $this->post(route('sales.challan.fare.pay', $challan), ['fare_account_id' => $this->till->id])
            ->assertSessionHasErrors(['fare' => __('sales::fare.nothing_due')]);

        // ⓘ ভুল PV বাতিল হলে ভাড়া আবার বাকি — তালিকায় ফেরে, আবার দেওয়া যায়
        app(\App\Modules\Accounts\Services\VoucherService::class)->cancel($voucher->fresh(), 'ভুল খাত');
        $this->assertTrue(app(FarePayment::class)->isDue($challan->fresh()), '⛔ PV বাতিলের পরে ভাড়া আবার বাকি হলো না।');
        $this->assertContains($challan->id, $this->dueOnBoard(), '⛔ PV বাতিলের পরে "ভাড়া বাকি" ট্যাবে ফেরেনি।');
    }

    public function test_a_clerk_pays_a_due_fare_only_into_a_draft_for_someone_else_and_needs_the_voucher_key(): void
    {
        $challan = $this->confirmedChallan();
        $this->post(route('sales.challan.fare.record', $challan), ['fare_paid_by' => 'us', 'transport_cost' => '300',
            'fare_when' => 'later', 'carrier_id' => $this->carrier->id])->assertSessionHasNoErrors();

        $clerk = User::factory()->create(['current_company_id' => CompanyContext::id()]);
        $clerk->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $clerk->givePermissionTo(['sales.challan.create', 'sales.challan.view']);
        $this->actingAs($clerk->fresh());
        $this->post(route('sales.challan.fare.pay', $challan), ['fare_account_id' => $this->till->id])->assertForbidden();

        $clerk->givePermissionTo(['accounts.voucher.create']);
        $this->actingAs($clerk->fresh());
        $this->post(route('sales.challan.fare.pay', $challan), ['fare_account_id' => $this->till->id])->assertSessionHasNoErrors();

        $voucher = Voucher::query()->find($challan->fresh()->fare_voucher_id);
        $this->assertSame(DocumentStatus::DRAFT, $voucher->status, '⛔ লেখক নিজেই ভাড়ার PV পাকা করলেন (লেখক ≠ পাকাকারী)।');
        $this->assertFalse(app(FarePayment::class)->isDue($challan->fresh()), 'খসড়া PV থাকলে আবার দেওয়া যায় না — দাবি অন্ধ।');
    }

    // ── যন্ত্রপাতি ──

    private function confirmedChallan(): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create(
            ['customer_id' => Customer::query()->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '50']],
        );

        return app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));
    }

    /** @return list<int> */
    private function dueOnBoard(): array
    {
        return collect($this->get(route('sales.transport.index', ['tab' => 'fare_due']))->assertOk()->viewData('challans')->items())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
