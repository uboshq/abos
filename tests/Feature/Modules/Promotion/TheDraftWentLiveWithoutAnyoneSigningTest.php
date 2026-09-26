<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * খসড়া অফার কারও সই ছাড়াই চালু হয়ে যেত।
 *
 * ── ⭐ এটাই সেই দাবি যা আগে ফাঁকি দিত ───────────────────────────────
 * ⓘ প্রথম পাহারায় একটা দাবি ছিল *"খসড়া সোজা চালু হতে পারে না"*। ⛔
 * সে কেবল enum-এর মানচিত্র পড়ত, আর ঐ ফাইলের নিজের সেটআপই অবস্থাটা
 * সরাসরি বসাত — অর্থাৎ যে দরজা নিষেধ, সেটা দিয়েই ঢোকা হত।
 *
 * ⭐ এখানে প্রতিটা দাবি **আসল HTTP দরজা** ধাক্কা দেয়, আর চাবিওয়ালা
 * ব্যবহারকারী দিয়ে — কারণ চাবি ছাড়া প্রত্যাখ্যান তো স্বাভাবিক, আসল
 * প্রশ্ন হলো চাবি **থাকা সত্ত্বেও** থামে কি না।
 */
final class TheDraftWentLiveWithoutAnyoneSigningTest extends TestCase
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
    }

    private function aDraft(?int $createdBy = null): Promotion
    {
        $offer = new Promotion([
            'name_en' => 'Draft offer',
            'name_bn' => 'খসড়া অফার',
            'starts_on' => Carbon::today(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-D-0001';
        $offer->type = PromotionType::BUY_X_GET_Y;
        $offer->status = PromotionStatus::DRAFT;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $createdBy ?? $this->owner->id;
        $offer->save();

        return $offer;
    }

    private function approvalSwitch(bool $on): void
    {
        $settings = app(SettingsService::class);
        $settings->set('promotion.needs_approval', $on);
        $settings->flush();
    }

    /**
     * ⛔ চাবি থাকলেও খসড়া সোজা চালু হয় না — অনুমোদনের সুইচ চালু থাকলে।
     *
     * ⚠️ বিপজ্জনক ইনপুট: `promotion.activate` চাবিওয়ালা মানুষ, খসড়া
     * অফার, সরাসরি চালুর অনুরোধ।
     */
    public function test_even_with_the_key_a_draft_cannot_go_live(): void
    {
        $this->approvalSwitch(true);
        $offer = $this->aDraft();

        $this->actingAs($this->owner)
            ->post(route('promotion.activate', $offer))
            ->assertSessionHasErrors('status');

        $this->assertSame(PromotionStatus::DRAFT, $offer->fresh()->status,
            'খসড়াটা সই ছাড়াই চালু হয়ে গেছে।');
    }

    /**
     * ⛔ নিজের অফার নিজে অনুমোদন নয়।
     *
     * ⓘ অনুমোদনের পুরো মানে *"দ্বিতীয় একজন দেখেছেন"*। ⚠️ যিনি বানালেন
     * তিনিই সই দিলে কাগজে দুই সই, আসলে একজন।
     */
    public function test_nobody_approves_their_own_offer(): void
    {
        $this->approvalSwitch(true);
        $offer = $this->aDraft();

        $this->actingAs($this->owner)->post(route('promotion.submit', $offer));
        $this->assertSame(PromotionStatus::SUBMITTED, $offer->fresh()->status, 'জমা দেওয়াই গেল না।');

        $this->actingAs($this->owner)
            ->post(route('promotion.approve', $offer))
            ->assertSessionHasErrors('status');

        $this->assertSame(PromotionStatus::SUBMITTED, $offer->fresh()->status,
            'যিনি বানালেন তিনিই অনুমোদন দিয়ে দিলেন।');
    }

    /**
     * ⭐ অনুমোদনের সুইচ বন্ধ থাকলে খসড়া চালু হয় — আর ইতিহাস সৎ থাকে।
     *
     * ── ⚠️ পর্যালোচনার ২ নম্বর ধরা ──────────────────────────────────
     * ⓘ সুইচ আর মানচিত্র একে অপরকে কাটত: সুইচ বন্ধ করলেও মানচিত্র পথটা
     * আটকে রাখত, আর সুইচটা মিথ্যা বলত। ⭐ এখন দরজাটা নিজেই পুরো পথ
     * হাঁটে, আর `approved_by`-তে যিনি চালু করলেন তাঁর নাম বসে।
     */
    public function test_with_approval_switched_off_a_draft_walks_the_whole_path(): void
    {
        $this->approvalSwitch(false);
        $offer = $this->aDraft();

        $this->actingAs($this->owner)
            ->post(route('promotion.activate', $offer))
            ->assertSessionHasNoErrors();

        $fresh = $offer->fresh();

        $this->assertSame(PromotionStatus::ACTIVE, $fresh->status,
            'সুইচ বন্ধ, তবু খসড়াটা চালু হলো না — সুইচটা মিথ্যা বলছে।');

        $this->assertSame($this->owner->id, (int) $fresh->approved_by,
            'চালু হলো, কিন্তু কে চালু করলেন তা লেখা নেই — ইতিহাসে ফাঁক।');
    }

    /**
     * ⛔ যে ধরন ইঞ্জিন চেনে না, সেটা ফর্ম বদলে পাঠালেও জন্মায় না।
     *
     * ⚠️ পর্দার ছাঁকনি একমাত্র পাহারা হলে কেউ ফর্ম বদলে *"loyalty"*
     * পাঠাতেন, অফারটা তৈরি হত, *"চলছে"* দেখাত, আর বিলে কিছুই করত না।
     */
    public function test_a_type_the_engine_does_not_know_is_refused_at_the_door(): void
    {
        $this->actingAs($this->owner)
            ->post(route('promotion.store'), [
                'name_en' => 'Loyalty',
                'name_bn' => 'লয়্যালটি',
                'type' => PromotionType::LOYALTY->value,
                'starts_on' => Carbon::today()->toDateString(),
                'ends_on' => Carbon::today()->addWeek()->toDateString(),
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, Promotion::query()->where('name_en', 'Loyalty')->count());
    }

    /**
     * ⛔ শেষ তারিখ শুরুর আগে নয় — স্পেক §২০।
     *
     * ⚠️ ছাড়া এমন অফার বসত যেটা **কোনোদিন** খাটে না, আর তালিকা *"চলছে"*
     * বলত।
     */
    public function test_an_offer_that_ends_before_it_starts_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('promotion.store'), [
                'name_en' => 'Backwards',
                'name_bn' => 'উল্টো',
                'type' => PromotionType::BUY_X_GET_Y->value,
                'starts_on' => Carbon::today()->addWeek()->toDateString(),
                'ends_on' => Carbon::today()->toDateString(),
            ])
            ->assertSessionHasErrors('ends_on');
    }

    /**
     * ⭐ মেয়াদ কমানো যায় — মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর।
     *
     * ⓘ *"komate hole komabe"*। ⛔ আগের প্রস্তাবে কেবল সামনে সরানো
     * যেত; মালিক দুই দিকই চেয়েছেন।
     */
    public function test_a_running_offer_can_be_cut_short(): void
    {
        $offer = $this->aDraft();
        $offer->status = PromotionStatus::ACTIVE;
        $offer->save();

        $shorter = Carbon::today()->addDays(2)->toDateString();

        $this->actingAs($this->owner)
            ->post(route('promotion.reschedule', $offer), ['ends_on' => $shorter])
            ->assertSessionHasNoErrors();

        $this->assertSame($shorter, $offer->fresh()->ends_on->toDateString());
    }

    /** ⛔ বাতিল অফারের তারিখ বদলানো যায় না — ফেরাতে হলে নতুন অফার। */
    public function test_a_cancelled_offer_cannot_be_rescheduled(): void
    {
        $offer = $this->aDraft();
        $offer->status = PromotionStatus::CANCELLED;
        $offer->save();

        $this->actingAs($this->owner)
            ->post(route('promotion.reschedule', $offer), ['ends_on' => Carbon::today()->addMonth()->toDateString()])
            ->assertSessionHasErrors('ends_on');
    }
}
