<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\ComboItem;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Models\PromotionScope;
use App\Modules\Promotion\Services\BillPromotionEngine;
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
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * কম্বো চাইত দুইটা পণ্য একসাথে, অথচ একটাতেই খুলে যেত — স্পেক §৭-ছ, §৭-জ।
 *
 * ── ⓘ কী মাপা হলো ──────────────────────────────────────────────────
 * [[PromotionEngine]] একবারে একটা সারি দেখে, আর অফারের ধরন দেখে না। ⚠️
 * তাই শর্তহীন সুবিধার একটা কম্বো তার কাছে *"সব পণ্যে খাটে"* — ক একা
 * কিনলেও খোলে, প্রতি সারিতে একবার। ⭐ [[BillPromotionEngine]] গোটা বিল
 * দেখে কম্বো খোলে, আর সারির ইঞ্জিনের উত্তর থেকে কম্বো ছেঁকে ফেলে।
 *
 * ⛔ প্রতিটা দাবির পাশে পাল্টা-দাবি: *"কখনো খোলে না"* লিখলে প্রথম অর্ধেক
 * সবুজ হত, *"সবসময় খোলে"* লিখলে দ্বিতীয় অর্ধেক।
 */
final class TheComboNeededBothAndOpenedWithOneTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $a;

    private Product $b;

    private Product $c;

    private int $serial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        [$this->a, $this->b, $this->c] = Product::query()->orderBy('id')->take(3)->get()->all();
    }

    private function anOffer(PromotionType $type, PromotionCombines $combines = PromotionCombines::BEST): Promotion
    {
        $this->serial++;

        $offer = new Promotion([
            'name_en' => $type->value.' '.$this->serial,
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-C-'.$this->serial;
        $offer->type = $type;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = $combines;
        $offer->created_by = $this->owner->id;
        $offer->save();

        return $offer;
    }

    /** ⓘ ক + খ, প্রতিটা অন্তত `$min` — শর্তহীন সুবিধাসহ। */
    private function aCombo(array $parts, BenefitKind $kind, string $amount, PromotionType $type = PromotionType::COMBO, ?string $cap = null): Promotion
    {
        $offer = $this->anOffer($type);

        foreach ($parts as [$product, $min]) {
            ComboItem::query()->create([
                'promotion_id' => $offer->id,
                'product_id' => $product->id,
                'min_qty' => $min,
            ]);
        }

        PromotionBenefit::query()->create([
            'promotion_id' => $offer->id,
            'promotion_condition_id' => null,
            'kind' => $kind,
            'amount' => $amount,
            'cap_per_bill' => $cap,
        ]);

        return $offer;
    }

    /** @return array<string, mixed> */
    private function line(int $id, Product $product, string $qty, string $value): array
    {
        return ['line_id' => $id, 'product_id' => $product->id, 'qty' => $qty, 'value' => $value];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function bill(array $lines): Collection
    {
        return app(BillPromotionEngine::class)->offersForBill($lines);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rowsOf(Collection $result, Promotion $offer): Collection
    {
        return $result->filter(fn (array $r) => $r['promotion']->id === $offer->id)->values();
    }

    private function assertMoney(string $expected, string $actual, string $message = ''): void
    {
        $this->assertSame(0, bccomp($expected, $actual, 4), $message." — চাই {$expected}, এল {$actual}");
    }

    /**
     * ⭐ ক একা খোলে না; ক + খ খোলে।
     *
     * ⚠️ বিপজ্জনক ইনপুট: শর্তহীন সুবিধা আর কোনো সুযোগ-সারি নেই — ঠিক সেই
     * আকার যেটা সারির ইঞ্জিন প্রতিটা সারিতে খাটায়। ⓘ ছাঁকনিটা না থাকলে ক-এর
     * সারিতে কম্বোটা `line` হয়ে ফিরত।
     */
    public function test_one_product_alone_does_not_open_the_combo_and_both_together_do(): void
    {
        $combo = $this->aCombo([[$this->a, '1'], [$this->b, '1']], BenefitKind::AMOUNT, '100');

        $alone = $this->rowsOf($this->bill([$this->line(1, $this->a, '1', '500')]), $combo);
        $this->assertCount(0, $alone, 'কেবল ক কিনতেই কম্বো খুলে গেছে।');

        $both = $this->rowsOf($this->bill([
            $this->line(1, $this->a, '1', '500'),
            $this->line(2, $this->b, '1', '300'),
        ]), $combo);

        $this->assertCount(1, $both, 'ক + খ একসাথে থাকলেও কম্বো খোলেনি, বা একাধিকবার খুলেছে।');
        $this->assertSame('bill', $both[0]['source'], 'কম্বোটা সারির পথে এসেছে, বিলের পথে নয়।');
        $this->assertSame(1, $both[0]['fits']);
        $this->assertMoney('100', $both[0]['worth']);
        $this->assertSame([1, 2], $both[0]['line_ids']);
    }

    /** ⛔ উপাদানহীন কম্বো — *"সবগুলো আছে"* খালি তালিকায় সবসময় সত্য, তবু খোলে না। */
    public function test_a_combo_with_no_components_never_opens(): void
    {
        $combo = $this->aCombo([], BenefitKind::AMOUNT, '100');

        $rows = $this->rowsOf($this->bill([
            $this->line(1, $this->a, '1', '500'),
            $this->line(2, $this->b, '1', '300'),
        ]), $combo);

        $this->assertCount(0, $rows, 'পণ্য বাছা হয়নি এমন কম্বো বিলে খুলে গেছে।');
    }

    /**
     * ⭐ ক×২ + খ×১, ন্যূনতম ১ করে: এক সেট, দুই নয়।
     *
     * ⓘ পাল্টা-দাবি: শতাংশ ছাড় কেবল সেটের ভিতরের মালে — এক ক আর এক খ।
     * ⛔ পুরো সারির দামে ধরলে সেটের বাইরের দ্বিতীয় ক-ও ছাড় পেত।
     */
    public function test_two_of_one_and_one_of_the_other_make_one_set_not_two(): void
    {
        $amount = $this->aCombo([[$this->a, '1'], [$this->b, '1']], BenefitKind::AMOUNT, '100');

        $lines = [
            $this->line(1, $this->a, '2', '1000'),
            $this->line(2, $this->b, '1', '300'),
        ];

        $row = $this->rowsOf($this->bill($lines), $amount)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, $row['fits'], 'খ একটা, অথচ কম্বো দুই সেট ধরেছে।');
        $this->assertMoney('100', $row['worth']);

        $amount->delete();
        $percent = $this->aCombo([[$this->a, '1'], [$this->b, '1']], BenefitKind::PERCENT, '10');

        $row = $this->rowsOf($this->bill($lines), $percent)->first();
        $this->assertNotNull($row);

        /* ⓘ সেটের ভিতরে: ক-এর অর্ধেক (৫০০) + খ (৩০০) = ৮০০ → ১০% = ৮০ */
        $this->assertMoney('80', $row['worth'], 'শতাংশ ছাড় সেটের বাইরের মালেও বসেছে।');
    }

    /** ⭐ বান্ডলের গুণিতক: দুইটাই ×২ হলে দুই সেট, সুবিধাও দুইবার। */
    public function test_a_bundle_with_both_doubled_fits_twice(): void
    {
        $bundle = $this->aCombo([[$this->a, '1'], [$this->b, '1']], BenefitKind::AMOUNT, '100', PromotionType::BUNDLE);

        $row = $this->rowsOf($this->bill([
            $this->line(1, $this->a, '2', '1000'),
            $this->line(2, $this->b, '2', '600'),
        ]), $bundle)->first();

        $this->assertNotNull($row);
        $this->assertSame(2, $row['fits']);
        $this->assertMoney('200', $row['worth']);
    }

    /** ⛔ `cap_per_bill` সেট গোনে: দুই সেট মিললেও ছাদ ১ হলে একটাই। */
    public function test_the_cap_per_bill_stops_the_sets(): void
    {
        $bundle = $this->aCombo([[$this->a, '1'], [$this->b, '1']], BenefitKind::AMOUNT, '100', PromotionType::BUNDLE, '1');

        $row = $this->rowsOf($this->bill([
            $this->line(1, $this->a, '2', '1000'),
            $this->line(2, $this->b, '2', '600'),
        ]), $bundle)->first();

        $this->assertNotNull($row);
        $this->assertSame(1, $row['fits'], 'ছাদ ১ সেট, অথচ দুই সেটের সুবিধা এসেছে।');
        $this->assertMoney('100', $row['worth']);
    }

    /**
     * ⭐ ১০০ টাকা তিন সমান সারিতে — যোগফল ঠিক ১০০, বাকিটা শেষ সারিতে।
     *
     * ⚠️ বিপজ্জনক ইনপুট: ১০০ ÷ ৩ কখনো মেলে না। ⓘ প্রতিটা ভাগ আলাদা কাটলে
     * ৯৯.৯৯৯৯ হত, আর খাতা এক পয়সার ভগ্নাংশে মিলত না।
     */
    public function test_a_hundred_spread_over_three_lines_adds_back_to_exactly_a_hundred(): void
    {
        $combo = $this->aCombo([[$this->a, '1'], [$this->b, '1'], [$this->c, '1']], BenefitKind::AMOUNT, '100');

        $row = $this->rowsOf($this->bill([
            $this->line(11, $this->a, '1', '300'),
            $this->line(12, $this->b, '1', '300'),
            $this->line(13, $this->c, '1', '300'),
        ]), $combo)->first();

        $this->assertNotNull($row);
        $this->assertSame([11, 12, 13], array_keys($row['spread']));

        $sum = '0';
        foreach ($row['spread'] as $share) {
            $sum = bcadd($sum, $share, 4);
        }

        $this->assertMoney('100', $sum, 'ভাগগুলোর যোগফল সুবিধার সমান নয়।');
        $this->assertMoney('33.3333', $row['spread'][11]);
        $this->assertMoney('33.3333', $row['spread'][12]);
        $this->assertMoney('33.3334', $row['spread'][13], 'বাকিটা শেষ সারিতে যায়নি।');

        /* ⓘ পাল্টা-দাবি: অসমান দামে অনুপাতে — ৫০০ আর ৩০০-তে ১০০ = ৬২.৫ + ৩৭.৫ */
        $this->assertSame(
            ['1' => '62.5000', '2' => '37.5000'],
            array_map('strval', app(BillPromotionEngine::class)->spreadOver('100', ['1' => '500', '2' => '300'])),
        );
    }

    /**
     * ⭐ সারির অফার সারির ইঞ্জিনের উত্তরই — হুবহু।
     *
     * ⓘ কোনো কম্বো নেই, দুইটা সারি, প্রতিটায় নিজের অফার। ⛔ বিলের ইঞ্জিন
     * নিয়মটা নতুন করে লিখলে একদিন অঙ্কটা আলাদা হত।
     */
    public function test_single_line_offers_come_back_exactly_as_the_line_engine_gave_them(): void
    {
        $onA = $this->anOffer(PromotionType::PERCENT_DISCOUNT);
        PromotionScope::query()->create(['promotion_id' => $onA->id, 'kind' => ScopeKind::PRODUCT, 'target_id' => $this->a->id]);
        $this->step($onA, BenefitKind::PERCENT, '10');

        $onB = $this->anOffer(PromotionType::FIXED_DISCOUNT);
        PromotionScope::query()->create(['promotion_id' => $onB->id, 'kind' => ScopeKind::PRODUCT, 'target_id' => $this->b->id]);
        $this->step($onB, BenefitKind::AMOUNT, '40');

        $lines = [
            $this->line(1, $this->a, '5', '5000'),
            $this->line(2, $this->b, '1', '300'),
        ];

        $result = $this->bill($lines);
        $engine = app(PromotionEngine::class);

        foreach ($lines as $line) {
            $expected = $engine->offersFor($line)
                ->map(fn (array $r) => [$r['promotion']->id, $r['benefit']->id, $r['worth']])
                ->all();

            $actual = $result
                ->filter(fn (array $r) => $r['line_ids'] === [$line['line_id']])
                ->map(fn (array $r) => [$r['promotion']->id, $r['benefit']->id, $r['worth']])
                ->values()
                ->all();

            $this->assertNotSame([], $expected, 'পরীক্ষাটা ফাঁকা — সারির ইঞ্জিন কিছুই দেয়নি।');
            $this->assertSame($expected, $actual, "সারি {$line['line_id']}-এর অফার বিলের ইঞ্জিনে বদলে গেছে।");
        }
    }

    /**
     * ⭐ সংঘাত কেবল একই সারিতে — [[PromotionCombines]]-এর নিয়মে।
     *
     * ⓘ দুইটাই `BEST`। গ-এর ছাড় কম্বোর কোনো সারি ছোঁয় না, তাই দুইটাই থাকে।
     * ⛔ পাল্টা-দাবি: ক-এর ছাড় কম্বোর সারি ছোঁয়, তাই বড়টা (কম্বো) একা জেতে।
     */
    public function test_a_combo_and_a_line_offer_clash_only_where_they_share_a_line(): void
    {
        $combo = $this->aCombo([[$this->a, '1'], [$this->b, '1']], BenefitKind::AMOUNT, '500');

        $onC = $this->anOffer(PromotionType::FIXED_DISCOUNT);
        PromotionScope::query()->create(['promotion_id' => $onC->id, 'kind' => ScopeKind::PRODUCT, 'target_id' => $this->c->id]);
        $this->step($onC, BenefitKind::AMOUNT, '20');

        $onA = $this->anOffer(PromotionType::FIXED_DISCOUNT);
        PromotionScope::query()->create(['promotion_id' => $onA->id, 'kind' => ScopeKind::PRODUCT, 'target_id' => $this->a->id]);
        $this->step($onA, BenefitKind::AMOUNT, '30');

        $result = $this->bill([
            $this->line(1, $this->a, '1', '1000'),
            $this->line(2, $this->b, '1', '1000'),
            $this->line(3, $this->c, '1', '1000'),
        ]);

        $this->assertCount(1, $this->rowsOf($result, $combo), 'কম্বোটা বাদ পড়েছে।');
        $this->assertCount(1, $this->rowsOf($result, $onC), 'গ-এর আলাদা ছাড়টা কম্বো কেড়ে নিয়েছে।');
        $this->assertCount(0, $this->rowsOf($result, $onA), 'দুইটা BEST অফার একই সারিতে যোগ হয়েছে।');
    }

    private function step(Promotion $offer, BenefitKind $kind, string $amount): void
    {
        $condition = PromotionCondition::query()->create([
            'promotion_id' => $offer->id,
            'kind' => ConditionKind::QUANTITY,
            'value_from' => null,
        ]);

        PromotionBenefit::query()->create([
            'promotion_id' => $offer->id,
            'promotion_condition_id' => $condition->id,
            'kind' => $kind,
            'amount' => $amount,
        ]);
    }
}
