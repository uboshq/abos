<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryEvent;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ একটা QR, দুই রকম মানুষ — মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * *"ekta qr add korbe zate mobile scane korei delivery dap gulo complate r porer daper nirdes
 * dite pare"* আর *"ekoi code dilar scane kore tar hisab r invoice dekte pare r confm korte pare"*।
 *
 * ── কী মাপা হয় ─────────────────────────────────────────────────────────
 * • তিন দরজা: কেউ না → লগইন বাছাই (চালানের কিছুই না); কর্মী → কর্মীর পাতা; ডিলার → ডিলারের পাতা।
 * • কর্মীর চাবি: একই মানুষ, চাবি ছাড়া ৪০৩, চাবিতে ২০০; অন্য কোম্পানির কাগজ ৪০৪; অচেনা আকার ৪০৪।
 * • ⛔ GET কিছুই বদলায় না; ধাপ বদলায় কেবল পুরনো POST-এ — আর রওনায় বিল ও গেট পাস সেই পথেই।
 * • ডিলার: নিজের চালান দেখেন (বিল আর বকেয়াসহ), অন্যেরটা ৪০৪; "মাল বুঝে পেয়েছি" একবারই বসে,
 *   প্রাপক তিনি, নোটে তাঁর কোড, অডিটে ওঠে; রওনার আগে নিশ্চিত করা যায় না।
 * • লগইনের পরে QR-এর পাতাতেই ফেরা — কেবল নিজের সাইটের স্ক্যান/পোর্টালের ঠিকানায়।
 */
final class OneQrForTheDepotAndTheDealerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->customer = Customer::query()->firstOrFail();
        // ⛔ ১ অক্টোবর ২০২৬ থেকে শূন্য সীমা মানে বাকি নেই (মালিকের চূড়ান্ত কথা) — তাই এই পরীক্ষার গ্রাহকের সত্যিকারের বড় সীমা
        $this->customer->forceFill(['credit_limit' => '1000000000', 'portal_enabled' => true, 'portal_password' => 'dealer-pass-1'])->save();
    }

    // ── তিন দরজা ──────────────────────────────────────────────────────

    /** ⛔ কেউ ঢোকা নেই — দুই লগইনের বাছাই, আর চালানের নম্বরও দেখা যায় না */
    public function test_a_stranger_sees_only_the_two_sign_ins_and_nothing_of_the_paper(): void
    {
        $challan = $this->aConfirmedChallan();
        auth()->logout();

        $page = $this->get(route('sales.scan', $challan->public_id))->assertOk();

        $page->assertSee('data-staff-login', false)->assertSee('data-dealer-login', false);
        $page->assertDontSee((string) $challan->document_no);
        $page->assertDontSee((string) $this->customer->name());
        $this->assertSame(route('sales.scan', $challan->public_id), session('url.intended'));
    }

    public function test_staff_are_sent_to_the_staff_page(): void
    {
        $challan = $this->aConfirmedChallan();

        $this->get(route('sales.scan', $challan->public_id))
            ->assertRedirect(route('sales.delivery.scan', $challan->public_id));
    }

    public function test_a_dealer_is_sent_to_the_dealer_page(): void
    {
        $challan = $this->aConfirmedChallan();
        auth()->logout();

        $this->actingAs($this->customer->fresh(), 'portal')
            ->get(route('sales.scan', $challan->public_id))
            ->assertRedirect(route('sales.portal.scan', $challan->public_id));
    }

    /** ⓘ publicId কেবল UUID-র আকারে — ক্রমিক আইডি বা অন্য কিছু দিলে রুটই মেলে না */
    public function test_an_id_that_is_not_a_uuid_is_404(): void
    {
        $challan = $this->aConfirmedChallan();

        foreach ([(string) $challan->id, 'S-0001', str_repeat('z', 36)] as $bad) {
            $this->get('/scan/'.$bad)->assertNotFound();
        }
    }

    // ── কর্মী ─────────────────────────────────────────────────────────

    /** ⛔ একই মানুষ — চাবি ছাড়া ৪০৩, চাবি দিলে ২০০ ([[a-door-claim-needs-one-actor-twice]]) */
    public function test_the_same_staff_member_is_403_without_the_key_and_200_with_it(): void
    {
        $challan = $this->aConfirmedChallan();

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($clerk)->get(route('sales.delivery.scan', $challan->public_id))->assertForbidden();

        $clerk->givePermissionTo('sales.delivery.view');

        $this->actingAs($clerk->fresh())->get(route('sales.delivery.scan', $challan->public_id))
            ->assertOk()
            ->assertSee((string) $challan->document_no);
    }

    /** ⛔ অন্য কোম্পানির কর্মী এই কাগজের QR পেলেও ৪০৪ — থাকার খবরও নয় */
    public function test_another_companys_staff_get_404(): void
    {
        $challan = $this->aConfirmedChallan();
        $other = Company::query()->where('code', 'FMART')->firstOrFail();

        $stranger = User::factory()->create(['current_company_id' => $other->id]);
        $stranger->companies()->attach($other->id, ['is_active' => true]);

        /* ⓘ চাবি নিজের কোম্পানিতেই — চাবি আছে বলেই ৪০৩ নয়, কাগজটা তাঁর চোখে নেই বলে ৪০৪ */
        CompanyContext::set($other->id, $other->defaultBranch()?->id);
        $stranger->givePermissionTo('sales.delivery.view');
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->actingAs($stranger)->get(route('sales.delivery.scan', $challan->public_id))->assertNotFound();
    }

    /** ⛔ খোলা মানেই বদল নয় — পাতা খুললে কোনো ঘটনা বসে না, ধাপ একই থাকে */
    public function test_opening_the_scan_page_changes_nothing(): void
    {
        $challan = $this->aConfirmedChallan();
        $events = DeliveryEvent::query()->count();
        $stage = $this->stageOf($challan);

        $this->get(route('sales.scan', $challan->public_id));
        $this->get(route('sales.delivery.scan', $challan->public_id))->assertOk();
        $this->get(route('sales.delivery.scan', $challan->public_id))->assertOk();

        $this->assertSame($events, DeliveryEvent::query()->count(), '⛔ স্ক্যানের পাতা খুলতেই একটা ধাপ বসে গেছে।');
        $this->assertSame($stage, $this->stageOf($challan));
    }

    /**
     * ⭐ পাতার বোতাম পুরনো পথেই যায় — আর স্ক্যান থেকে রওনা দিলেও বিল ও গেট পাস হয়।
     *
     * ⓘ ফর্মটা `sales.delivery.move`-এ POST করে; চাপার পরে স্ক্যানের পাতাতেই ফেরা।
     */
    public function test_dispatching_from_the_scan_page_makes_the_bill_and_the_gate_pass(): void
    {
        $challan = $this->aConfirmedChallan();
        $scan = route('sales.delivery.scan', $challan->public_id);

        $this->get($scan)->assertOk()->assertSee(route('sales.delivery.move', $challan), false);

        $this->from($scan)
            ->post(route('sales.delivery.move', $challan), ['stage' => DeliveryStage::DISPATCHED])
            ->assertSessionHasNoErrors()
            ->assertRedirect($scan);

        $this->assertSame(DeliveryStage::DISPATCHED, $this->stageOf($challan));
        $this->assertTrue(GatePass::query()->where('delivery_challan_id', $challan->id)->exists(), '⛔ স্ক্যান থেকে রওনায় গেট পাস হয়নি।');
        $this->assertTrue(SalesInvoice::query()->where('sale_no', $challan->fresh()->sale_no)->exists(), '⛔ স্ক্যান থেকে রওনায় বিল হয়নি।');
    }

    // ── ডিলার ────────────────────────────────────────────────────────

    /** ⭐ নিজের চালান — মাল, এই চালানের বিল, নিজের মোট বকেয়া */
    public function test_a_dealer_sees_their_own_paper_with_the_bill_and_the_due(): void
    {
        $challan = $this->aDispatchedChallan();
        $bill = SalesInvoice::query()->where('sale_no', $challan->sale_no)->firstOrFail();

        $this->asDealer()->get(route('sales.portal.scan', $challan->public_id))
            ->assertOk()
            ->assertSee((string) $challan->document_no)
            ->assertSee((string) $bill->document_no)
            ->assertSee('data-due', false)
            ->assertSee('data-confirm-form', false);
    }

    /** ⛔ অন্য গ্রাহকের চালান — ৪০৪, ৪০৩ নয় */
    public function test_a_dealer_cannot_open_someone_elses_paper(): void
    {
        $theirs = $this->aDispatchedChallan();

        $other = Customer::query()->whereKeyNot($this->customer->id)->firstOrFail();
        $other->forceFill(['portal_enabled' => true, 'portal_password' => 'other-pass-1'])->save();
        auth()->logout();

        $this->actingAs($other->fresh(), 'portal')
            ->get(route('sales.portal.scan', $theirs->public_id))->assertNotFound();

        $this->actingAs($other->fresh(), 'portal')
            ->post(route('sales.portal.scan.received', $theirs->public_id))->assertNotFound();

        $this->assertSame(DeliveryStage::DISPATCHED, $this->stageOf($theirs));
    }

    /** ⭐ "মাল বুঝে পেয়েছি" — পৌঁছেছে, প্রাপক তিনি, নোটে তাঁর কোড, আর অডিটে ওঠে */
    public function test_the_dealer_confirms_receipt_and_it_is_recorded(): void
    {
        $challan = $this->aDispatchedChallan();
        $trails = AuditTrail::query()->count();

        $this->asDealer()->post(route('sales.portal.scan.received', $challan->public_id))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.portal.scan', $challan->public_id));

        $this->assertSame(DeliveryStage::DELIVERED, $this->stageOf($challan));

        $event = DeliveryEvent::query()->where('delivery_challan_id', $challan->id)
            ->where('to_stage', DeliveryStage::DELIVERED)->firstOrFail();

        $this->assertSame((string) $this->customer->name(), (string) $event->receiver_name);
        $this->assertStringContainsString((string) $this->customer->code, (string) $event->note);

        /* ⛔ গ্রাহকের আইডি কর্মীর ঘরে বসেনি — `created_by` কর্মীর, আর ডিলার কর্মী নন */
        $this->assertNull($event->created_by);
        $this->assertGreaterThan($trails, AuditTrail::query()->count(), '⛔ ডিলারের নিশ্চিত করা অডিটে ওঠেনি।');
    }

    /** ⛔ দুইবার চাপলে "পৌঁছেছে" একবারই */
    public function test_confirming_twice_records_it_once(): void
    {
        $challan = $this->aDispatchedChallan();

        $this->asDealer()->post(route('sales.portal.scan.received', $challan->public_id))->assertSessionHasNoErrors();
        $this->asDealer()->post(route('sales.portal.scan.received', $challan->public_id))->assertSessionHasErrors('stage');

        $this->assertSame(1, DeliveryEvent::query()->where('delivery_challan_id', $challan->id)
            ->where('to_stage', DeliveryStage::DELIVERED)->count());
    }

    /** ⛔ রওনার আগে ডিলার "পেয়েছি" বলতে পারেন না — বোতামও নেই, আর চাপলেও সার্ভিস ফেরায় */
    public function test_a_dealer_cannot_confirm_goods_that_have_not_left(): void
    {
        $challan = $this->aConfirmedChallan();
        $stage = $this->stageOf($challan);

        $this->asDealer()->get(route('sales.portal.scan', $challan->public_id))
            ->assertOk()->assertSee('data-cannot-confirm', false)->assertDontSee('data-confirm-form', false);

        $this->asDealer()->post(route('sales.portal.scan.received', $challan->public_id))->assertSessionHasErrors();

        $this->assertSame($stage, $this->stageOf($challan));
    }

    // ── লগইনের পরে ফেরা ────────────────────────────────────────────────

    /** ⭐ QR থেকে এসে ডিলার লগইন করলে সেই QR-এর পাতায়; ⛔ অন্য সাইটের ঠিকানা থাকলে হোমে */
    public function test_after_signing_in_the_dealer_returns_to_the_scan_and_never_to_another_site(): void
    {
        $challan = $this->aConfirmedChallan();
        auth()->logout();

        $this->get(route('sales.scan', $challan->public_id))->assertOk();
        $this->post(route('sales.portal.login.attempt'), ['code' => $this->customer->code, 'password' => 'dealer-pass-1'])
            ->assertRedirect(route('sales.scan', $challan->public_id));

        auth('portal')->logout();

        $this->withSession(['url.intended' => 'https://evil.example/scan/'.$challan->public_id])
            ->post(route('sales.portal.login.attempt'), ['code' => $this->customer->code, 'password' => 'dealer-pass-1'])
            ->assertRedirect(route('sales.portal.home'));
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────

    private function aConfirmedChallan(): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10']]));
    }

    private function aDispatchedChallan(): DeliveryChallan
    {
        $challan = $this->aConfirmedChallan();
        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);

        return $challan->fresh();
    }

    private function asDealer(): static
    {
        auth()->logout();

        return $this->actingAs($this->customer->fresh(), 'portal');
    }

    private function stageOf(DeliveryChallan $challan): string
    {
        return (string) DeliveryState::query()->where('delivery_challan_id', $challan->id)->value('stage');
    }
}
