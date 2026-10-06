<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\PaperToken;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * এক কাগজে এক QR — মালিক, ১ অক্টোবর ২০২৬।
 *
 * ⓘ QR-এ কেবল সই-করা টোকেন ([[PaperToken]]); দেখতে লগইন আর চাবি লাগে; গেটম্যান "মাল বেরোল",
 * ডেলিভারিম্যান "ডেলিভারি নিশ্চিত"।
 *
 * ⭐ দাবি (সমন্বয়কের তালিকা):
 *   গাড়ি বসানোর আগে গেট-স্ক্যান → ফেরে; বাকির সীমা পার → ফেরে; ঠিক পথে বেরোনো একবারই বসে
 *   (দ্বিতীয় স্ক্যান "আগেই বেরিয়েছে"); অন্য কোম্পানির টোকেন / ভাঙা সই / বাতিল কাগজ → ৪০৪;
 *   চাবিহীন মানুষ কিছুই দেখে না।
 */
final class OneQrOpensThePaperForTheRightPeopleOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->customer = Customer::query()->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '100000'])->save();
    }

    public function test_the_good_path_lets_the_goods_out_once_and_then_confirms_delivery(): void
    {
        $challan = $this->challan(ownTransport: true);
        $token = app(PaperToken::class)->for($challan);
        $this->phone();

        $seen = $this->getJson('/api/v1/sales/scan/'.$token)->assertOk()->json();
        $this->assertSame($challan->document_no, $seen['document_no']);
        $this->assertTrue($seen['actions']['gate_out'], 'প্রস্তুতিটাই ভুল — "মাল বেরোল" বোতাম নেই।');

        $this->postJson('/api/v1/sales/scan/'.$token.'/gate-out')->assertOk();
        $this->assertSame(1, GatePass::query()->where('delivery_challan_id', $challan->id)->count(), '⛔ গেট পাস বসেনি।');

        $again = $this->postJson('/api/v1/sales/scan/'.$token.'/gate-out')->assertStatus(409)->json();
        $this->assertNotNull($again['gate_out']['at'] ?? null, 'দ্বিতীয় স্ক্যান বলেনি কবে বেরিয়েছিল।');
        $this->assertSame(1, GatePass::query()->where('delivery_challan_id', $challan->id)->count(), '⛔ দ্বিতীয় স্ক্যানে আরেকটা গেট পাস।');

        // ⭐ ফোন-নম্বর ছাড়া নয় (ধাপ ৭, ৬ অক্টোবর ২০২৬)
        $this->postJson('/api/v1/sales/scan/'.$token.'/deliver', ['receiver_name' => 'দোকানি'])->assertUnprocessable();
        $this->postJson('/api/v1/sales/scan/'.$token.'/deliver', ['receiver_name' => 'দোকানি', 'receiver_phone' => '01711-000000'])->assertOk()
            ->assertJsonPath('stage', 'delivered');
    }

    public function test_the_gate_refuses_without_transport(): void
    {
        $challan = $this->challan(ownTransport: false);
        $this->phone();

        $this->postJson('/api/v1/sales/scan/'.app(PaperToken::class)->for($challan).'/gate-out')->assertStatus(422);
        $this->assertSame(0, GatePass::query()->where('delivery_challan_id', $challan->id)->count(), '⛔ গাড়ি ছাড়াই মাল বেরোল।');
    }

    public function test_the_gate_refuses_over_the_credit_limit(): void
    {
        $challan = $this->challan(ownTransport: true);
        $this->customer->forceFill(['credit_limit' => '1'])->save();
        $this->phone();

        $this->postJson('/api/v1/sales/scan/'.app(PaperToken::class)->for($challan).'/gate-out')->assertStatus(422);
        $this->assertSame(0, GatePass::query()->where('delivery_challan_id', $challan->id)->count(), '⛔ বাকির সীমা পার হয়েও মাল বেরোল।');
    }

    public function test_a_wrong_cancelled_or_foreign_token_shows_nothing(): void
    {
        $challan = $this->challan(ownTransport: true);
        $token = app(PaperToken::class)->for($challan);
        $this->phone();

        // ভাঙা সই
        $bad = substr($token, 0, 22).str_repeat('A', 16);
        $this->getJson('/api/v1/sales/scan/'.$bad)->assertNotFound();
        $this->get('/q/'.$bad)->assertNotFound();

        // অন্য কোম্পানির নামে সই করা — একই চালান, কোম্পানি বদলে সই
        $foreign = Company::query()->whereKeyNot($this->company->id)->firstOrFail();
        $challan->forceFill(['company_id' => $foreign->id])->saveQuietly();
        $this->getJson('/api/v1/sales/scan/'.app(PaperToken::class)->for($challan->fresh()))->assertNotFound();
        $challan->forceFill(['company_id' => $this->company->id])->saveQuietly();

        // বাতিল কাগজ
        $challan->forceFill(['status' => 'cancelled'])->saveQuietly();
        $this->getJson('/api/v1/sales/scan/'.$token)->assertNotFound();
    }

    public function test_someone_without_the_delivery_key_sees_nothing(): void
    {
        $challan = $this->challan(ownTransport: true);
        $nobody = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $nobody->companies()->attach($this->company->id, ['is_active' => true]);
        $this->assertFalse($nobody->fresh()->can('sales.delivery.view'), 'প্রস্তুতিটাই ভুল — নতুন মানুষের হাতে চাবি।');

        Sanctum::actingAs($nobody->fresh(), [AuthController::APP]);
        $token = app(PaperToken::class)->for($challan);
        $this->getJson('/api/v1/sales/scan/'.$token)->assertForbidden();
        $this->postJson('/api/v1/sales/scan/'.$token.'/gate-out')->assertForbidden();
        $this->assertSame(0, GatePass::query()->where('delivery_challan_id', $challan->id)->count(), '⛔ চাবিহীন মানুষ মাল বের করল।');
    }

    public function test_the_web_link_goes_to_the_paper_and_the_print_carries_only_the_token(): void
    {
        $challan = $this->challan(ownTransport: true);
        $token = app(PaperToken::class)->for($challan);

        $this->get('/q/'.$token)->assertRedirect(route('sales.scan', $challan->public_id));
        $this->assertSame(38, strlen($token));
        $this->assertStringNotContainsString((string) $challan->document_no, $token);
    }

    /** ⭐ গেট পাসের কাগজেও সেই QR — গেটম্যান এটাই স্ক্যান করেন */
    public function test_the_printed_gate_pass_carries_the_signed_qr(): void
    {
        $challan = $this->challan(ownTransport: true);
        app(\App\Modules\Sales\Services\DeliveryStageService::class)->move($challan, \App\Modules\Sales\Services\DeliveryStage::DISPATCHED);
        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();

        $seen = null;
        \Illuminate\Support\Facades\View::composer('print.document', function ($view) use (&$seen) {
            $seen = ['name' => $view->getName(), 'data' => $view->getData()];
        });

        $this->get(route('sales.print.gate_pass', $pass))->assertOk();

        $this->assertNotNull($seen, 'প্রস্তুতিটাই ভুল — গেট পাস print.document দিয়ে আঁকা হয়নি।');
        $this->assertSame(route('sales.qr', app(PaperToken::class)->for($challan)), $seen['data']['doc']->qrUrl,
            '⛔ ছাপার আগের কপিতে (withWordsFor) QR হারিয়ে গেছে, বা গেট পাসে বসানোই হয়নি।');
        $this->assertStringContainsString('data-scan-qr', view($seen['name'], $seen['data'])->render(), '⛔ কাগজে QR আঁকা হয়নি।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function challan(bool $ownTransport): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => $ownTransport,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10']]));
    }

    private function phone(): void
    {
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
    }
}
