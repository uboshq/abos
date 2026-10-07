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
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * অফারের ছাদ ছিল, কিন্তু বসানোর কোনো জায়গা ছিল না — স্পেক §১৫।
 *
 * ── ⚠️ কী ঘটছিল ────────────────────────────────────────────────────
 * ⓘ [[BudgetGuard]] প্রতিটা বিলে ছাদ মাপত, কিন্তু ছাদ বসানোর পর্দা
 * ছিল না। ⛔ বাস্তবে প্রতিটা অফার সীমাহীন চলত। ⚠️ আর মালিকের চাওয়া
 * *"মেয়াদ বাড়ানো-কমানো"*-র রুট ছিল, অথচ পাতায় ফর্ম ছিল না।
 *
 * ⭐ চাবির দাবিগুলো **একই মানুষ, একই অফার** — কেবল চাবিটা বদলায়।
 * ⓘ দুইজন আলাদা মানুষ হলে ৪০৩-টা সদস্যপদ বা সুইচের কারণেও আসতে পারত।
 */
final class TheOfferHadACeilingNobodyCouldSetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Promotion $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->offer = new Promotion([
            'name_en' => 'Ceiling me',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $this->offer->code = 'PROM-B-0001';
        $this->offer->type = PromotionType::QUANTITY_SLAB;
        $this->offer->status = PromotionStatus::ACTIVE;
        $this->offer->combines = PromotionCombines::BEST;
        $this->offer->created_by = $this->owner->id;
        $this->offer->save();
    }

    private function spent(string $worth): void
    {
        PromotionApplication::query()->create([
            'promotion_id' => $this->offer->id,
            'source_type' => 'sales_invoice',
            'source_id' => random_int(10000, 99999),
            'benefit_kind' => BenefitKind::AMOUNT,
            'benefit_amount' => $worth,
            'worth' => $worth,
        ]);
    }

    private function ceiling(): ?string
    {
        return PromotionBudget::query()
            ->where('promotion_id', $this->offer->id)
            ->where('kind', PromotionBudget::TOTAL)
            ->value('ceiling');
    }

    /** ⭐ ছাদ বসানো যায়, আর পাতায় খরচের পাশে দেখা যায়। */
    public function test_a_ceiling_can_be_set_and_is_shown_beside_what_is_spent(): void
    {
        $this->spent('250');

        $this->actingAs($this->owner)
            ->post(route('promotion.budget.store', $this->offer), ['kind' => 'total', 'ceiling' => '1000'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp((string) $this->ceiling(), '1000', 4), 'ছাদটা রাখা হয়নি।');

        $this->actingAs($this->owner)
            ->get(route('promotion.show', $this->offer))
            ->assertOk()
            ->assertSee('25%');
    }

    /**
     * ⭐ দ্বিতীয়বার বসালে পুরনো সারিটাই বদলায় — দুইটা ছাদ হয় না।
     *
     * ⓘ দুইটা হলে পাহারা ছোটটা মানত, আর পাতায় মানুষ বড়টা দেখতেন।
     */
    public function test_raising_the_ceiling_changes_the_one_row(): void
    {
        foreach (['500', '800'] as $ceiling) {
            $this->actingAs($this->owner)
                ->post(route('promotion.budget.store', $this->offer), ['kind' => 'total', 'ceiling' => $ceiling])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(1, PromotionBudget::query()->where('promotion_id', $this->offer->id)->count());
        $this->assertSame(0, bccomp((string) $this->ceiling(), '800', 4));
    }

    /**
     * ⛔ ছাদ খরচের নিচে নামে না — কিন্তু সমান হতে পারে।
     *
     * ⚠️ বিপজ্জনক ইনপুট: ৭০০ খরচের পরে ছাদ ৫০০। ⓘ পাল্টা-দাবি ৭০০ —
     * নাহলে *"সব ছাদ থামাও"* লিখেও প্রথম অর্ধেকটা সবুজ হত।
     */
    public function test_the_ceiling_cannot_go_below_what_is_already_spent(): void
    {
        $this->spent('700');

        $this->actingAs($this->owner)
            ->post(route('promotion.budget.store', $this->offer), ['kind' => 'total', 'ceiling' => '500'])
            ->assertSessionHasErrors('ceiling');
        $this->assertNull($this->ceiling(), 'খরচের নিচে ছাদ বসে গেছে।');

        $this->actingAs($this->owner)
            ->post(route('promotion.budget.store', $this->offer), ['kind' => 'total', 'ceiling' => '700'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp((string) $this->ceiling(), '700', 4));
    }

    /** ⛔ বাতিল অফারের ছাদ বদলায় না। */
    public function test_a_cancelled_offer_takes_no_budget(): void
    {
        $this->offer->status = PromotionStatus::CANCELLED;
        $this->offer->save();

        $this->actingAs($this->owner)
            ->post(route('promotion.budget.store', $this->offer), ['kind' => 'total', 'ceiling' => '1000'])
            ->assertSessionHasErrors('ceiling');

        $this->assertNull($this->ceiling());
    }

    /**
     * ⭐ একই মানুষ: `budget` চাবি ছাড়া দরজা বন্ধ, চাবি পেলে খোলে।
     *
     * ⚠️ `update` থাকলেও যথেষ্ট নয় — ছাদ বাড়ানো মানে আরও টাকা দেওয়া।
     */
    public function test_the_same_person_needs_the_budget_key(): void
    {
        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();
        $clerk->givePermissionTo([
            Permission::findOrCreate('promotion.view', 'web'),
            Permission::findOrCreate('promotion.update', 'web'),
        ]);

        $this->actingAs($clerk)
            ->post(route('promotion.budget.store', $this->offer), ['kind' => 'total', 'ceiling' => '1000'])
            ->assertForbidden();
        $this->assertNull($this->ceiling());

        $clerk->givePermissionTo(Permission::findOrCreate('promotion.budget', 'web'));

        $this->actingAs($clerk->fresh())
            ->post(route('promotion.budget.store', $this->offer), ['kind' => 'total', 'ceiling' => '1000'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp((string) $this->ceiling(), '1000', 4),
            'চাবি পাওয়ার পরেও দরজা খোলেনি — তাহলে ৪০৩-টা চাবির জন্য ছিল না।');
    }

    /**
     * ⭐ মেয়াদ বদলের ফর্মটা পাতায় সত্যিই আছে, আর চাপলে কাজ করে।
     *
     * ⓘ মালিক: *"barate hole barabe, komate hole komabe"*। ⚠️ দুই দিকেই —
     * বাড়ানো আর কমানো।
     */
    public function test_the_period_can_be_extended_and_shortened_from_the_page(): void
    {
        $this->actingAs($this->owner)
            ->get(route('promotion.show', $this->offer))
            ->assertOk()
            ->assertSee(route('promotion.reschedule', $this->offer), false);

        foreach ([Carbon::today()->addMonth(), Carbon::today()->addDays(2)] as $endsOn) {
            $this->actingAs($this->owner)
                ->post(route('promotion.reschedule', $this->offer), ['ends_on' => $endsOn->toDateString()])
                ->assertSessionHasNoErrors();

            $this->assertSame($endsOn->toDateString(), $this->offer->fresh()->ends_on->toDateString());
        }
    }
}
