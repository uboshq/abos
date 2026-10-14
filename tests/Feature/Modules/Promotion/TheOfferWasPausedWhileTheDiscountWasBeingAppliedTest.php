<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Services\PromotionDesk;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ছাড় বসানোর মাঝপথে অফারটা থামানো হলো — Promotion লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ [[PromotionDesk::apply()]] ইঞ্জিনকে জিজ্ঞেস করত তালার **আগে**, আর তালার পরে অবস্থা আর দেখত না।
 * ⛔ ইঞ্জিন পড়ার ঠিক পরে অন্য ট্যাবে অফারটা থামানো হলে থামানো অফারের ছাড় বিলে বসত।
 *
 * ⚠️ দুই ট্যাবের মাঝখানটা নিশ্চিতভাবে বানানো: ইঞ্জিনের অফার-খোঁজা প্রশ্নটা চলার ঠিক পরে
 * (`DB::listen`) সারিটা `paused` লেখা হয় — অর্থাৎ ইঞ্জিন "চলছে" দেখেছে, তালা দেখবে "থামানো"।
 */
final class TheOfferWasPausedWhileTheDiscountWasBeingAppliedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function aRunningPercentOffer(): Promotion
    {
        $offer = new Promotion([
            'name_en' => 'Five percent',
            'name_bn' => 'পাঁচ শতাংশ',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-PZ-0001';
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        $step = PromotionCondition::query()->create([
            'promotion_id' => $offer->id,
            'kind' => ConditionKind::QUANTITY,
            'value_from' => '1',
            'value_to' => null,
            'step_order' => 1,
        ]);

        PromotionBenefit::query()->create([
            'promotion_id' => $offer->id,
            'promotion_condition_id' => $step->id,
            'kind' => BenefitKind::PERCENT,
            'amount' => '5',
        ]);

        return $offer->fresh();
    }

    /** @return array<string, mixed> */
    private function line(): array
    {
        return [
            'product_id' => (int) Product::query()->orderBy('id')->firstOrFail()->id,
            'qty' => '10',
            'value' => '1000',
        ];
    }

    public function test_an_offer_paused_after_the_engine_looked_is_not_applied(): void
    {
        $offer = $this->aRunningPercentOffer();
        $paused = false;

        DB::listen(function (QueryExecuted $q) use ($offer, &$paused) {
            $sql = strtolower($q->sql);

            if ($paused || ! str_contains($sql, 'from `promotions`') || ! str_contains($sql, 'starts_on')
                || str_contains($sql, 'for update')) {
                return;
            }

            /* ⓘ অন্য ট্যাব — ইঞ্জিনের খোঁজার ঠিক পরে */
            $paused = true;
            DB::table('promotions')->where('id', $offer->id)->update(['status' => PromotionStatus::PAUSED->value]);
        });

        try {
            app(PromotionDesk::class)->apply($offer, $this->line(), 'test:bill', 1, 1);
            $this->fail('ইঞ্জিন পড়ার পরে থামানো অফারের ছাড় বিলে বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('promotion', $e->errors());
        }

        $this->assertTrue($paused, 'পরীক্ষার ফাঁদটা চলেনি — ইঞ্জিনের প্রশ্নটা চেনা যায়নি।');
        $this->assertSame(0, PromotionApplication::query()->where('promotion_id', $offer->id)->count());
    }

    /** ⓘ উল্টো দিক — কেউ না থামালে ছাড় আগের মতোই বসে */
    public function test_a_running_offer_is_still_applied(): void
    {
        $offer = $this->aRunningPercentOffer();

        $applied = app(PromotionDesk::class)->apply($offer, $this->line(), 'test:bill', 1, 1);

        $this->assertSame(0, bccomp('50', (string) $applied->worth, 4));
    }
}
