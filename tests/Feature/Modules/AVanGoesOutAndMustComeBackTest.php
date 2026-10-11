<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Services\SalesReturnService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Models\ShipmentLine;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\ShipmentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একটা গাড়ি বেরোয়, আর তাকে ফিরতে হয়।
 *
 * ── যে ফাঁকটা এই কাগজ ভরাট করে ──────────────────────────────────────
 * চালান বলে কার কাছে কী গেল। কিন্তু ডিপোর সকালটা গাড়ি ধরে চলে, আর
 * সন্ধ্যায় এক গাড়ির বারোটা চালানের হিসাব একসাথে বুঝে নিতে হয়। আজ
 * সেই হিসাবটা কোথাও নেই — বিশেষ করে **যেটা ফিরে এল** তার কোনো নাম নেই,
 * অথচ ওই মাল গুদামে ঢুকে যায় আর খাতায় ক্রেতার কাছেই থেকে যায়।
 */
class AVanGoesOutAndMustComeBackTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    private function challan(bool $confirm = true, ?Warehouse $warehouse = null, string $qty = '1'): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        $challan = $service->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'delivered_qty' => $qty, 'rate' => '100']],
        );

        return $confirm ? $service->confirm($challan) : $challan;
    }

    /**
     * @param  list<DeliveryChallan>  $challans
     */
    private function trip(array $challans, array $data = []): Shipment
    {
        return app(ShipmentService::class)->create(
            ['trx_date' => now()->toDateString(), 'warehouse_id' => $this->warehouse->id, ...$data],
            array_map(fn (DeliveryChallan $c) => $c->id, $challans),
        );
    }

    // ── লোড করা ─────────────────────────────────────────────────────

    /** গাড়িতে চালান ওঠে, আর কাগজটা নিজের নম্বর পায়। */
    public function test_a_trip_carries_its_challans(): void
    {
        $trip = $this->trip([$this->challan(), $this->challan()]);

        $this->assertCount(2, $trip->lines);
        $this->assertStringStartsWith('TRP', $trip->document_no);
        $this->assertSame(DocumentStatus::DRAFT, $trip->status);
    }

    /**
     * খসড়া চালান গাড়িতে ওঠে না।
     *
     * খসড়া মানে মাল এখনো তাকেই — স্টক নামেনি। ওটা গাড়িতে তুললে কাগজে
     * মাল যাচ্ছে অথচ গুদামের হিসাবে কিছুই কমেনি।
     */
    public function test_a_draft_challan_does_not_go_on_the_van(): void
    {
        $this->expectException(ValidationException::class);

        $this->trip([$this->challan(confirm: false)]);
    }

    /**
     * একই চালান দুই গাড়িতে যেতে পারে না।
     *
     * ── কেন এটাই সবচেয়ে সহজ ভুল ─────────────────────────────────────
     * দুইজন লোক দুইটা গাড়ি সাজান, আর একই কাগজ দুইবার টিক পড়ে যায়।
     * ধরা পড়ে সন্ধ্যায়, যখন দুই চালকই বলেন মালটা তাঁরা দিয়েছেন।
     */
    public function test_the_same_challan_cannot_ride_two_vans(): void
    {
        $challan = $this->challan();

        $this->trip([$challan]);

        $this->expectException(ValidationException::class);

        $this->trip([$challan]);
    }

    /**
     * শেষ হওয়া ট্রিপ পথ আটকায় না।
     *
     * ফেরত আসা মাল পরদিন আবার পাঠানো হয় — বাধাটা কেবল চলতি ট্রিপে।
     */
    public function test_a_finished_trip_does_not_block_tomorrow(): void
    {
        $challan = $this->challan();
        $first = $this->trip([$challan]);

        app(ShipmentService::class)->dispatch($first);
        $this->settleAll($first, ShipmentLine::DELIVERED);
        app(ShipmentService::class)->close($first->fresh());

        $second = $this->trip([$challan]);

        $this->assertCount(1, $second->lines, 'শেষ হওয়া ট্রিপটা পরদিনের পাঠানো আটকে দিয়েছে।');
    }

    /** দুই গুদামের মাল এক গাড়িতে ওঠে না। */
    public function test_one_trip_leaves_one_warehouse(): void
    {
        $other = Warehouse::query()->where('id', '<>', $this->warehouse->id)->first();

        if ($other === null) {
            $this->markTestSkipped('এই কোম্পানিতে দ্বিতীয় গুদাম নেই।');
        }

        $this->expectException(ValidationException::class);

        $this->trip([$this->challan(warehouse: $other)]);
    }

    // ── রওনা ────────────────────────────────────────────────────────

    /** খালি গাড়ি বেরোয় না। */
    public function test_an_empty_van_does_not_leave(): void
    {
        $trip = $this->trip([]);

        $this->expectException(ValidationException::class);

        app(ShipmentService::class)->dispatch($trip);
    }

    /** গাড়ি বেরোলে সময়টা লেখা থাকে। */
    public function test_leaving_is_written_down(): void
    {
        $trip = app(ShipmentService::class)->dispatch($this->trip([$this->challan()]));

        $this->assertSame(DocumentStatus::CONFIRMED, $trip->status);
        $this->assertNotNull($trip->dispatched_at);
    }

    /** গাড়ি একবারই বেরোয়। */
    public function test_a_van_leaves_once(): void
    {
        $trip = app(ShipmentService::class)->dispatch($this->trip([$this->challan()]));

        $this->expectException(ValidationException::class);

        app(ShipmentService::class)->dispatch($trip);
    }

    /**
     * বেরোনোর মুহূর্তে আবার যাচাই হয়।
     *
     * খসড়াটা ছয়টায় লেখা হতে পারে আর গাড়ি বেরোয় আটটায়। ওই দুই ঘণ্টায়
     * একটা চালান বাতিল হয়ে যেতে পারে — তখন কাগজে যা লেখা তা আর সত্যি
     * নয়, আর সেই কাগজ নিয়ে গাড়ি বেরোনো উচিত নয়।
     */
    public function test_a_challan_cancelled_before_dawn_stops_the_van(): void
    {
        $challan = $this->challan();
        $trip = $this->trip([$challan]);

        app(DeliveryChallanService::class)->cancel($challan, 'ক্রেতা বাতিল করেছেন');

        $this->expectException(ValidationException::class);

        app(ShipmentService::class)->dispatch($trip->fresh());
    }

    // ── ফিরে আসা ────────────────────────────────────────────────────

    /** কী হলো না লিখে ট্রিপ বন্ধ হয় না। */
    public function test_a_trip_does_not_close_with_a_row_unanswered(): void
    {
        $trip = app(ShipmentService::class)->dispatch($this->trip([$this->challan()]));

        $this->expectException(ValidationException::class);

        app(ShipmentService::class)->close($trip);
    }

    /** সবগুলো দিয়ে আসা হলে ট্রিপ শেষ হয়। */
    public function test_a_delivered_trip_closes(): void
    {
        $trip = app(ShipmentService::class)->dispatch($this->trip([$this->challan(), $this->challan()]));

        $this->settleAll($trip, ShipmentLine::DELIVERED);

        $closed = app(ShipmentService::class)->close($trip->fresh(), ['closing_km' => '120']);

        $this->assertSame(DocumentStatus::CLOSED, $closed->status);
        $this->assertNotNull($closed->returned_at);
    }

    /**
     * **এটাই এই কাগজের আসল কাজ।**
     *
     * মাল ফিরে এসেছে বলে লেখা হয়েছে, অথচ খাতায় সেটা এখনো ক্রেতার কাছে।
     * দুইটা একসাথে সত্যি হতে পারে না, আর ট্রিপ বন্ধ করে দিলে অমিলটা
     * চিরকালের জন্য চাপা পড়ত — মাসের শেষে কেউ একজন মজুদ মেলাতে গিয়ে
     * খুঁজে বেড়াতেন কোথায় কী হয়েছিল।
     */
    public function test_a_trip_will_not_close_while_returned_goods_are_still_out(): void
    {
        $challan = $this->challan();
        $trip = app(ShipmentService::class)->dispatch($this->trip([$challan]));

        $this->settleAll($trip, ShipmentLine::RETURNED, 'দোকান বন্ধ ছিল');

        try {
            app(ShipmentService::class)->close($trip->fresh());
            $this->fail('ফেরত আসা মাল খাতায় ক্রেতার কাছে রেখেই ট্রিপ বন্ধ হয়ে গেল।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($challan->document_no,
                implode(' ', $e->validator->errors()->all()),
                'কোন চালানটা আটকে আছে তা বার্তায় বলা হয়নি — খুঁজে বের করার দায় ব্যবহারকারীর ঘাড়ে।');
        }
    }

    /**
     * ফেরত লিখলে মাল খাতায় ফেরে, আর তখন ট্রিপ বন্ধ হয়।
     *
     * ⭐ ২ অক্টোবর ২০২৬ থেকে গেট পাসের পরে বিল বা চালান বাতিল নয় — কেবল ফেরত, মালিকের সইসহ
     * ([[AConfirmedBillCouldStillBeCancelledTest]])। ⓘ আগে এখানে দাবি ছিল "বিল আর চালান বাতিলে ট্রিপ বন্ধ"।
     */
    public function test_a_return_for_the_goods_lets_the_trip_close(): void
    {
        $challan = $this->challan();
        $trip = app(ShipmentService::class)->dispatch($this->trip([$challan]));

        $this->settleAll($trip, ShipmentLine::RETURNED, 'ক্রেতা নেননি');

        // ⓘ রওনাতেই বিল ([[DispatchBill]]) — পুরো মাল ফিরলে পুরো বিলের ফেরত
        $bill = SalesInvoice::query()->where('sale_no', $challan->fresh()->sale_no)->with('lines')->firstOrFail();
        $returns = app(SalesReturnService::class);
        $returns->confirm($returns->create([
            'customer_id' => $bill->customer_id,
            'warehouse_id' => $bill->warehouse_id,
            'sales_invoice_id' => $bill->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => now()->toDateString(),
        ], $bill->lines->map(fn ($line) => [
            'product_id' => $line->product_id,
            'sales_invoice_line_id' => $line->id,
            'qty' => (string) $line->qty,
        ])->all()));

        $closed = app(ShipmentService::class)->close($trip->fresh());

        $this->assertSame(DocumentStatus::CLOSED, $closed->status);
    }

    /**
     * ⛔ পুরো ফেরতের ট্রিপ এক পিসের ফেরতে বন্ধ হয় না — যতটা গেছে ততটা ফিরতে হয় (Sales অডিট, ১০ অক্টোবর ২০২৬)।
     *
     * ⓘ বিপজ্জনক ইনপুট: ৩টা গেল, চালক বললেন "ফেরত এসেছে", ফেরত লেখা হলো ১টার। ⚠️ আগে যেকোনো পাকা ফেরতেই
     * ট্রিপ বন্ধ হত — বাকি ২টা খাতায় ক্রেতার কাছে, গুদামে মাল। বাকি ২টা লিখলে তবে বন্ধ।
     */
    public function test_one_piece_returned_does_not_close_a_trip_whose_whole_challan_came_back(): void
    {
        $challan = $this->challan(qty: '3');
        $trip = app(ShipmentService::class)->dispatch($this->trip([$challan]));
        $this->settleAll($trip, ShipmentLine::RETURNED, 'দোকান বন্ধ ছিল');

        $this->returnFrom($challan, '1');

        try {
            app(ShipmentService::class)->close($trip->fresh());
            $this->fail('⛔ ৩টার ১টা ফেরত লিখেই "পুরো ফেরত" ট্রিপ বন্ধ হয়ে গেল।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($challan->document_no, implode(' ', $e->validator->errors()->all()));
        }

        $this->returnFrom($challan, '2');
        $this->assertSame(DocumentStatus::CLOSED, app(ShipmentService::class)->close($trip->fresh())->status,
            '⛔ পুরোটা ফেরত লেখার পরেও ট্রিপ বন্ধ হলো না।');
    }

    /** ⭐ "কম পৌঁছেছে" — যা ফিরল তার ফেরত লিখলেই বন্ধ; কতটা কম তা চালকের কথায় */
    public function test_a_short_delivery_closes_once_what_came_back_is_returned(): void
    {
        $challan = $this->challan(qty: '3');
        $trip = app(ShipmentService::class)->dispatch($this->trip([$challan]));
        $this->settleAll($trip, ShipmentLine::SHORT, 'একটা কার্টন নেননি');

        try {
            app(ShipmentService::class)->close($trip->fresh());
            $this->fail('⛔ কম পৌঁছেছে, অথচ কোনো ফেরত ছাড়াই ট্রিপ বন্ধ হলো।');
        } catch (ValidationException) {
        }

        $this->returnFrom($challan, '1');
        $this->assertSame(DocumentStatus::CLOSED, app(ShipmentService::class)->close($trip->fresh())->status);
    }

    /**
     * ⛔ পথে বাতিল হওয়া ট্রিপের গেট পাস বাতিল — গাড়ি বেরোয়নি, কাগজেও না (Sales অডিট, ১০ অক্টোবর ২০২৬)।
     *
     * ⓘ বিক্রি আর বিল থাকে (নিশ্চিত বিল বাতিল হয় না, মালিক ২ অক্টোবর ২০২৬); পরের ট্রিপে নতুন পাস, দ্বিতীয় বিল নয়।
     */
    public function test_a_trip_cancelled_on_the_road_cancels_its_gate_pass_and_the_next_trip_issues_a_new_one(): void
    {
        $challan = $this->challan();
        $first = app(ShipmentService::class)->dispatch($this->trip([$challan]));

        $pass = GatePass::query()->where('shipment_id', $first->id)->where('delivery_challan_id', $challan->id)->firstOrFail();
        $this->assertSame(GatePass::ISSUED, $pass->status, 'দৃশ্যটাই বানানো যায়নি — রওনায় গেট পাস হয়নি।');

        app(ShipmentService::class)->cancel($first->fresh(), 'গাড়ি নষ্ট');

        $this->assertSame(GatePass::CANCELLED, $pass->fresh()->status, '⛔ ট্রিপ বাতিল, অথচ গেট পাস এখনো "জারি"।');
        $this->assertSame('গাড়ি নষ্ট', $pass->fresh()->cancel_reason);

        $second = app(ShipmentService::class)->dispatch($this->trip([$challan]));
        $this->assertSame(1, GatePass::query()->where('shipment_id', $second->id)->where('status', GatePass::ISSUED)->count(),
            '⛔ পরের ট্রিপে নতুন গেট পাস হয়নি।');
        $this->assertSame(1, SalesInvoice::query()->where('sale_no', $challan->fresh()->sale_no)->count(),
            '⛔ দ্বিতীয় রওনায় একই বিক্রির দ্বিতীয় বিল।');
    }

    /** ফেরত লিখতে গেলে কারণটাও লিখতে হয়। */
    public function test_goods_coming_back_need_a_reason(): void
    {
        $trip = app(ShipmentService::class)->dispatch($this->trip([$this->challan()]));

        $this->expectException(ValidationException::class);

        app(ShipmentService::class)->settle($trip->lines->first(), ShipmentLine::RETURNED);
    }

    /** গাড়ি বেরোনোর আগে হিসাব বুঝে নেওয়ার কিছু নেই। */
    public function test_nothing_settles_before_the_van_leaves(): void
    {
        $trip = $this->trip([$this->challan()]);

        $this->expectException(ValidationException::class);

        app(ShipmentService::class)->settle($trip->lines->first(), ShipmentLine::DELIVERED);
    }

    /** মিটার পিছিয়ে যেতে পারে না। */
    public function test_the_meter_does_not_run_backwards(): void
    {
        $trip = app(ShipmentService::class)->dispatch(
            $this->trip([$this->challan()], ['opening_km' => '500'])
        );

        $this->settleAll($trip, ShipmentLine::DELIVERED);

        $this->expectException(ValidationException::class);

        app(ShipmentService::class)->close($trip->fresh(), ['closing_km' => '400']);
    }

    // ── বাতিল ───────────────────────────────────────────────────────

    /** ট্রিপ বাতিল হলে চালানগুলো আবার মুক্ত। */
    public function test_cancelling_a_trip_frees_its_challans(): void
    {
        $challan = $this->challan();
        $trip = $this->trip([$challan]);

        app(ShipmentService::class)->cancel($trip, 'গাড়ি বিগড়েছে');

        $again = $this->trip([$challan]);

        $this->assertCount(1, $again->lines, 'বাতিল ট্রিপের চালান এখনো আটকে আছে।');
    }

    /** শেষ হওয়া ট্রিপ বাতিল হয় না। */
    public function test_a_finished_trip_does_not_cancel(): void
    {
        $trip = app(ShipmentService::class)->dispatch($this->trip([$this->challan()]));
        $this->settleAll($trip, ShipmentLine::DELIVERED);
        $closed = app(ShipmentService::class)->close($trip->fresh());

        $this->expectException(ValidationException::class);

        app(ShipmentService::class)->cancel($closed, 'ভুল হয়েছিল');
    }

    /**
     * ট্রিপ স্টকে হাত দেয় না।
     *
     * ── কেন এটা পরখ করা দরকার ───────────────────────────────────────
     * মাল আগেই চালানে বেরিয়েছে। ট্রিপ আবার বের করলে দুইবার বেরোত, আর
     * সেটা কেউ টের পেত না — গুদামের সংখ্যা কেবল কম দেখাত।
     */
    public function test_a_trip_moves_no_stock(): void
    {
        $challan = $this->challan();

        $before = StockMovement::query()->count();

        $trip = app(ShipmentService::class)->dispatch($this->trip([$challan]));
        $this->settleAll($trip, ShipmentLine::DELIVERED);
        app(ShipmentService::class)->close($trip->fresh());

        $this->assertSame($before, StockMovement::query()->count(),
            'ট্রিপ স্টকের সারি বানিয়েছে — মাল দুইবার নড়ল।');
    }

    // ── পর্দা ───────────────────────────────────────────────────────

    /** তালিকার পর্দা খোলে ও ট্রিপটা দেখায়। */
    public function test_the_list_screen_shows_the_trip(): void
    {
        $trip = $this->trip([$this->challan()]);

        $this->get(route('sales.shipment.index'))
            ->assertOk()
            ->assertSee($trip->document_no);
    }

    /** ট্রিপের পর্দা তার চালানগুলো দেখায়। */
    public function test_the_trip_screen_shows_its_challans(): void
    {
        $challan = $this->challan();
        $trip = $this->trip([$challan]);

        $this->get(route('sales.shipment.show', $trip))
            ->assertOk()
            ->assertSee($challan->document_no);
    }

    /** পর্দা থেকেই গাড়ি বেরোয়। */
    public function test_the_van_leaves_from_the_screen(): void
    {
        $trip = $this->trip([$this->challan()]);

        $this->post(route('sales.shipment.dispatch', $trip))
            ->assertRedirect(route('sales.shipment.show', $trip));

        $this->assertSame(DocumentStatus::CONFIRMED, $trip->fresh()->status);
    }

    /**
     * অন্য ট্রিপের সারিতে হাত দেওয়া যায় না।
     *
     * ঠিকানায় দুইটা সংখ্যা থাকলে কেউ একটা বদলে দেখতে পারেন কী হয় —
     * আর তখন এক ট্রিপের পর্দা থেকে অন্য ট্রিপের হিসাব লেখা হয়ে যেত।
     */
    public function test_a_row_belonging_to_another_trip_is_refused(): void
    {
        $mine = app(ShipmentService::class)->dispatch($this->trip([$this->challan()]));
        $theirs = app(ShipmentService::class)->dispatch($this->trip([$this->challan()]));

        $this->post(route('sales.shipment.settle', [$mine, $theirs->lines->first()]), [
            'outcome' => ShipmentLine::DELIVERED,
        ])->assertNotFound();

        $this->assertSame(ShipmentLine::PENDING, $theirs->lines->first()->fresh()->outcome);
    }

    /** এই চালানের বিল থেকে এতগুলো ফেরত — পাকা */
    private function returnFrom(DeliveryChallan $challan, string $qty): void
    {
        $bill = SalesInvoice::query()->where('sale_no', $challan->fresh()->sale_no)->with('lines')->firstOrFail();
        $returns = app(SalesReturnService::class);
        $returns->confirm($returns->create([
            'customer_id' => $bill->customer_id,
            'warehouse_id' => $bill->warehouse_id,
            'sales_invoice_id' => $bill->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => now()->toDateString(),
        ], $bill->lines->map(fn ($line) => [
            'product_id' => $line->product_id,
            'sales_invoice_line_id' => $line->id,
            'qty' => $qty,
        ])->all()));
    }

    private function settleAll(Shipment $trip, string $outcome, ?string $note = null): void
    {
        foreach ($trip->fresh('lines')->lines as $line) {
            app(ShipmentService::class)->settle($line, $outcome, $note);
        }
    }
}
