<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\DispatchBill;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Sales\Services\ShipmentService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ফেরত পাকা হওয়া চালান আবার গেট পেরোয় না — গভীর অডিট (৯), ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * চালান রওনা হলো (বিল হলো), ফিরে এল ("পৌঁছায়নি"), ক্রেতা মালের একটা অংশ ফেরত দিলেন আর
 * ফেরতটা পাকা হলো — মাল গুদামে ফিরল। তারপর একই চালান আবার গাড়িতে উঠতে পারত: গেট পাস
 * বেরোত চালানের **পুরো** পরিমাণে, অথচ তার একটা অংশ আর বিক্রিতেই নেই। বিল আগেই আছে, তাই
 * নতুন বিল হত না; দারোয়ানের হাতের কাগজ আর খাতার মাল দুই রকম কথা বলত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * পাকা ফেরত আছে এমন চালান রওনা হয় না — ট্রিপে তোলায় নয়, ট্রিপ বেরোনোয় নয়, হাতের
 * "রওনা"-য় নয়, আর সরাসরি "পৌঁছেছে"-তেও নয় ([[DeliveryStageService::assertNothingCameBack()]])।
 * বাকি মাল পাঠাতে নতুন চালান।
 */
final class AReturnedChallanLeftTheGateAgainTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $biscuit;

    private string $returnNo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
    }

    /** ⭐ ফেরতের আগে আবার রওনা চলে; ⛔ ফেরত পাকা হওয়ার পরে একই চালানের হাতের রওনা ফেরে। */
    public function test_a_hand_dispatch_is_refused_once_a_return_is_posted(): void
    {
        $challan = $this->confirmedChallan();
        $stages = app(DeliveryStageService::class);

        $stages->move($challan, DeliveryStage::DISPATCHED);
        $stages->move($challan, DeliveryStage::FAILED, ['note' => 'দোকান বন্ধ']);

        // ⭐ ফেরতের আগে — পরদিন আবার রওনা স্বাভাবিক
        $stages->move($challan, DeliveryStage::DISPATCHED);
        $stages->move($challan, DeliveryStage::FAILED, ['note' => 'আবার বন্ধ']);
        $this->assertSame(2, $this->passes($challan), 'প্রস্তুতিটাই ভুল — ফেরতের আগে দুই রওনা, দুই গেট পাস হওয়ার কথা।');

        $this->returnPart($challan);

        $this->assertRefused(fn () => $stages->move($challan, DeliveryStage::DISPATCHED), $challan);
        $this->assertSame(DeliveryStage::FAILED, (string) $stages->ensure($challan->fresh())->stage, '⛔ ধাপ সরে গেছে।');
    }

    /** ⛔ ট্রিপে তোলাই যায় না, আর আগে বানানো খসড়া ট্রিপ বেরোলেও চালানটা রওনা হয় না। */
    public function test_a_trip_neither_takes_nor_dispatches_a_challan_with_a_posted_return(): void
    {
        $stages = app(DeliveryStageService::class);
        $trips = app(ShipmentService::class);

        // (ক) কোনো ট্রিপে নেই — ফেরতের পরে নতুন ট্রিপে তোলা
        $loose = $this->confirmedChallan();
        $stages->move($loose, DeliveryStage::DISPATCHED);
        $stages->move($loose, DeliveryStage::FAILED, ['note' => 'দোকান বন্ধ']);
        $this->returnPart($loose);

        $this->assertRefused(fn () => $trips->create($this->tripHead(), [$loose->id]), $loose);

        // (খ) ফেরতের আগে খসড়া ট্রিপে তোলা ছিল (তখন ওঠার যোগ্য) — ফেরতের পরে ট্রিপ বেরোলো
        $challan = $this->confirmedChallan();
        $stages->move($challan, DeliveryStage::DISPATCHED);
        $stages->move($challan, DeliveryStage::FAILED, ['note' => 'দোকান বন্ধ']);
        $draft = $trips->create($this->tripHead(), [$challan->id]);
        $this->returnPart($challan);

        $this->assertRefused(fn () => $trips->dispatch($draft->fresh()), $challan);

        $this->assertNotSame(\App\Core\Support\DocumentStatus::CONFIRMED, (string) Shipment::query()->findOrFail($draft->id)->status,
            '⛔ ট্রিপটা রওনা হয়ে গেছে।');
    }

    /** ⛔ গাড়ি ছাড়া সরাসরি "পৌঁছেছে" — এটাও গেট পেরোনো; ⭐ ফেরত নেই এমন যমজ চালানে চলে। */
    public function test_straight_to_delivered_is_refused_once_a_return_is_posted(): void
    {
        $stages = app(DeliveryStageService::class);
        $receiver = ['receiver_name' => 'দোকানদার'];

        $twin = $this->confirmedChallan();
        app(DispatchBill::class)->forDispatch($twin);
        $stages->move($twin, DeliveryStage::DELIVERED, $receiver);
        $this->assertSame(1, $this->passes($twin), 'প্রস্তুতিটাই ভুল — ফেরত ছাড়া সরাসরি পৌঁছানোয় একটা গেট পাস হওয়ার কথা।');

        $challan = $this->confirmedChallan();
        app(DispatchBill::class)->forDispatch($challan);
        $this->returnPart($challan);

        $this->assertRefused(fn () => $stages->move($challan, DeliveryStage::DELIVERED, $receiver), $challan);
    }

    private function assertRefused(callable $act, DeliveryChallan $challan): void
    {
        $passes = $this->passes($challan);
        $bills = $this->bills($challan);
        $message = null;

        try {
            $act();
        } catch (ValidationException $e) {
            $message = implode(' ', \Illuminate\Support\Arr::flatten($e->errors()));
        }

        $this->assertNotNull($message, '⛔ ফেরত পাকা হওয়া চালান আবার রওনা হলো।');
        $this->assertStringContainsString((string) $challan->document_no, $message, '⛔ বার্তায় চালানের নম্বর নেই: '.$message);
        $this->assertStringContainsString(
            (string) __('sales::delivery.errors.returned_cannot_travel', ['no' => $challan->document_no, 'return' => $this->returnNo]),
            $message,
            '⛔ অন্য কারণে থেমেছে, ফেরতের কারণে নয়: '.$message,
        );
        $this->assertSame($passes, $this->passes($challan), '⛔ ফেরানো রওনাতেও গেট পাস বেরিয়েছে।');
        $this->assertSame($bills, $this->bills($challan), '⛔ ফেরানো রওনাতেও বিল হয়েছে।');
    }

    private function confirmedChallan(): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $this->biscuit->id, 'delivered_qty' => '5', 'rate' => '10']]);

        return app(DeliveryChallanService::class)->confirm($challan);
    }

    /** চালানের বিলের ৫-এর ২টা ফেরত, পাকা। */
    private function returnPart(DeliveryChallan $challan): void
    {
        $invoice = $this->billOf($challan);
        $line = $invoice->lines()->firstOrFail();
        $returns = app(SalesReturnService::class);

        $this->returnNo = (string) $returns->confirm($returns->create([
            'customer_id' => $invoice->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'sales_invoice_id' => $invoice->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->biscuit->id, 'sales_invoice_line_id' => $line->id, 'qty' => '2']]))->document_no;
    }

    private function billOf(DeliveryChallan $challan): SalesInvoice
    {
        return SalesInvoice::query()
            ->whereHas('lines', fn ($q) => $q->whereIn('delivery_challan_line_id', $challan->lines()->pluck('id')))
            ->firstOrFail();
    }

    private function bills(DeliveryChallan $challan): int
    {
        return SalesInvoice::query()
            ->whereHas('lines', fn ($q) => $q->whereIn('delivery_challan_line_id', $challan->lines()->pluck('id')))
            ->count();
    }

    private function passes(DeliveryChallan $challan): int
    {
        return GatePass::query()->where('delivery_challan_id', $challan->id)->count();
    }

    /** @return array<string, mixed> */
    private function tripHead(): array
    {
        return [
            'trx_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'vehicle_no' => 'ঢাকা মেট্রো ট ১১-২২৩৩',
            'driver_name' => 'রফিক',
        ];
    }
}
