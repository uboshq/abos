<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * কেউ ছাড়টা বদলে দিলেন, আর পুরনোটা কেউ রাখল না — স্পেক §১৯।
 */
final class SomeoneChangedTheDiscountAndNobodyKeptTheOldOneTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private PromotionApplication $applied;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $offer = new Promotion([
            'name_en' => 'Override me',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-O-0001';
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        $this->applied = PromotionApplication::query()->create([
            'promotion_id' => $offer->id,
            'source_type' => 'sales_invoice',
            'source_id' => 6001,
            'benefit_kind' => BenefitKind::AMOUNT,
            'benefit_amount' => '100',
            'worth' => '100',
        ]);
    }

    /**
     * ⭐ বদলালে মূল অঙ্ক, কারণ, কে আর কখন — চারটাই থাকে।
     *
     * ⓘ স্পেক: *"Reason Mandatory, User, Date/Time, Original Benefit,
     * Modified Benefit"*।
     */
    public function test_an_override_keeps_the_original_and_the_reason(): void
    {
        $this->actingAs($this->owner)
            ->post(route('promotion.override', $this->applied), [
                'worth' => '150',
                'override_reason' => 'পুরনো ক্রেতা, মালিকের সম্মতিতে',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $this->applied->fresh();

        $this->assertSame(0, bccomp((string) $fresh->worth, '150', 4));
        $this->assertSame(0, bccomp((string) $fresh->original_worth, '100', 4),
            'হাতে বদলানোর পর মূল অঙ্কটা হারিয়ে গেছে।');
        $this->assertTrue($fresh->was_overridden);
        $this->assertSame($this->owner->id, (int) $fresh->overridden_by);
        $this->assertNotNull($fresh->overridden_at);
    }

    /**
     * ⛔ কারণ ছাড়া বদলানো যায় না — আর একটা বিন্দু কারণ নয়।
     *
     * ⚠️ বিপজ্জনক ইনপুট: `"."`। ⓘ কেবল `required` থাকলে এটাও চলত, আর
     * কারণের ঘরটা থাকত অথচ কিছুই বলত না।
     */
    public function test_a_dot_is_not_a_reason(): void
    {
        $this->actingAs($this->owner)
            ->post(route('promotion.override', $this->applied), ['worth' => '150', 'override_reason' => '.'])
            ->assertSessionHasErrors('override_reason');

        $this->assertSame(0, bccomp((string) $this->applied->fresh()->worth, '100', 4),
            'কারণ ছাড়াই অঙ্কটা বদলে গেছে।');
    }

    /**
     * ⭐ দুইবার বদলালেও "মূল" থাকে ইঞ্জিনের অঙ্কটাই।
     *
     * ⛔ নাহলে দ্বিতীয় বদলে প্রথম হাতে-বদলানো অঙ্কটাই "মূল" হয়ে যেত।
     */
    public function test_a_second_override_does_not_rewrite_the_original(): void
    {
        foreach (['150', '180'] as $worth) {
            $this->actingAs($this->owner)
                ->post(route('promotion.override', $this->applied), [
                    'worth' => $worth,
                    'override_reason' => 'পরপর দুইবার বদল',
                ]);
        }

        $this->assertSame(0, bccomp((string) $this->applied->fresh()->original_worth, '100', 4));
    }

    /**
     * ⛔ হাতে বাড়ানো অঙ্কও ছাদ মানে।
     *
     * ⓘ ছাদের মানেই *"এর বেশি নয়, কেউ চাইলেও"*।
     */
    public function test_an_override_cannot_break_the_budget_ceiling(): void
    {
        PromotionBudget::query()->create([
            'promotion_id' => $this->applied->promotion_id,
            'kind' => PromotionBudget::TOTAL,
            'ceiling' => '120',
        ]);

        $this->actingAs($this->owner)
            ->post(route('promotion.override', $this->applied), [
                'worth' => '150',
                'override_reason' => 'ছাদ ভাঙার চেষ্টা',
            ])
            ->assertSessionHasErrors('promotion');

        $this->assertSame(0, bccomp((string) $this->applied->fresh()->worth, '100', 4));
    }

    /** ⛔ `override` চাবি ছাড়া বদলানো যায় না — কেবল অফার বসানোর চাবি যথেষ্ট নয়। */
    public function test_without_the_override_key_nothing_changes(): void
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $stranger = User::factory()->create(['is_active' => true]);
        $stranger->companies()->attach($company->id, ['is_active' => true]);
        $stranger->switchCompany($company->id);

        $this->actingAs($stranger)
            ->post(route('promotion.override', $this->applied), [
                'worth' => '999',
                'override_reason' => 'চাবি ছাড়া চেষ্টা',
            ])
            ->assertForbidden();

        $this->assertSame(0, bccomp((string) $this->applied->fresh()->worth, '100', 4));
    }
}
