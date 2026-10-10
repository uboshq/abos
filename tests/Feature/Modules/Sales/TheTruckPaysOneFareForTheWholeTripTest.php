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
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\FarePayment;
use App\Modules\Sales\Services\ShipmentService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * এক ট্রাক, এক ভাড়া, এক ভাউচার — মালিক, ৭ অক্টোবর ২০২৬; fe-র সিদ্ধান্ত ঘ ([[FarePayment::bookTrip()]])।
 *
 * ⛔ ট্রিপে (লোডিং শিট) ভাড়ার কোনো ঘর ছিল না; কয়েক চালানের এক ট্রাকের ভাড়া লেখার জায়গা ছিল না।
 *
 * দাবি:
 *  - ট্রিপের ফর্মে ভাড়ার ঘর আছে।
 *  - এখনই দিলাম: খসড়ায় কিছু বসে না; ট্রাক রওনা হলে একটা EV against ট্রিপ, বাছা টিল থেকে, বিবরণে দুই চালানই।
 *    ট্রিপ বাতিলে EV বাতিল, টাকা ফেরে।
 *  - পরে দেব: বাহক ছাড়া থামে; রওনা হলে ২১১৬-এ বাহকের নামে; পাতায় দেওয়ার ফর্ম; দিলে PV Dr ২১১৬; আবার দেওয়া যায় না।
 *  - এক ট্রাকের ভাড়া একবারই: ভাড়াওয়ালা ট্রিপে নিজের ভাড়াওয়ালা চালান ওঠে না; ভাড়াওয়ালা ট্রিপের চালানে পরে ভাড়া লেখা
 *    যায় না; ভাড়াহীন ট্রিপে চালানের নিজের ভাড়া থাকে।
 *  - ভাড়ার ঘর ছাড়া পুরনো ট্রিপ: রওনায় খাতায় কিছু নয়।
 */
final class TheTruckPaysOneFareForTheWholeTripTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Account $till;

    private Supplier $carrier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $till = app(CashTillService::class)->create(['code' => 'TRP-F', 'name_en' => 'Trip fare till']);
        $this->till = Account::query()->findOrFail($till->account_id);
        $this->putMoneyIn($this->till, '5000');
        $this->carrier = Supplier::query()->create(['code' => 'TR-TRP', 'name_en' => 'Truck Owner',
            'party_type_id' => PartyType::query()->where('code', 'TRANSPORT')->firstOrFail()->id]);
    }

    public function test_the_trip_form_asks_for_the_truck_fare(): void
    {
        $html = $this->get(route('sales.shipment.create'))->assertOk()->getContent();

        foreach (['data-trip-fare-form', 'name="transport_cost"', 'name="carrier_id"', 'name="fare_when"', 'name="fare_account_id"'] as $field) {
            $this->assertStringContainsString($field, $html, "⛔ ট্রিপের ফর্মে {$field} নেই।");
        }
    }

    public function test_paid_now_is_one_expense_voucher_against_the_trip_when_the_truck_leaves_and_cancels_with_it(): void
    {
        $a = $this->challan();
        $b = $this->challan();
        $trip = $this->trip([$a, $b], ['transport_cost' => '900', 'fare_when' => 'now', 'fare_account_id' => $this->till->id]);
        $this->assertSame([FarePayment::RULE, FarePayment::NOW], [$trip->fare_rule, $trip->fare_status], '⛔ ট্রিপের ভাড়া নতুন নিয়মে বসেনি।');
        $this->assertNull($trip->fare_voucher_id, '⛔ খসড়া ট্রিপেই ভাড়ার ভাউচার — ট্রাক তো এখনো রওনা হয়নি।');

        $before = $this->till->balanceOn();
        $trip = app(ShipmentService::class)->dispatch($trip);

        $vouchers = Voucher::query()->where('against_type', Shipment::drillSourceType())->where('against_id', $trip->id)->get();
        $this->assertCount(1, $vouchers, '⛔ এক ট্রাকের ভাড়ায় একটাই ভাউচার হওয়ার কথা।');
        $voucher = $vouchers->first();
        $this->assertSame([Voucher::EXPENSE, DocumentStatus::CONFIRMED], [$voucher->type, $voucher->status]);
        $this->assertSame(0, bccomp(bcsub($before, $this->till->fresh()->balanceOn(), 4), '900', 4), '⛔ বাছা টিল থেকে ৯০০ কমেনি।');
        $this->assertStringContainsString($a->document_no, (string) $voucher->narration, '⛔ বিবরণে ট্রিপের চালান নেই।');
        $this->assertStringContainsString($b->document_no, (string) $voucher->narration, '⛔ বিবরণে ট্রিপের দ্বিতীয় চালান নেই।');
        $this->get(route('sales.shipment.show', $trip))->assertOk()->assertSee('data-trip-fare', false)->assertSee($voucher->document_no);

        app(ShipmentService::class)->cancel($trip->fresh(), 'ভুল ট্রিপ');
        $this->assertSame(DocumentStatus::CANCELLED, $voucher->fresh()->status, '⛔ ট্রিপ বাতিলে ভাড়ার ভাউচার বাতিল হয়নি।');
        $this->assertSame(0, bccomp($before, $this->till->fresh()->balanceOn(), 4), '⛔ বাতিলের পরে টাকা টিলে ফেরেনি।');
    }

    public function test_pay_later_is_the_carriers_due_when_the_truck_leaves_and_is_paid_once_from_the_trip_page(): void
    {
        $this->refused(fn () => $this->trip([$this->challan()], ['transport_cost' => '700', 'fare_when' => 'later']),
            'carrier_id', __('sales::fare.later_needs_carrier'));

        $trip = $this->trip([$this->challan()], ['transport_cost' => '700', 'fare_when' => 'later', 'carrier_id' => $this->carrier->id]);
        $trip = app(ShipmentService::class)->dispatch($trip);

        $this->assertTrue(LedgerEntry::query()->join('accounts as a', 'a.id', '=', 'ledger_entries.account_id')
            ->where('a.code', StandardChart::TRANSPORT_PAYABLE)->where('ledger_entries.source_type', FarePayment::TRIP_SOURCE)
            ->where('ledger_entries.source_id', $trip->id)->where('ledger_entries.party_id', $this->carrier->id)
            ->where('ledger_entries.credit', '700.0000')->exists(), '⛔ ট্রিপের পরে-দেওয়া ভাড়া বাহকের দেনায় বসেনি।');
        $this->assertTrue(app(FarePayment::class)->isDue($trip->fresh()));
        $this->get(route('sales.shipment.show', $trip))->assertOk()->assertSee('data-trip-fare-pay', false);

        $this->post(route('sales.shipment.fare.pay', $trip), ['fare_account_id' => $this->till->id])->assertSessionHasNoErrors();
        $voucher = Voucher::query()->with('lines.account')->find($trip->fresh()->fare_voucher_id);
        $this->assertSame([Voucher::PAYMENT, DocumentStatus::CONFIRMED], [$voucher->type, $voucher->status], '⛔ ট্রিপের বাকি ভাড়া PV-তে পাকা হয়নি।');
        $debit = $voucher->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 4) > 0);
        $this->assertSame([StandardChart::TRANSPORT_PAYABLE, $this->carrier->id], [$debit->account->code, (int) $debit->party_id], '⛔ দেনা বাহকের নামে মোছেনি।');

        $this->post(route('sales.shipment.fare.pay', $trip), ['fare_account_id' => $this->till->id])
            ->assertSessionHasErrors(['fare' => __('sales::fare.nothing_due')]);
    }

    public function test_cancelling_a_pay_later_trip_reverses_the_carriers_due(): void
    {
        $trip = app(ShipmentService::class)->dispatch(
            $this->trip([$this->challan()], ['transport_cost' => '600', 'fare_when' => 'later', 'carrier_id' => $this->carrier->id]));

        app(ShipmentService::class)->cancel($trip->fresh(), 'ট্রাক ফেরত গেল');

        $net = LedgerEntry::query()->where('party_type', 'supplier')->where('party_id', $this->carrier->id)
            ->whereIn('source_type', [FarePayment::TRIP_SOURCE, FarePayment::TRIP_SOURCE.':reversal'])->where('source_id', $trip->id)
            ->get(['debit', 'credit'])->reduce(fn ($s, $r) => bcadd($s, bcsub((string) $r->credit, (string) $r->debit, 4), 4), '0');
        $this->assertSame(0, bccomp($net, '0', 4), '⛔ বাতিল ট্রিপের ভাড়া বাহকের দেনায় রয়ে গেল।');
        $this->assertTrue(LedgerEntry::query()->where('source_type', FarePayment::TRIP_SOURCE.':reversal')->where('source_id', $trip->id)->exists(),
            'উল্টো সারি নেই — দাবি অন্ধ।');

        // ⓘ রওনার আগেই বাতিল — খাতায় কিছু বসেনি, তাই উল্টানোরও কিছু নেই; বাতিলটা নির্বিঘ্নে হয়
        $draft = $this->trip([$this->challan()], ['transport_cost' => '400', 'fare_when' => 'later', 'carrier_id' => $this->carrier->id]);
        app(ShipmentService::class)->cancel($draft, 'ট্রাক আসেনি');
        $this->assertSame(DocumentStatus::CANCELLED, $draft->fresh()->status, '⛔ রওনার আগের পরে-দেব ট্রিপ বাতিল হলো না।');
        $this->assertFalse(LedgerEntry::query()->whereIn('source_type', [FarePayment::TRIP_SOURCE, FarePayment::TRIP_SOURCE.':reversal'])
            ->where('source_id', $draft->id)->exists(), '⛔ রওনা-না-হওয়া ট্রিপের ভাড়া খাতা ছুঁল।');
    }

    public function test_one_truck_pays_one_fare(): void
    {
        $own = $this->challan();
        app(FarePayment::class)->recordOnConfirmed($own, ['fare_paid_by' => 'us', 'transport_cost' => '150', 'fare_when' => 'now', 'fare_account_id' => $this->till->id]);

        $this->refused(fn () => $this->trip([$own->fresh()], ['transport_cost' => '900', 'fare_when' => 'now', 'fare_account_id' => $this->till->id]),
            'challans', __('sales::fare.trip_challan_has_fare', ['documents' => $own->document_no]));

        // ভাড়াহীন ট্রিপে চালানের নিজের ভাড়া থাকে
        $plain = $this->trip([$own->fresh()]);
        $this->assertFalse(app(FarePayment::class)->isOurs($plain));

        // ভাড়াওয়ালা ট্রিপের চালানে পরে ভাড়া লেখা যায় না
        $onTrip = $this->challan();
        $trip = $this->trip([$onTrip], ['transport_cost' => '900', 'fare_when' => 'now', 'fare_account_id' => $this->till->id]);
        $this->refused(fn () => app(FarePayment::class)->recordOnConfirmed($onTrip->fresh(), ['fare_paid_by' => 'us', 'transport_cost' => '80',
            'fare_when' => 'now', 'fare_account_id' => $this->till->id]), 'fare', __('sales::fare.challan_on_trip', ['trip' => $trip->document_no]));

        // ⛔ খসড়ার পরে কোনো পথে চালানে ভাড়া বসলে রওনার সময় আবার ধরা পড়ে — ট্রাক বেরোয় না, কিছুই খাতায় নয়
        $onTrip->forceFill(['transport_cost' => '80', 'fare_paid_by' => 'us'])->save();
        $this->refused(fn () => app(ShipmentService::class)->dispatch($trip->fresh()),
            'challans', __('sales::fare.trip_challan_has_fare', ['documents' => $onTrip->document_no]));
        $this->assertSame(DocumentStatus::DRAFT, $trip->fresh()->status, '⛔ দুই ভাড়া নিয়ে ট্রাক রওনা হলো।');
        $this->assertNull($trip->fresh()->fare_voucher_id);
    }

    public function test_an_old_trip_without_fare_fields_books_nothing_when_it_leaves(): void
    {
        $trip = app(ShipmentService::class)->dispatch($this->trip([$this->challan()]));

        $this->assertNull($trip->fare_voucher_id);
        $this->assertFalse(LedgerEntry::query()->where('source_type', FarePayment::TRIP_SOURCE)->where('source_id', $trip->id)->exists());
    }

    // ── যন্ত্রপাতি ──

    private function challan(): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create(
            ['customer_id' => Customer::query()->value('id'), 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->value('id'), 'delivered_qty' => '1', 'rate' => '100']],
        ));
    }

    /** @param list<DeliveryChallan> $challans */
    private function trip(array $challans, array $data = []): Shipment
    {
        return app(ShipmentService::class)->create(
            ['trx_date' => now()->toDateString(), 'warehouse_id' => $this->warehouse->id, ...$data],
            array_map(fn (DeliveryChallan $c) => $c->id, $challans),
        )->fresh();
    }

    private function refused(\Closure $work, string $field, string $message): void
    {
        try {
            $work();
            $this->fail("⛔ থামার কথা ছিল: {$message}");
        } catch (ValidationException $e) {
            $this->assertSame([$message], $e->errors()[$field] ?? null, 'অন্য কারণে থামল: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }
}
