<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Services\ReturnReckoner;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ক্রেতা বিশ কার্টন ফেরত দিলেন, আর গোটা উপহারটাই রেখে দিলেন।
 *
 * ── ⭐ স্পেক §১৮, আর তার নিজের উদাহরণটাই এখানে ────────────────────────
 * *"১০০ কার্টন কিনে ৫ কার্টন উপহার; পরে ২০ কার্টন ফেরত দিলে system
 * re-evaluate করবে Customer এখনও eligible কি না।"*
 */
final class TheBuyerReturnedTwentyAndKeptTheWholeGiftTest extends TestCase
{
    use RefreshDatabase;

    private Promotion $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $this->offer = new Promotion([
            'name_en' => 'Eid mega',
            'name_bn' => 'ঈদের বড় অফার',
            'starts_on' => Carbon::today()->subMonth(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $this->offer->code = 'PROM-R-0001';
        $this->offer->type = PromotionType::BUY_X_GET_Y;
        $this->offer->status = PromotionStatus::ACTIVE;
        $this->offer->combines = PromotionCombines::BEST;
        $this->offer->created_by = $owner->id;
        $this->offer->save();
    }

    private function appliedAt(string $from, BenefitKind $kind, string $amount, string $worth): PromotionApplication
    {
        $step = PromotionCondition::query()->create([
            'promotion_id' => $this->offer->id,
            'kind' => ConditionKind::QUANTITY,
            'value_from' => $from,
        ]);

        $benefit = PromotionBenefit::query()->create([
            'promotion_id' => $this->offer->id,
            'promotion_condition_id' => $step->id,
            'kind' => $kind,
            'amount' => $amount,
        ]);

        return PromotionApplication::query()->create([
            'promotion_id' => $this->offer->id,
            'source_type' => 'sales_invoice',
            'source_id' => 8001,
            'promotion_benefit_id' => $benefit->id,
            'benefit_kind' => $kind,
            'benefit_amount' => $amount,
            'worth' => $worth,
        ]);
    }

    /**
     * ⛔ ১০০ থেকে ৮০-তে নামলে "১০০ কিনলে ৫ ফ্রি"-র ধাপের নিচে — পুরো উপহার ফেরত।
     *
     * ⚠️ এটাই স্পেকের নিজের উদাহরণ। ⓘ না ধরলে ক্রেতা ২০ কার্টন ফেরত দিয়েও
     * গোটা উপহারটা রেখে দিতেন।
     */
    public function test_falling_below_the_step_asks_for_the_whole_gift_back(): void
    {
        $applied = $this->appliedAt('100', BenefitKind::GOODS, '5', '5000');

        $verdict = app(ReturnReckoner::class)->afterReturn($applied, '100', '20');

        $this->assertFalse($verdict['still_eligible']);
        $this->assertSame(0, bccomp($verdict['recover'], '5000', 4),
            '৮০ কার্টনে নেমে গেলেও উপহারটা ফেরত চাওয়া হচ্ছে না।');
    }

    /** ⭐ পাল্টা-দাবি: ধাপের উপরেই থাকলে কিছুই ফেরত চাওয়া হয় না। */
    public function test_staying_above_the_step_keeps_the_gift(): void
    {
        $applied = $this->appliedAt('100', BenefitKind::GOODS, '5', '5000');

        $verdict = app(ReturnReckoner::class)->afterReturn($applied, '120', '20');

        $this->assertTrue($verdict['still_eligible']);
        $this->assertSame(0, bccomp($verdict['recover'], '0', 4),
            'ধাপের উপরেই আছেন, তবু উপহার ফেরত চাওয়া হচ্ছে।');
    }

    /**
     * ⭐ হিসাবটা **সেদিনের** নিয়মে — অফারের মেয়াদ শেষ হলেও।
     *
     * ⛔ আজকের নিয়মে হিসাব করলে মেয়াদোত্তীর্ণ অফারে *"যোগ্য নয়"* আসত, আর
     * সামান্য ফেরতেই পুরো উপহার ফেরত চাওয়া হত।
     */
    public function test_the_reckoning_uses_the_rule_of_that_day_not_today(): void
    {
        $applied = $this->appliedAt('100', BenefitKind::GOODS, '5', '5000');

        $this->offer->status = PromotionStatus::EXPIRED;
        $this->offer->ends_on = Carbon::today()->subDay();
        $this->offer->save();

        $verdict = app(ReturnReckoner::class)->afterReturn($applied, '120', '5');

        $this->assertTrue($verdict['still_eligible'],
            'অফার শেষ হয়েছে বলে একটা ছোট ফেরতেই পুরো উপহার ফেরত চাওয়া হচ্ছে।');
    }

    /** ⭐ শতাংশের ছাড় মালের সাথে অনুপাতে কমে। */
    public function test_a_percent_discount_shrinks_with_the_goods(): void
    {
        $applied = $this->appliedAt('50', BenefitKind::PERCENT, '5', '500');

        $verdict = app(ReturnReckoner::class)->afterReturn($applied, '100', '40');

        $this->assertTrue($verdict['still_eligible']);
        $this->assertSame(0, bccomp($verdict['keep'], '300', 4),
            '৬০% মাল থাকল, অথচ ছাড় পুরোটাই থেকে গেল।');
        $this->assertSame(0, bccomp($verdict['recover'], '200', 4));
    }
}
