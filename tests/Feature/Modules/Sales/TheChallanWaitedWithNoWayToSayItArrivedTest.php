<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চালান নিশ্চিত হলো, মাল গেল — কিন্তু "পৌঁছেছে" বলার কোনো বোতাম নেই। মালিকের পরিকল্পনা, ধাপ ৪, ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * নিশ্চিত চালান থাকত "বরাদ্দ" ধাপে। হাতে "পৌঁছেছে" বসানো যেত কেবল
 * "রওনা" থেকে — অর্থাৎ ডিপো থেকে হাতে দেওয়া বা ক্রেতার নিজের গাড়িতে
 * যাওয়া মালের জন্য আগে একটা মিথ্যা "রওনা" লিখতে হত। আর তালিকাগুলো
 * (চালান, বিল) কোথাও বলত না কোনটা এখনো পথে, কোনটা পৌঁছেছে।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * চালানের পাতায় "ডেলিভারি নিশ্চিত" — প্রাপকের নাম আগে থেকেই গ্রাহকের
 * নামে ভরা, এক চাপে। চালান আর বিলের তালিকায় "ডেলিভারির অপেক্ষায়" বা
 * "ডেলিভার্ড"। চালানের তালিকায় "নতুন চালান" নেই — চালান জন্মায় বিক্রি
 * থেকে, তালিকা থেকে নয়।
 */
final class TheChallanWaitedWithNoWayToSayItArrivedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        // ⭐ ভূমিকাহীন — চাবি দাবির ভিতরে ([[same-user-key-off-then-on]])
        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->clerk->givePermissionTo(['sales.challan.view', 'sales.delivery.view']);

        $this->actingAs($this->owner);
    }

    /** ⭐ নিশ্চিত চালান থেকে সরাসরি "পৌঁছেছে" — রওনার মিথ্যা ধাপ ছাড়া। */
    public function test_a_confirmed_challan_can_be_confirmed_delivered_straight_away(): void
    {
        $challan = $this->confirmedChallan();

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DELIVERED, ['receiver_name' => 'রহিম স্টোর']);

        $this->assertSame(DeliveryStage::DELIVERED, $this->stageOf($challan));
    }

    /**
     * ⛔→⭐ একই মানুষ: ধাপ বদলানোর চাবি ছাড়া বোতাম নেই আর পোস্টে ৪০৩;
     * চাবি দিলে বোতাম আছে, প্রাপক আগে থেকেই গ্রাহকের নামে, আর এক চাপে পৌঁছেছে।
     */
    public function test_the_confirm_delivery_button_opens_on_its_key_and_works_in_one_press(): void
    {
        $challan = $this->confirmedChallan();
        $customer = $challan->customer()->firstOrFail();

        $this->actingAs($this->clerk->fresh())->get(route('sales.challan.show', $challan))
            ->assertOk()
            ->assertDontSee(__('sales::delivery.action.confirm'));

        $this->actingAs($this->clerk->fresh())
            ->post(route('sales.delivery.move', $challan), ['stage' => DeliveryStage::DELIVERED, 'receiver_name' => 'x'])
            ->assertForbidden();

        $this->assertSame(DeliveryStage::ALLOCATED, $this->stageOf($challan), '⛔ ৪০৩ ফিরেছে, তবু ধাপ বসে গেছে।');

        $this->clerk->givePermissionTo('sales.delivery.update');

        $html = $this->actingAs($this->clerk->fresh())->get(route('sales.challan.show', $challan))
            ->assertOk()
            ->assertSee(__('sales::delivery.action.confirm'))
            ->getContent();

        $this->assertStringContainsString('value="'.e($customer->name()).'"', $html,
            '⛔ প্রাপকের ঘর খালি — এক চাপে হয় না, প্রতিবার নাম লিখতে হয়।');

        $this->actingAs($this->clerk->fresh())
            ->from(route('sales.challan.show', $challan))
            ->post(route('sales.delivery.move', $challan), [
                'stage' => DeliveryStage::DELIVERED,
                'receiver_name' => $customer->name(),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(DeliveryStage::DELIVERED, $this->stageOf($challan));
    }

    /** ⭐ চালানের তালিকা বলে কোনটা অপেক্ষায়, কোনটা পৌঁছেছে — আর "নতুন চালান" নেই। */
    public function test_the_challan_list_says_awaiting_or_delivered_and_offers_no_new_challan(): void
    {
        $waiting = $this->confirmedChallan();
        $arrived = $this->confirmedChallan();
        app(DeliveryStageService::class)->move($arrived, DeliveryStage::DELIVERED, ['receiver_name' => 'করিম']);

        $html = $this->get(route('sales.challan.index'))->assertOk()->getContent();

        $this->assertSame(
            ['awaiting', 'delivered'],
            [$this->summaryInRow($html, $waiting->document_no), $this->summaryInRow($html, $arrived->document_no)],
            '⛔ তালিকার সারিতে ডেলিভারির কথা নেই, নাকি ভুল কথা।',
        );

        $this->assertStringNotContainsString(route('sales.challan.create'), $html,
            '⛔ চালানের তালিকায় এখনো "নতুন চালান" — চালান জন্মায় বিক্রি থেকে।');
    }

    /** ⭐ বিলের তালিকাও একই কথা বলে — বিলের চালান পৌঁছালে "ডেলিভার্ড"। */
    public function test_the_invoice_list_follows_its_challan(): void
    {
        $challan = $this->confirmedChallan();
        $invoice = $this->invoiceFor($challan);

        $html = $this->get(route('sales.invoice.index'))->assertOk()->getContent();
        $this->assertSame('awaiting', $this->summaryInRow($html, $invoice->document_no));

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DELIVERED, ['receiver_name' => 'করিম']);

        $html = $this->get(route('sales.invoice.index'))->assertOk()->getContent();
        $this->assertSame('delivered', $this->summaryInRow($html, $invoice->document_no),
            '⛔ চালান পৌঁছেছে, বিলের তালিকা এখনো অপেক্ষায় বলে।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function confirmedChallan(): DeliveryChallan
    {
        $biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();

        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $biscuit->id, 'delivered_qty' => '5', 'rate' => '10']]);

        return app(DeliveryChallanService::class)->confirm($challan);
    }

    private function invoiceFor(DeliveryChallan $challan)
    {
        $line = $challan->lines()->firstOrFail();

        return app(SalesInvoiceService::class)->create([
            'customer_id' => $challan->customer_id,
            'warehouse_id' => $challan->warehouse_id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $line->product_id,
            'qty' => (string) $line->delivered_qty,
            'rate' => '10',
            'delivery_challan_line_id' => $line->id,
        ]]);
    }

    private function stageOf(DeliveryChallan $challan): string
    {
        return (string) DeliveryState::query()->where('delivery_challan_id', $challan->id)->value('stage');
    }

    /**
     * তালিকার যে সারিতে কাগজের নম্বর, সেই সারির ডেলিভারি-চিহ্ন।
     *
     * ⓘ চিহ্নটা `data-delivery="…"` — লেখা নয়, কারণ লেখা ভাষা ধরে বদলায়
     * আর "অপেক্ষায়" শব্দটা পাতার অন্য কোথাও থাকলেও দাবি সবুজ হত।
     */
    private function summaryInRow(string $html, string $documentNo): ?string
    {
        foreach (preg_split('/<tr[\s>]/', $html) ?: [] as $row) {
            if (str_contains($row, $documentNo) && preg_match('/data-delivery="([a-z_]+)"/', $row, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
