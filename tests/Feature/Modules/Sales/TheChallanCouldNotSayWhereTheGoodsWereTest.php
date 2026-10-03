<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryEvent;
use App\Modules\Sales\Models\DeliveryEventLine;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\ShipmentLine;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\ShipmentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * চালান বলতে পারত না মালটা এখন কোথায় — ডেলিভারির ধাপ (NEXUS §২১–২২)।
 *
 * ── ⓘ কী মাপা হয় ────────────────────────────────────────────────────
 *   • বৈধ পথ ধাপে ধাপে বসে, আর প্রতিটা ধাপের ইতিহাস-সারি থাকে।
 *   • অবৈধ লাফ আটকায় — আর আটকালে কিছুই লেখা হয় না।
 *   • "পৌঁছায়নি" কারণ চায়; "পৌঁছেছে" চায় কে নিলেন; "আংশিক" চায় পরিমাণ।
 *   • ট্রিপ ধাপ চালায়; চলতি ট্রিপে হাতে বসানো যায় না।
 *   • ⛔ কোনো ধাপ স্টক নাড়ায় না।
 *   • দরজা — একই মানুষ, চাবি ছাড়া ৪০৩, চাবি দিলে খোলে; অন্য কোম্পানির
 *     চালান ৪০৪।
 *
 * ⚠️ এই দাবিগুলো দাঁড়ায় [[TellsTheDeliveryStage]] তিনটা মডেলে বসানোর উপর —
 * না বসালে চালান নিশ্চিত হলেও ধাপ "অপেক্ষায়" থাকত, আর প্রথম দাবিটাই লাল।
 */
final class TheChallanCouldNotSayWhereTheGoodsWereTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private User $owner;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /* ⭐ ভূমিকাহীন — চাবি দাবির ভিতরে দেওয়া হয় ([[same-user-key-off-then-on]]) */
        $this->outsider = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->outsider->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($this->owner);
    }

    // ── নিয়মের পথ ─────────────────────────────────────────────────────

    /** ⭐ বৈধ পথ — অপেক্ষায় থেকে পৌঁছেছে, প্রতিটা ধাপ ইতিহাসে। */
    public function test_a_challan_walks_the_legal_path_and_every_step_is_written(): void
    {
        $challan = $this->draftChallan();

        $this->assertSame(DeliveryStage::PENDING, $this->stageOf($challan),
            '⛔ নতুন চালানের ধাপ "অপেক্ষায়" নয় — ট্রেইটটা চালানে বসানো আছে তো?');

        app(DeliveryChallanService::class)->confirm($challan);

        $this->assertSame(DeliveryStage::ALLOCATED, $this->stageOf($challan),
            '⛔ চালান নিশ্চিত হলো, অথচ ধাপ "বরাদ্দ" হয়নি।');

        $stages = app(DeliveryStageService::class);
        $stages->move($challan, DeliveryStage::PICKING);
        $stages->move($challan, DeliveryStage::PACKED);
        $stages->move($challan, DeliveryStage::DISPATCHED, ['note' => 'কুরিয়ারে']);
        $stages->move($challan, DeliveryStage::DELIVERED, [
            'receiver_name' => 'রহিম দোকানদার', 'receiver_phone' => '+8801711000000',
        ]);

        $this->assertSame(DeliveryStage::DELIVERED, $this->stageOf($challan));

        $events = DeliveryEvent::query()->where('delivery_challan_id', $challan->id)->orderBy('id')->get();

        $this->assertSame(
            [null, DeliveryStage::PENDING, DeliveryStage::ALLOCATED, DeliveryStage::PICKING, DeliveryStage::PACKED, DeliveryStage::DISPATCHED],
            $events->pluck('from_stage')->all(),
            '⛔ ইতিহাসের "থেকে" শিকলটা ভাঙা — একটা ধাপ বাদ পড়েছে বা দুইবার বসেছে।',
        );

        $last = $events->last();
        $this->assertSame('রহিম দোকানদার', $last->receiver_name);
        $this->assertSame('+8801711000000', $last->receiver_phone);
        $this->assertSame((int) $this->owner->id, (int) $last->created_by, '⛔ কে বসালেন তা লেখা নেই।');
        $this->assertSame(DeliveryStage::BY_HAND, $last->source);
    }

    /**
     * ⛔ অবৈধ লাফ — বরাদ্দ থেকে সরাসরি "আংশিক" নয়; আর কিছুই লেখা হয় না।
     *
     * ⓘ ২৮ সেপ্টেম্বর ২০২৬ পর্যন্ত এখানে "পৌঁছেছে" মাপা হত। মালিকের পরিকল্পনার
     * ধাপ ৪ ঠিক ওই পথটাই খুলেছে (ডিপো থেকে হাতে দেওয়া —
     * [[TheChallanWaitedWithNoWayToSayItArrivedTest]]), তাই লাফটা এখন "আংশিক":
     * কোন সারির কতটা গেল, সেটা গাড়ি থেকে ফেরার পরের কথা।
     */
    public function test_an_illegal_jump_is_refused_and_nothing_is_written(): void
    {
        $challan = $this->confirmedChallan();
        $before = DeliveryEvent::query()->where('delivery_challan_id', $challan->id)->count();

        $this->assertRefused(fn () => app(DeliveryStageService::class)->move($challan, DeliveryStage::PARTIALLY_DELIVERED, [
            'receiver_name' => 'কেউ একজন',
        ]), 'stage');

        $this->assertSame(DeliveryStage::ALLOCATED, $this->stageOf($challan));
        $this->assertSame($before, DeliveryEvent::query()->where('delivery_challan_id', $challan->id)->count(),
            '⛔ ধাপ আটকেছে, তবু ইতিহাসে একটা সারি বসে গেছে।');

        // খসড়া চালানের মাল তাকেই — হাতে "তোলা হচ্ছে" নয়
        $draft = $this->draftChallan();
        $this->assertRefused(fn () => app(DeliveryStageService::class)->move($draft, DeliveryStage::PICKING), 'stage');

        // বাতিল শেষ ধাপ — আবার রওনা নয়
        app(DeliveryChallanService::class)->cancel($challan, 'ভুল চালান');
        $this->assertSame(DeliveryStage::CANCELLED, $this->stageOf($challan),
            '⛔ চালান বাতিল হলো, অথচ ধাপ "বাতিল" হয়নি।');
        $this->assertRefused(fn () => app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED), 'stage');

        // ⚠️ তালিকায় নেই এমন নাম
        $this->assertRefused(fn () => app(DeliveryStageService::class)->move($draft, 'lost_in_transit'), 'stage');
    }

    /** ⛔ "পৌঁছায়নি" কারণ চায় — ফাঁকা স্পেস কারণ নয়, অন্য কাজের কারণও নয়। */
    public function test_a_failed_delivery_needs_a_reason(): void
    {
        $challan = $this->dispatchedByHand();
        $stages = app(DeliveryStageService::class);

        $this->assertRefused(fn () => $stages->move($challan, DeliveryStage::FAILED), 'note');
        $this->assertRefused(fn () => $stages->move($challan, DeliveryStage::FAILED, ['note' => "   \t "]), 'note');

        // ⚠️ ছাড়ের কারণ দিয়ে "পৌঁছায়নি" নয় — প্রসঙ্গ মাপা হয়
        $discount = $this->reasonCode('DISC-X', ReasonCode::DISCOUNT);
        $this->assertRefused(fn () => $stages->move($challan, DeliveryStage::FAILED, [
            'reason_code_id' => $discount->id,
        ]), 'reason_code_id');

        $this->assertSame(DeliveryStage::DISPATCHED, $this->stageOf($challan));

        $stages->move($challan, DeliveryStage::FAILED, ['note' => 'দোকান বন্ধ ছিল']);

        $this->assertSame(DeliveryStage::FAILED, $this->stageOf($challan));
        $this->assertSame('দোকান বন্ধ ছিল', DeliveryEvent::query()
            ->where('delivery_challan_id', $challan->id)->orderByDesc('id')->value('note'));
    }

    /** ⭐ তালিকা থেকে বাছা কারণই যথেষ্ট — লেখা ছাড়াই। */
    public function test_a_reason_from_the_list_is_enough(): void
    {
        $challan = $this->dispatchedByHand();
        $shut = $this->reasonCode('SHOP-SHUT', ReasonCode::SALES_RETURN);

        app(DeliveryStageService::class)->move($challan, DeliveryStage::FAILED, ['reason_code_id' => $shut->id]);

        $this->assertSame($shut->id, (int) DeliveryEvent::query()
            ->where('delivery_challan_id', $challan->id)->orderByDesc('id')->value('reason_code_id'));
    }

    /** ⛔ "পৌঁছেছে" মানে কেউ বুঝে নিয়েছেন — নাম ছাড়া নয়, ভুল ফোনও নয়। */
    public function test_delivered_needs_the_receivers_name(): void
    {
        $challan = $this->dispatchedByHand();
        $stages = app(DeliveryStageService::class);

        $this->assertRefused(fn () => $stages->move($challan, DeliveryStage::DELIVERED), 'receiver_name');
        $this->assertRefused(fn () => $stages->move($challan, DeliveryStage::DELIVERED, ['receiver_name' => '  ']), 'receiver_name');
        $this->assertRefused(fn () => $stages->move($challan, DeliveryStage::DELIVERED, [
            'receiver_name' => 'করিম', 'receiver_phone' => '<script>',
        ]), 'receiver_phone');

        $this->assertSame(DeliveryStage::DISPATCHED, $this->stageOf($challan));
    }

    /** ⭐ আংশিক — কোন সারির কতটা গেল, সারি ধরে লেখা। */
    public function test_a_partial_delivery_records_what_each_line_delivered(): void
    {
        $challan = $this->dispatchedByHand(twoLines: true);
        [$first, $second] = $challan->lines()->orderBy('line_no')->get()->all();

        app(DeliveryStageService::class)->move($challan, DeliveryStage::PARTIALLY_DELIVERED, [
            'receiver_name' => 'রহিম',
            'lines' => [$first->id => '2', $second->id => '3'],
        ]);

        $this->assertSame(DeliveryStage::PARTIALLY_DELIVERED, $this->stageOf($challan));

        $event = DeliveryEvent::query()->where('delivery_challan_id', $challan->id)->orderByDesc('id')->firstOrFail();
        $qty = DeliveryEventLine::query()->where('delivery_event_id', $event->id)
            ->pluck('delivered_qty', 'delivery_challan_line_id');

        $this->assertSame('2.0000', (string) $qty[$first->id]);
        $this->assertSame('3.0000', (string) $qty[$second->id]);
    }

    /** ⛔ আংশিকের পরিমাণের পাঁচটা ফাঁদ — প্রতিটা আটকায়, কিছুই লেখা হয় না। */
    public function test_partial_quantities_are_guarded(): void
    {
        $challan = $this->dispatchedByHand(twoLines: true);
        [$first, $second] = $challan->lines()->orderBy('line_no')->get()->all();
        $stranger = $this->confirmedChallan()->lines()->firstOrFail();
        $stages = app(DeliveryStageService::class);
        $base = ['receiver_name' => 'রহিম'];

        $cases = [
            'চালানের চেয়ে বেশি' => [$first->id => '6', $second->id => '0'],
            'ঋণাত্মক' => [$first->id => '-1', $second->id => '1'],
            'সংখ্যা নয়' => [$first->id => '1e3', $second->id => '1'],
            'অন্য চালানের সারি' => [$first->id => '1', $stranger->id => '1'],
            'সব পুরো — ওটা আংশিক নয়' => [$first->id => '5', $second->id => '3'],
            'সব শূন্য — ওটা পৌঁছায়নি' => [$first->id => '0', $second->id => '0'],
        ];

        foreach ($cases as $why => $lines) {
            $this->assertRefused(fn () => $stages->move($challan, DeliveryStage::PARTIALLY_DELIVERED, [
                ...$base, 'lines' => $lines,
            ]), 'lines', "⛔ আংশিক ডেলিভারি গৃহীত হলো: {$why}।");
        }

        $this->assertSame(DeliveryStage::DISPATCHED, $this->stageOf($challan));
        $this->assertSame(0, DeliveryEventLine::query()->count(), '⛔ আটকানো পরিমাণের সারি বসে গেছে।');
    }

    /** ⛔ ধাপ বদলানো কেবল বলা — একটাও স্টক চলাচল নয়। */
    public function test_no_stage_change_moves_any_stock(): void
    {
        $challan = $this->confirmedChallan(twoLines: true);
        [$first] = $challan->lines()->orderBy('line_no')->get()->all();

        /* ⓘ ২ অক্টোবর ২০২৬ থেকে আংশিক পৌঁছানো না-নেওয়া মালের ফেরত বানায় ([[ShortDeliveryReturn]]) — সেই ফেরতের
           নিজের চলাচল বাদে; ধাপ বদলানো নিজে একটাও চলাচল নয় */
        $byStage = fn () => StockMovement::query()->where('source_type', 'not like', \App\Modules\Sales\Models\SalesReturn::STOCK_SOURCE.'%');
        $movements = $byStage()->count();
        $floor = (string) $byStage()->sum('floor_change');

        $stages = app(DeliveryStageService::class);
        $stages->move($challan, DeliveryStage::PICKING);
        $stages->move($challan, DeliveryStage::PACKED);
        $stages->move($challan, DeliveryStage::DISPATCHED);
        $stages->move($challan, DeliveryStage::PARTIALLY_DELIVERED, [
            'receiver_name' => 'রহিম', 'lines' => [$first->id => '1'],
        ]);

        $this->assertSame($movements, $byStage()->count(),
            '⛔ ধাপ বদলাতে গিয়ে স্টক চলাচল বসেছে — মাল দুইবার নামত বা উঠত।');
        $this->assertSame(0, bccomp($floor, (string) $byStage()->sum('floor_change'), 4));
    }

    // ── ট্রিপ ──────────────────────────────────────────────────────────

    /** ⭐ ট্রিপ ধাপ চালায় — আর চলতি ট্রিপে হাতে বসানো যায় না। */
    public function test_the_trip_drives_the_stage_and_owns_it_while_on_the_road(): void
    {
        $challan = $this->confirmedChallan();
        $trips = app(ShipmentService::class);
        $trip = $trips->dispatch($trips->create(
            ['trx_date' => now()->toDateString(), 'warehouse_id' => $this->warehouse->id], [$challan->id],
        ));

        $this->assertSame(DeliveryStage::DISPATCHED, $this->stageOf($challan), '⛔ গাড়ি বেরোল, ধাপ "রওনা" হয়নি।');
        $this->assertSame((int) $trip->id, (int) DeliveryEvent::query()
            ->where('delivery_challan_id', $challan->id)->orderByDesc('id')->value('shipment_id'));

        // ⛔ ট্রিপ পথে — হাতে "পৌঁছেছে" নয়
        $this->assertRefused(fn () => app(DeliveryStageService::class)->move($challan, DeliveryStage::DELIVERED, [
            'receiver_name' => 'রহিম',
        ]), 'stage');

        $line = ShipmentLine::query()->where('shipment_id', $trip->id)->firstOrFail();

        $trips->settle($line, ShipmentLine::RETURNED, 'ক্রেতা নেননি');
        $this->assertSame(DeliveryStage::FAILED, $this->stageOf($challan));

        // চালক কথা শোধরালেন — ট্রিপ তখনো খোলা
        $trips->settle($line->fresh(), ShipmentLine::DELIVERED);
        $this->assertSame(DeliveryStage::DELIVERED, $this->stageOf($challan));
    }

    /** ⭐ পথে থাকা ট্রিপ বাতিল — মাল গাড়িতে ওঠার আগের ধাপে ফেরে। */
    public function test_a_trip_cancelled_on_the_road_puts_the_stage_back(): void
    {
        $challan = $this->confirmedChallan();
        app(DeliveryStageService::class)->move($challan, DeliveryStage::PACKED);

        $trips = app(ShipmentService::class);
        $trip = $trips->dispatch($trips->create(
            ['trx_date' => now()->toDateString(), 'warehouse_id' => $this->warehouse->id], [$challan->id],
        ));
        $this->assertSame(DeliveryStage::DISPATCHED, $this->stageOf($challan));

        $trips->cancel($trip, 'গাড়ি নষ্ট');

        $this->assertSame(DeliveryStage::PACKED, $this->stageOf($challan),
            '⛔ ট্রিপ বাতিল হলো, অথচ চালান এখনো "রওনা" দেখায়।');
    }

    /** ⛔ হাতে "পৌঁছেছে" লেখা চালান গাড়িতে ওঠে না — গোটা বেরোনোটাই ফেরে। */
    public function test_a_delivered_challan_cannot_leave_on_a_van(): void
    {
        $challan = $this->dispatchedByHand();
        app(DeliveryStageService::class)->move($challan, DeliveryStage::DELIVERED, ['receiver_name' => 'রহিম']);

        $trips = app(ShipmentService::class);
        $trip = $trips->create(['trx_date' => now()->toDateString(), 'warehouse_id' => $this->warehouse->id], [$challan->id]);

        $this->assertRefused(fn () => $trips->dispatch($trip), 'lines');

        $this->assertSame(DocumentStatus::DRAFT, $trip->fresh()->status, '⛔ বেরোনো আটকেছে, তবু ট্রিপটা বেরিয়ে গেছে।');
        $this->assertSame(DeliveryStage::DELIVERED, $this->stageOf($challan));
    }

    // ── দরজা ───────────────────────────────────────────────────────────

    /** ⛔→⭐ একই মানুষ: চাবি ছাড়া ৪০৩ ও কিছুই বদলায় না; চাবি দিলে ধাপ বসে। */
    public function test_the_update_key_is_what_opens_the_stage_door(): void
    {
        $challan = $this->confirmedChallan();

        $this->outsider->givePermissionTo('sales.delivery.view');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.delivery.move', $challan), ['stage' => DeliveryStage::PICKING])
            ->assertForbidden();

        $this->assertSame(DeliveryStage::ALLOCATED, $this->stageOf($challan),
            '⛔ ৪০৩ ফিরেছে, তবু ধাপটা বসে গেছে।');

        $this->outsider->givePermissionTo('sales.delivery.update');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.delivery.move', $challan), ['stage' => DeliveryStage::PICKING])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(DeliveryStage::PICKING, $this->stageOf($challan),
            '⛔ দরজা খুলেছে, কিন্তু ধাপ বসেনি — ৩০২ এসেছে, কাজ হয়নি।');
    }

    /** ⛔→⭐ একই মানুষ: দেখার চাবি ছাড়া তালিকা ও পাতা বন্ধ; চাবি দিলে চালানটা দেখা যায়। */
    public function test_the_view_key_is_what_opens_the_list_and_the_page(): void
    {
        $challan = $this->confirmedChallan();

        $this->actingAs($this->outsider)->get(route('sales.delivery.index'))->assertForbidden();
        $this->actingAs($this->outsider)->get(route('sales.delivery.show', $challan))->assertForbidden();

        $this->outsider->givePermissionTo('sales.delivery.view');
        $viewer = $this->outsider->fresh();

        // ⚠️ সারি আছে বলেই রেন্ডার-ক্লোজারগুলো সত্যিই চলে ([[a-render-closure-never-runs-on-an-empty-list]])
        $this->actingAs($viewer)->get(route('sales.delivery.index', ['stage' => DeliveryStage::ALLOCATED]))
            ->assertOk()
            ->assertSee($challan->document_no);

        $this->actingAs($viewer)->get(route('sales.delivery.show', $challan))
            ->assertOk()
            ->assertSee(__('sales::delivery.timeline.title'))
            // দেখার চাবিতে ধাপ বসানোর ঘর নেই
            ->assertDontSee(route('sales.delivery.move', $challan));
    }

    /** ⭐ চালানের পাতায় বসানোর ঘরটাই ঠিক জায়গায় পোস্ট করে — আর পোস্টটা কাজ করে। */
    public function test_the_page_offers_the_legal_next_stages_and_they_work(): void
    {
        $challan = $this->confirmedChallan();

        $this->outsider->givePermissionTo(['sales.delivery.view', 'sales.delivery.update']);
        $worker = $this->outsider->fresh();

        $this->actingAs($worker)->get(route('sales.delivery.show', $challan))
            ->assertOk()
            ->assertSee(route('sales.delivery.move', $challan))
            ->assertSee(__('sales::delivery.action.to', ['stage' => DeliveryStage::label(DeliveryStage::PICKING)]))
            // ⭐ বরাদ্দ থেকে "পৌঁছেছে" নিজের বড় বোতামে (ধাপ ৪), ছোট তালিকায় দ্বিতীয়বার নয়
            ->assertSee(__('sales::delivery.action.confirm'))
            ->assertDontSee(__('sales::delivery.action.to', ['stage' => DeliveryStage::label(DeliveryStage::DELIVERED)]))
            // "আংশিক" বরাদ্দ থেকে নয় — সেবাও না বলত
            ->assertDontSee(__('sales::delivery.action.to', ['stage' => DeliveryStage::label(DeliveryStage::PARTIALLY_DELIVERED)]));
    }

    /**
     * ⛔→⭐ চালানের নিজের পাতায় সময়রেখা — একই মানুষ, দেখার চাবি ছাড়া নেই, চাবিতে আছে।
     *
     * ⚠️ ওয়্যারিং-নির্ভর: challan/show.blade.php-তে partial-টা বসানো না থাকলে
     * দ্বিতীয় অংশ লাল — ঠিক যেমন হওয়া উচিত।
     */
    public function test_the_challan_page_shows_the_timeline_only_with_the_view_key(): void
    {
        $challan = $this->confirmedChallan();

        $this->outsider->givePermissionTo('sales.challan.view');
        $reader = $this->outsider->fresh();

        $this->actingAs($reader)->get(route('sales.challan.show', $challan))
            ->assertOk()
            ->assertDontSee('id="delivery-stages"', false);

        $this->outsider->givePermissionTo('sales.delivery.view');

        $this->actingAs($this->outsider->fresh())->get(route('sales.challan.show', $challan))
            ->assertOk()
            ->assertSee('id="delivery-stages"', false)
            ->assertSee(__('sales::delivery.timeline.title'))
            ->assertSee(DeliveryStage::label(DeliveryStage::ALLOCATED));
    }

    /** ⛔ অন্য কোম্পানির চালান — তালিকায় নেই, পাতা ৪০৪, ধাপ বসে না। */
    public function test_another_companys_challan_is_invisible(): void
    {
        $challan = $this->confirmedChallan();

        $fmart = Company::query()->where('code', 'FMART')->firstOrFail();
        $stranger = User::factory()->create(['current_company_id' => $fmart->id]);
        $stranger->companies()->attach($fmart->id, ['is_active' => true]);
        // ⚠️ চাবি মার্টের দলে — ডিপোর প্রসঙ্গে দিলে ৪০৩ আসত, আর ৪০৪-এর দাবি কিছুই মাপত না
        CompanyContext::forCompany($fmart->id,
            fn () => $stranger->givePermissionTo(['sales.delivery.view', 'sales.delivery.update']));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $stranger = $stranger->fresh();

        $this->actingAs($stranger)->get(route('sales.delivery.index', ['stage' => DeliveryStage::ALLOCATED]))
            ->assertOk()
            ->assertDontSee($challan->document_no);

        $this->actingAs($stranger)->get(route('sales.delivery.show', $challan))->assertNotFound();

        $this->actingAs($stranger)
            ->post(route('sales.delivery.move', $challan), ['stage' => DeliveryStage::PICKING])
            ->assertNotFound();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->assertSame(DeliveryStage::ALLOCATED, $this->stageOf($challan),
            '⛔ অন্য কোম্পানির লোক এই চালানের ধাপ বদলে দিয়েছেন।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function stageOf(DeliveryChallan $challan): ?string
    {
        // ⓘ কোম্পানির ছাঁকনি ছাড়া — অন্য কোম্পানির অনুরোধের পরে প্রসঙ্গ বদলে থাকতে পারে
        return DeliveryState::acrossAllCompanies()
            ->where('delivery_challan_id', $challan->id)
            ->value('stage');
    }

    private function assertRefused(callable $action, string $field, string $message = ''): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(),
                $message ?: "⛔ আটকেছে, কিন্তু অন্য ঘরে: ".implode(', ', array_keys($e->errors())));

            return;
        }

        $this->fail($message ?: "⛔ আটকানোর কথা ছিল ({$field}), কিন্তু গৃহীত হলো।");
    }

    /** খসড়া চালান — বিস্কুট ৫টা (আর চাইলে চা ৩টা), ডেমোর গুদামে যথেষ্ট আছে। */
    private function draftChallan(bool $twoLines = false): DeliveryChallan
    {
        $biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $lines = [['product_id' => $biscuit->id, 'delivered_qty' => '5', 'rate' => '10']];

        if ($twoLines) {
            $tea = Product::query()->where('name_en', 'Premium Tea 250gm')->firstOrFail();
            $lines[] = ['product_id' => $tea->id, 'delivered_qty' => '3', 'rate' => '165'];
        }

        return app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], $lines);
    }

    private function confirmedChallan(bool $twoLines = false): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->confirm($this->draftChallan($twoLines));
    }

    /** হাতে রওনা — কুরিয়ারে পাঠানো, কোনো ট্রিপ ছাড়া। */
    private function dispatchedByHand(bool $twoLines = false): DeliveryChallan
    {
        $challan = $this->confirmedChallan($twoLines);
        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);

        return $challan;
    }

    private function reasonCode(string $code, string $context): ReasonCode
    {
        return ReasonCode::query()->create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name_en' => $code,
            'name_bn' => $code,
            'context' => $context,
            'is_active' => true,
        ]);
    }
}
