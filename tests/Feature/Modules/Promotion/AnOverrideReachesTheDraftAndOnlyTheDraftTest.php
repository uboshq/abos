<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ⛔ অফারের হাতে-বদল — খসড়া কাগজের সারিতে পৌঁছায়, পাকা কাগজে নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, প্রমোশন ২০;
 * [[PromotionDesk::override()]], [[SalesCouponPapers::offerChanged()]])।
 *
 * ⓘ বদল কেবল অফারের সারিতে লেখা হত: চালানের সারির ছাড় আগের অঙ্কেই থাকত, তাই বিলেও আগের ছাড় যেত। আর পাকা চালানের অফারও বদলানো
 * যেত — অথচ মাল বেরিয়ে গেছে, খাতা বসে গেছে।
 */
final class AnOverrideReachesTheDraftAndOnlyTheDraftTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    private Product $biscuit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        app(StockService::class)->move(product: $this->biscuit, warehouse: $this->warehouse, sourceType: StockService::ADJUSTMENT, sourceId: $this->biscuit->id, floor: '100');
    }

    public function test_an_override_on_a_draft_challan_changes_the_lines_discount(): void
    {
        $challan = $this->challanWithOffer();
        $applied = $this->applied($challan);

        $this->override($applied, '7')->assertSessionHasNoErrors();

        $this->assertSame('7.0000', (string) $challan->lines()->firstOrFail()->promotion_discount,
            '⛔ হাতে-বদল চালানের সারিতে পৌঁছাল না — বিলে আগের ছাড়ই যাবে');
    }

    public function test_an_override_on_a_confirmed_challan_is_refused(): void
    {
        $challan = $this->challanWithOffer();
        $applied = $this->applied($challan);
        Customer::query()->whereKey($challan->customer_id)->update(['credit_limit' => '100000000']);
        app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));

        $this->override($applied, '7')->assertSessionHasErrors('worth');

        $this->assertSame(0, bccomp('10', (string) $applied->fresh()->worth, 4), '⛔ পাকা চালানের অফার বদলে গেল');
        $this->assertFalse((bool) $applied->fresh()->was_overridden);
    }

    private function override(PromotionApplication $applied, string $worth): TestResponse
    {
        return $this->post(route('promotion.override', $applied), ['worth' => $worth, 'override_reason' => 'পুরনো ক্রেতা, মালিকের সম্মতিতে']);
    }

    private function applied(DeliveryChallan $challan): PromotionApplication
    {
        return PromotionApplication::query()->where('source_type', DeliveryChallan::drillSourceType())
            ->where('source_id', $challan->id)->whereNull('reversed_at')->sole();
    }

    private function challanWithOffer(): DeliveryChallan
    {
        $offer = new Promotion(['name_en' => 'Challan offer', 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek(), 'priority' => 0]);
        $offer->code = 'PROM-OV-0001';
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();
        $condition = PromotionCondition::query()->create(['promotion_id' => $offer->id, 'kind' => ConditionKind::QUANTITY, 'value_from' => '1', 'value_to' => null, 'step_order' => 0]);
        PromotionBenefit::query()->create(['promotion_id' => $offer->id, 'promotion_condition_id' => $condition->id, 'kind' => BenefitKind::PERCENT, 'amount' => '10']);

        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'), 'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(), 'own_transport' => true,
        ], [['product_id' => $this->biscuit->id, 'delivered_qty' => '10', 'rate' => '10']]);

        $this->post(route('sales.challan.offer.store', $challan), ['line_id' => $challan->lines()->value('id'), 'offer_id' => $offer->id])
            ->assertSessionHasNoErrors();
        $this->assertSame('10.0000', (string) $challan->lines()->firstOrFail()->promotion_discount, 'দৃশ্যটাই বানানো যায়নি');

        return $challan->fresh();
    }
}
