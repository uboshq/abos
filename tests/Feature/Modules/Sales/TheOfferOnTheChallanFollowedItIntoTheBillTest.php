<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
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
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * চালানের অফার বিলে যায়, ভাগে ভাগে — আর চালান বদলালে বা বাতিল হলে অফার ফেরে (অডিট §১১; abos-69, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── দৃশ্য ────────────────────────────────────────────────────────────
 * ১০ পিস × ১০ টাকা = ১০০ টাকার সারিতে ১০% অফার → সারির অফারের ছাড় ১০ টাকা ([[ChallanOffers]])।
 * বিল দুই ভাগে, ৪ আর ৬ পিস → ভাগ ৪ আর ৬ টাকা, যোগ ঠিক ১০ ([[ChallanOfferShare]])।
 *
 * ⛔ যা ভাঙলে এখানে লাল:
 * - খসড়া চালান বদলালে অফার না উঠলে পুরনো সারির আইডিতে অনাথ থেকে বাজেট খরচ ধরে রাখত;
 * - প্রথম আংশিক বিল পুরো অফার নিলে দ্বিতীয় বিল শূন্য পেত আর প্রথম বিলের ছাড় বাস্তবের চেয়ে বেশি দেখাত;
 * - বাতিল চালানে অফার থেকে গেলে বাজেট আর কুপন আটকে থাকত।
 */
final class TheOfferOnTheChallanFollowedItIntoTheBillTest extends TestCase
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
    }

    /** ⭐ খসড়ায় অফার বসে: সারির ঘরে ১০ টাকা, আর প্রোমোশনের খাতায় একটা খোলা প্রয়োগ। */
    public function test_an_offer_sits_on_a_draft_challan_line(): void
    {
        $offer = $this->tenPercentOffer();
        $challan = $this->draftChallan();

        $this->applyOffer($challan, $offer)->assertSessionHasNoErrors();

        $this->assertSame('10.0000', (string) $challan->lines()->firstOrFail()->promotion_discount,
            '⛔ অফার বসেছে বলে ফিরল, অথচ সারিতে ছাড় নেই — দৃশ্যটাই বানানো যায়নি।');
        $this->assertSame(1, $this->openApplications($challan));
    }

    /**
     * ⛔ কোম্পানি প্রচার বন্ধ রাখলে অফার বসে না — পাতায় অংশটা নেই, আর সরাসরি অনুরোধেও দরজা বন্ধ; চালু করলে একই মানুষ,
     * একই চালানে বসাতে পারেন (৪ অক্টোবর ২০২৬)।
     */
    public function test_with_promotions_switched_off_no_offer_is_shown_or_placed(): void
    {
        $offer = $this->tenPercentOffer();
        $challan = $this->draftChallan();

        app(\App\Core\Services\SettingsService::class)->set('promotion.enabled', false);
        $this->assertFalse(app(\App\Modules\Sales\Services\ChallanOffers::class)->enabled(), '⛔ প্রচার বন্ধ, তবু অফারের অংশ চালু।');
        $this->applyOffer($challan, $offer)->assertSessionHasErrors('offer_id');
        $this->assertSame(0, $this->openApplications($challan), '⛔ প্রচার বন্ধ, তবু সরাসরি অনুরোধে অফার বসে গেল।');

        app(\App\Core\Services\SettingsService::class)->set('promotion.enabled', true);
        $this->applyOffer($challan->fresh(), $offer)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->openApplications($challan), 'প্রচার চালু করার পরেও অফার বসল না।');
    }

    /** ⛔ খসড়া বদলালে অফার ওঠে — সারিতে শূন্য, আর প্রয়োগটা উল্টানো। */
    public function test_editing_the_draft_takes_the_offer_back(): void
    {
        $offer = $this->tenPercentOffer();
        $challan = $this->draftChallan();
        $this->applyOffer($challan, $offer)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->openApplications($challan), 'অফারটাই বসেনি — দাবিটা কিছু মাপছে না।');

        app(DeliveryChallanService::class)->update($challan->fresh(), [
            'customer_id' => $challan->customer_id,
            'warehouse_id' => $challan->warehouse_id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $this->biscuit->id, 'delivered_qty' => '10', 'rate' => '10']]);

        $this->assertSame(0, $this->openApplications($challan),
            '⛔ চালান বদলাল, অফার পুরনো সারিতে খোলা রয়ে গেল — বাজেট ঐ টাকা খরচ ধরে রাখে।');
        $this->assertSame('0.0000', (string) $challan->lines()->firstOrFail()->promotion_discount,
            '⛔ নতুন সারিতে পুরনো অফারের ছাড় বসে আছে।');
    }

    /** ⛔ বাতিল চালানে অফার ফেরে। */
    public function test_cancelling_the_challan_takes_the_offer_back(): void
    {
        $offer = $this->tenPercentOffer();
        $challan = $this->draftChallan();
        $this->applyOffer($challan, $offer)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->openApplications($challan), 'অফারটাই বসেনি — দাবিটা কিছু মাপছে না।');

        app(DeliveryChallanService::class)->cancel($challan->fresh(), 'ভুল চালান');

        $this->assertSame(0, $this->openApplications($challan),
            '⛔ চালান বাতিল, অফার এখনো খোলা — বাজেট আর কুপন আটকে থাকে।');
    }

    /**
     * ⭐ দুই আংশিক বিল: ৪ পিসে ৪ টাকা, ৬ পিসে ৬ টাকা — যোগ ঠিক অফারের ১০।
     * ⓘ বিলের সারির `discount`-এ মানুষের নিজের ছাড় (শূন্য) + অফারের ভাগ।
     */
    public function test_two_partial_bills_share_the_offer_by_quantity(): void
    {
        $offer = $this->tenPercentOffer();
        $challan = $this->draftChallan();
        $this->applyOffer($challan, $offer)->assertSessionHasNoErrors();
        $challan = app(DeliveryChallanService::class)->confirm($challan->fresh());

        $first = $this->bill($challan, '4');
        $second = $this->bill($challan, '6');

        $firstLine = $first->lines()->firstOrFail();
        $secondLine = $second->lines()->firstOrFail();

        $this->assertSame('4.0000', (string) $firstLine->promotion_discount,
            '⛔ প্রথম আংশিক বিল (৪ পিস) অফারের ৪ টাকা পাওয়ার কথা — পরিমাণের অনুপাতে।');
        $this->assertSame('4.0000', (string) $firstLine->discount,
            '⛔ সারির ছাড়ে অফারের ভাগ বসেনি — বিলের মোট অফার ছাড়া।');
        $this->assertSame('6.0000', (string) $secondLine->promotion_discount,
            '⛔ দ্বিতীয় বিল অফারের বাকি ৬ টাকা পায়নি।');
        $this->assertSame('10.0000', bcadd((string) $firstLine->promotion_discount, (string) $secondLine->promotion_discount, 4),
            '⛔ দুই বিলের ভাগের যোগ অফারের সমান নয়।');
    }

    /**
     * ⏸ মালিকের সিদ্ধান্তের অপেক্ষায় (সমন্বয়কারী জিজ্ঞেস করছেন, ৪ অক্টোবর ২০২৬)।
     *
     * আজকের আচরণ ([[SalesInvoiceService::assertDiscountApproved()]]): বিলের ছাড় থেকে অফারের ভাগ বাদ দিয়ে
     * কেবল মানুষের নিজের ছাড় মালিকের সই চায় — অফার চালুর সময়েই সই নিয়েছিল। মালিকের ১ অক্টোবরের নিয়ম
     * নাম ধরে কেবল স্কিম/অফারের ফ্রি মাল ছাড় দেয়; টাকার অফার ছাড় পায় কি না, সেটা মালিক বলবেন।
     * ⓘ নিচের দাবি আজকের আচরণ লিখে রাখে, কিন্তু চলে না — সিদ্ধান্ত এলে markTestIncomplete সরিয়ে ঠিক দিকটা দাবি করুন।
     */
    public function test_the_offer_share_and_the_owner_discount_signature_PENDING_OWNER(): void
    {
        $this->markTestIncomplete('মালিকের সিদ্ধান্ত বাকি: টাকার অফারের ছাড় কি প্রতিটা বিলে মালিকের ছাড়ের সই চাইবে, নাকি অফার চালুর সময়ের সই-ই যথেষ্ট? সিদ্ধান্তের আগে কোনো দিকেই দাবি নয়।');

        $offer = $this->tenPercentOffer();
        $challan = $this->draftChallan();
        $this->applyOffer($challan, $offer)->assertSessionHasNoErrors();
        $challan = app(DeliveryChallanService::class)->confirm($challan->fresh());
        $invoice = $this->bill($challan, '10');

        // আজকের আচরণ: বিলে কেবল অফারের ছাড় থাকলে সইয়ের কিছু নেই — assertDiscountApproved() থামায় না
        app(\App\Modules\Sales\Services\SalesInvoiceService::class)->assertDiscountApproved($invoice->fresh());
        $this->assertSame('10.0000', (string) $invoice->lines()->sum('promotion_discount'));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** ১ পিস থেকে ১০% — কোনো সীমা নেই, যেকোনো গ্রাহক, যেকোনো পণ্য। */
    private function tenPercentOffer(): Promotion
    {
        $offer = new Promotion([
            'name_en' => 'Challan offer',
            'name_bn' => 'চালানের অফার',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
            'priority' => 0,
        ]);
        $offer->code = 'PROM-CH-0001';
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        $condition = PromotionCondition::query()->create([
            'promotion_id' => $offer->id,
            'kind' => ConditionKind::QUANTITY,
            'value_from' => '1',
            'value_to' => null,
            'step_order' => 0,
        ]);

        PromotionBenefit::query()->create([
            'promotion_id' => $offer->id,
            'promotion_condition_id' => $condition->id,
            'kind' => BenefitKind::PERCENT,
            'amount' => '10',
        ]);

        return $offer->fresh();
    }

    private function draftChallan(): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $this->biscuit->id, 'delivered_qty' => '10', 'rate' => '10']]);
    }

    private function applyOffer(DeliveryChallan $challan, Promotion $offer): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('sales.challan.offer.store', $challan), [
            'line_id' => $challan->lines()->value('id'),
            'offer_id' => $offer->id,
        ]);
    }

    private function openApplications(DeliveryChallan $challan): int
    {
        return PromotionApplication::query()
            ->where('source_type', DeliveryChallan::drillSourceType())
            ->where('source_id', $challan->id)
            ->whereNull('reversed_at')
            ->count();
    }

    /** চালানের সারি ধরে আংশিক বিল — ফর্ম যা পাঠায়; মানুষের নিজের ছাড় শূন্য। */
    private function bill(DeliveryChallan $challan, string $qty): SalesInvoice
    {
        $line = $challan->lines()->firstOrFail();
        $before = SalesInvoice::query()->max('id') ?? 0;

        $this->post(route('sales.invoice.store'), [
            'delivery_challan_id' => $challan->id,
            'customer_id' => $challan->customer_id,
            'warehouse_id' => $challan->warehouse_id,
            'trx_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $line->product_id,
                'delivery_challan_line_id' => $line->id,
                'qty' => $qty,
                'rate' => '10',
                'discount' => '0',
            ]],
        ])->assertSessionHasNoErrors();

        return SalesInvoice::query()->where('id', '>', $before)->latest('id')->firstOrFail();
    }
}
