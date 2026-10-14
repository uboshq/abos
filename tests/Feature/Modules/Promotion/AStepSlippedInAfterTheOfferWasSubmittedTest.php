<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\ComboItem;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Models\PromotionScope;
use App\Modules\Promotion\Services\ComboRules;
use App\Modules\Promotion\Services\PromotionLifecycle;
use App\Modules\Promotion\Services\PromotionRules;
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
 * জমা দেওয়ার পরে পুরনো ট্যাব থেকে ধাপ ঢুকে গেল — Promotion লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ [[PromotionRules]] আর [[ComboRules]] *"খসড়া কি না"* হাতের কপি থেকে পড়ত। ⛔ একটা ট্যাবে অফার
 * জমা হলো, অন্য ট্যাবের কপি তখনো খসড়া — সেখান থেকে ধাপ, সুযোগ বা কম্বোর উপাদান বসে যেত, আর
 * অনুমোদনকারী যা দেখতেন তার বাইরের নিয়মে সই পড়ত।
 */
final class AStepSlippedInAfterTheOfferWasSubmittedTest extends TestCase
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

    private function aDraft(PromotionType $type, string $code): Promotion
    {
        $offer = new Promotion([
            'name_en' => 'Draft '.$code,
            'name_bn' => 'খসড়া '.$code,
            'starts_on' => Carbon::today(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = $code;
        $offer->type = $type;
        $offer->status = PromotionStatus::DRAFT;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        return $offer->fresh();
    }

    /** ⓘ অন্য ট্যাবে জমা — নিজের টাটকা কপি দিয়ে */
    private function submittedElsewhere(Promotion $offer): void
    {
        app(PromotionLifecycle::class)->submit(Promotion::query()->findOrFail($offer->id));
        $this->assertSame(PromotionStatus::SUBMITTED, $offer->fresh()->status);
    }

    public function test_a_stale_draft_copy_cannot_add_a_step(): void
    {
        $offer = $this->aDraft(PromotionType::QUANTITY_SLAB, 'PROM-SS-0001');
        $staleTab = Promotion::query()->findOrFail($offer->id);
        $this->submittedElsewhere($offer);

        try {
            app(PromotionRules::class)->addStep($staleTab, [
                'condition_kind' => 'quantity',
                'value_from' => '10',
                'value_to' => null,
                'benefit_kind' => 'percent',
                'amount' => '50',
            ]);
            $this->fail('জমা হওয়া অফারে পুরনো কপি থেকে ধাপ বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(0, PromotionCondition::query()->where('promotion_id', $offer->id)->count());
    }

    public function test_a_stale_draft_copy_cannot_add_a_scope(): void
    {
        $offer = $this->aDraft(PromotionType::QUANTITY_SLAB, 'PROM-SS-0002');
        $staleTab = Promotion::query()->findOrFail($offer->id);
        $this->submittedElsewhere($offer);

        try {
            app(PromotionRules::class)->addScope($staleTab, ScopeKind::CUSTOMER, 1);
            $this->fail('জমা হওয়া অফারে পুরনো কপি থেকে সুযোগ বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(0, PromotionScope::query()->where('promotion_id', $offer->id)->count());
    }

    public function test_a_stale_draft_copy_cannot_add_a_combo_part(): void
    {
        $offer = $this->aDraft(PromotionType::COMBO, 'PROM-SS-0003');
        $staleTab = Promotion::query()->findOrFail($offer->id);
        $this->submittedElsewhere($offer);

        $product = Product::query()->orderBy('id')->firstOrFail();

        try {
            app(ComboRules::class)->addItem($staleTab, (int) $product->id, '1');
            $this->fail('জমা হওয়া কম্বোতে পুরনো কপি থেকে উপাদান বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(0, ComboItem::query()->where('promotion_id', $offer->id)->count());
    }

    /** ⓘ উল্টো দিক — সত্যিকারের খসড়ায় ধাপ আগের মতোই বসে */
    public function test_a_real_draft_still_takes_a_step(): void
    {
        $offer = $this->aDraft(PromotionType::QUANTITY_SLAB, 'PROM-SS-0004');

        app(PromotionRules::class)->addStep($offer, [
            'condition_kind' => 'quantity',
            'value_from' => '10',
            'value_to' => null,
            'benefit_kind' => 'percent',
            'amount' => '5',
        ]);

        $this->assertSame(1, PromotionCondition::query()->where('promotion_id', $offer->id)->count());
    }
}
