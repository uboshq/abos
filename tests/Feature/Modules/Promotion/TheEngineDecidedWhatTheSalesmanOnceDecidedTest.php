<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Models\PromotionScope;
use App\Modules\Promotion\Services\PromotionDesk;
use App\Modules\Promotion\Services\PromotionEngine;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use App\Modules\Promotion\Support\ScopeKind;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * যে সিদ্ধান্ত আগে বিক্রয়কর্মী নিতেন, এখন ইঞ্জিন নেয়।
 *
 * ── ⭐ স্পেক §২২ ─────────────────────────────────────────────────────
 * অফারের হিসাব একটাই জায়গায় — [[PromotionEngine]] — আর বিক্রয় তাকে
 * জিজ্ঞেস করে [[PromotionDesk]] দিয়ে।
 *
 * ── ⚠️ প্রতিটা দাবি বিপজ্জনক ইনপুট খায় ─────────────────────────────
 * ⓘ স্ল্যাবের দাবি মাপে **ঠিক সীমানার** সংখ্যা (৪৯ আর ৫০) — ৭৫ দিয়ে
 * মাপলে `<` আর `<=`-এর ভুল কোনোদিন ধরা পড়ত না। ⓘ সুযোগের দাবি মাপে
 * **পণ্য মিলেছে কিন্তু ক্রেতা মেলেনি** — উল্টো নিয়মে ঠিক ঐ ক্রেতাই
 * অফারটা পেতেন।
 */
final class TheEngineDecidedWhatTheSalesmanOnceDecidedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private int $serial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * একটা চলতি অফার, তার স্ল্যাব আর সুবিধাসহ।
     *
     * @param  list<array{from: ?string, to: ?string, kind: BenefitKind, amount: string}>  $steps
     */
    private function anOffer(array $steps, PromotionCombines $combines = PromotionCombines::BEST, int $priority = 0): Promotion
    {
        $this->serial++;

        $offer = new Promotion([
            'name_en' => 'Offer '.$this->serial,
            'name_bn' => 'অফার '.$this->serial,
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
            'priority' => $priority,
        ]);
        $offer->code = sprintf('PROM-T-%04d', $this->serial);
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = $combines;
        $offer->created_by = $this->owner->id;
        $offer->save();

        foreach ($steps as $i => $step) {
            $condition = PromotionCondition::query()->create([
                'promotion_id' => $offer->id,
                'kind' => ConditionKind::QUANTITY,
                'value_from' => $step['from'],
                'value_to' => $step['to'],
                'step_order' => $i,
            ]);

            PromotionBenefit::query()->create([
                'promotion_id' => $offer->id,
                'promotion_condition_id' => $condition->id,
                'kind' => $step['kind'],
                'amount' => $step['amount'],
            ]);
        }

        return $offer->fresh();
    }

    /** @return array<string, mixed> */
    private function line(string $qty, string $value, array $extra = []): array
    {
        return array_merge([
            'product_id' => $this->product->id,
            'qty' => $qty,
            'value' => $value,
        ], $extra);
    }

    private function engine(): PromotionEngine
    {
        return app(PromotionEngine::class);
    }

    /**
     * ⭐ স্ল্যাবের সীমানা — ঠিক ৪৯ আর ঠিক ৫০।
     *
     * ── ⚠️ কেন ঠিক সীমানার সংখ্যাটাই ────────────────────────────────
     * ⓘ ৭৫ দিয়ে মাপলে `from <= qty` আর `from < qty`-এর তফাত কোনোদিন
     * ধরা পড়ত না। ⛔ অথচ ঠিক ঐ তফাতটাই ঠিক করে যে ৫০ কার্টন কেনা
     * ক্রেতা ৫% পাবেন নাকি ২%।
     */
    public function test_the_slab_edges_fall_on_the_right_side(): void
    {
        $this->anOffer([
            ['from' => '1', 'to' => '49', 'kind' => BenefitKind::PERCENT, 'amount' => '2'],
            ['from' => '50', 'to' => '99', 'kind' => BenefitKind::PERCENT, 'amount' => '5'],
        ]);

        $at49 = $this->engine()->offersFor($this->line('49', '49000'));
        $at50 = $this->engine()->offersFor($this->line('50', '50000'));

        $this->assertSame('2.0000', (string) $at49->first()['benefit']->amount,
            '৪৯ কার্টনে ২% পাওয়ার কথা।');
        $this->assertSame('5.0000', (string) $at50->first()['benefit']->amount,
            'ঠিক ৫০ কার্টনে ৫% পাওয়ার কথা — সীমানাটা ভুল দিকে পড়েছে।');
    }

    /**
     * ⛔ পণ্য মিলেছে, ক্রেতা মেলেনি — অফার আসে না।
     *
     * ── ⚠️ বিপজ্জনক ইনপুটটাই এটা ────────────────────────────────────
     * ⓘ উল্টো নিয়মে — *"যেকোনো একটা দিক মিললেই হলো"* — ঢাকার জন্য
     * বানানো অফার চট্টগ্রামের ক্রেতাও পেতেন, কেবল পণ্যটা মিলেছে বলে।
     */
    public function test_an_offer_for_one_buyer_does_not_reach_another(): void
    {
        $offer = $this->anOffer([
            ['from' => '1', 'to' => null, 'kind' => BenefitKind::PERCENT, 'amount' => '3'],
        ]);

        PromotionScope::query()->create(['promotion_id' => $offer->id, 'kind' => ScopeKind::CUSTOMER, 'target_id' => 111]);
        PromotionScope::query()->create(['promotion_id' => $offer->id, 'kind' => ScopeKind::PRODUCT, 'target_id' => $this->product->id]);

        $this->assertCount(1, $this->engine()->offersFor($this->line('10', '1000', ['customer_id' => 111])),
            'যাঁর জন্য অফার, তিনিই পাচ্ছেন না — দৃশ্যটাই বানানো যায়নি।');

        $this->assertCount(0, $this->engine()->offersFor($this->line('10', '1000', ['customer_id' => 222])),
            'অন্য ক্রেতা অফারটা পেয়ে গেলেন — কেবল পণ্যটা মিলেছে বলে।');
    }

    /**
     * ⛔ যে উপহার দেওয়াই যায় না, সে তালিকায় ওঠে না।
     *
     * ⓘ উপহারের পণ্য বাছতে ভুলে গেলে অফারটা *"চলছে"* বলত, বিলে খাটত,
     * আর উপহারের জায়গায় কিছুই দিত না — নীরবে।
     */
    public function test_a_gift_with_no_product_never_reaches_the_bill(): void
    {
        $this->anOffer([
            ['from' => '1', 'to' => null, 'kind' => BenefitKind::GOODS, 'amount' => '1'],
        ]);

        $this->assertCount(0, $this->engine()->offersFor($this->line('10', '1000')));
    }

    /**
     * ⭐ "সবচেয়ে ভালোটা" — দুইটা খাটলে একটাই, আর বড়টা।
     *
     * ⓘ মালিকের মার্জিন ৩.৮২%। ⛔ দুইটা ছাড় নীরবে যোগ হলে ঐ সারিতে লাভ
     * বলে কিছু থাকত না।
     */
    public function test_best_gives_one_offer_and_the_bigger_one(): void
    {
        $this->anOffer([['from' => '1', 'to' => null, 'kind' => BenefitKind::PERCENT, 'amount' => '2']]);
        $this->anOffer([['from' => '1', 'to' => null, 'kind' => BenefitKind::PERCENT, 'amount' => '5']]);

        $fit = $this->engine()->offersFor($this->line('10', '1000'));

        $this->assertCount(1, $fit, 'দুইটা "সবচেয়ে ভালোটা" অফার একসাথে বসে গেছে।');
        $this->assertSame('5.0000', (string) $fit->first()['benefit']->amount);
    }

    /** ⭐ পাল্টা-দাবি: দুইজনেই "যোগ হব" বললে দুইটাই বসে। */
    public function test_two_offers_that_both_add_do_sit_together(): void
    {
        $this->anOffer([['from' => '1', 'to' => null, 'kind' => BenefitKind::PERCENT, 'amount' => '2']], PromotionCombines::ADDS);
        $this->anOffer([['from' => '1', 'to' => null, 'kind' => BenefitKind::PERCENT, 'amount' => '5']], PromotionCombines::ADDS);

        $this->assertCount(2, $this->engine()->offersFor($this->line('10', '1000')),
            'দুইটা "যোগ হবে" অফার একসাথে বসেনি — তাহলে বিকল্পটা কেবল লেখা।');
    }

    /** ⛔ একজন "একা চলব" বললে অন্যজন যাই বলুক, বসে না। */
    public function test_an_offer_that_runs_alone_keeps_the_others_off(): void
    {
        $this->anOffer([['from' => '1', 'to' => null, 'kind' => BenefitKind::PERCENT, 'amount' => '5']], PromotionCombines::ALONE, priority: 10);
        $this->anOffer([['from' => '1', 'to' => null, 'kind' => BenefitKind::PERCENT, 'amount' => '2']], PromotionCombines::ADDS);

        $this->assertCount(1, $this->engine()->offersFor($this->line('10', '1000')));
    }

    /**
     * ⭐ "আর ৮ কার্টন নিলে" — স্পেক §১০।
     *
     * ⚠️ ভুল সংখ্যাটা নীরব: ক্রেতা আট কার্টন বাড়িয়ে নিতেন আর তবু অফারটা
     * খুলত না।
     */
    public function test_the_nudge_names_the_exact_shortfall(): void
    {
        $this->anOffer([['from' => '100', 'to' => null, 'kind' => BenefitKind::PERCENT, 'amount' => '5']]);

        $almost = $this->engine()->almostFor($this->line('92', '92000'));

        $this->assertCount(1, $almost);
        $this->assertSame('8.0000', $almost->first()['short_by']);
    }

    /**
     * ⭐ মেয়াদ কমালেও আগে কাটা বিলের সুবিধা অক্ষত — স্পেক §১৮।
     *
     * ── ⚠️ বিপজ্জনক ইনপুট: মেয়াদ কমানোর **আগে** কাটা একটা বিল ────────
     * ⓘ মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর: মেয়াদ দুই দিকেই বদলানো যায়।
     * ⛔ তাই তারিখ দিয়ে পুরনো বিল বাঁচানো যায় না — বাঁচে জমে থাকা সারিতে।
     *
     * ⚠️ দাবিটা লেখা সম্ভব হলো কেবল এখন, `PromotionApplication` আসার পর।
     * ⓘ আগে লিখলে সবুজ হত *কারণ কোনো বিলই ছিল না* — কোঅর্ডিনেটরের সাথে
     * ঐ কথাতেই একমত হয়েছিলাম।
     */
    public function test_shortening_the_dates_leaves_an_earlier_bill_untouched(): void
    {
        $offer = $this->anOffer([['from' => '1', 'to' => null, 'kind' => BenefitKind::PERCENT, 'amount' => '5']]);

        $applied = app(PromotionDesk::class)->apply($offer, $this->line('10', '1000'), 'sales_invoice', 9001);

        $this->assertSame('50.0000', (string) $applied->worth, 'দৃশ্যটাই বানানো যায়নি।');

        /* ⓘ মেয়াদ গতকালে নামিয়ে আনা — এখন বিলটা মেয়াদের বাইরে */
        $offer->ends_on = Carbon::today()->subDay();
        $offer->save();

        $this->assertCount(0, $this->engine()->offersFor($this->line('10', '1000')),
            'মেয়াদ কমানোর পরেও অফারটা নতুন বিলে খাটছে।');

        $kept = PromotionApplication::query()->findOrFail($applied->id);

        $this->assertSame('50.0000', (string) $kept->worth,
            'মেয়াদ কমাতেই আগে কাটা বিলের সুবিধা বদলে গেছে।');
        $this->assertSame('5.0000', (string) $kept->benefit_amount);
    }

    /**
     * ⛔ ছাদ ছাড়ালে থামে — আর **বসানোর আগেই**।
     *
     * ⓘ বাকির সীমায় আজ উল্টো ক্রম ধরা পড়েছে: বাধাটা অনুমোদনের পরে ছিল,
     * তাই সই হলেই সীমা ছাড়ানো বিল চলে যেত।
     */
    public function test_an_offer_stops_at_its_budget_before_anything_is_written(): void
    {
        $offer = $this->anOffer([['from' => '1', 'to' => null, 'kind' => BenefitKind::AMOUNT, 'amount' => '60']]);

        PromotionBudget::query()->create([
            'promotion_id' => $offer->id,
            'kind' => PromotionBudget::TOTAL,
            'ceiling' => '100',
        ]);

        $desk = app(PromotionDesk::class);
        $desk->apply($offer, $this->line('1', '500'), 'sales_invoice', 9101);

        try {
            $desk->apply($offer, $this->line('1', '500'), 'sales_invoice', 9102);
            $this->fail('বাজেট ছাড়িয়েও অফারটা বসে গেছে।');
        } catch (ValidationException) {
            /* ⓘ প্রত্যাশিত */
        }

        $this->assertSame(1, PromotionApplication::query()->where('promotion_id', $offer->id)->count(),
            'বাধা দিলেও সারিটা লেখা হয়ে গেছে — বাধাটা বসানোর পরে বসেছে।');
    }

    /** ⭐ পাল্টা-দাবি: ঠিক ছাদের সমান হলে বসে — বাজেটের শেষ টাকাটাও খরচ হয়। */
    public function test_spending_exactly_the_budget_is_allowed(): void
    {
        $offer = $this->anOffer([['from' => '1', 'to' => null, 'kind' => BenefitKind::AMOUNT, 'amount' => '50']]);

        PromotionBudget::query()->create([
            'promotion_id' => $offer->id,
            'kind' => PromotionBudget::TOTAL,
            'ceiling' => '100',
        ]);

        $desk = app(PromotionDesk::class);
        $desk->apply($offer, $this->line('1', '500'), 'sales_invoice', 9201);
        $desk->apply($offer, $this->line('1', '500'), 'sales_invoice', 9202);

        $this->assertSame(2, PromotionApplication::query()->where('promotion_id', $offer->id)->count(),
            'ঠিক ছাদের সমান খরচে শেষ বিলটা আটকে গেছে — বাজেটের শেষ টাকা কোনোদিন খরচ হবে না।');
    }
}
