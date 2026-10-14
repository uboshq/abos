<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Services\PromotionLifecycle;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বাতিল অফার পুরনো ট্যাব থেকে ফিরে এল — Promotion লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ [[PromotionLifecycle::moveTo()]] হাতের কপির `status` দেখে সিদ্ধান্ত নিত। ⛔ দুই ট্যাবে একই অফার খোলা:
 * একটায় বাতিল, অন্যটার হাতে তখনো *"চলছে"* — সেখান থেকে "থামাও" বাতিল অফারকে থামানো করত, আর থামানো
 * থেকে আবার চালু করা যেত। ⚠️ বিপজ্জনক ইনপুট: পুরনো কপি, চূড়ান্ত অবস্থার উপর।
 */
final class ACancelledOfferCameBackFromAStaleTabTest extends TestCase
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

    private function aRunningOffer(): Promotion
    {
        $offer = new Promotion([
            'name_en' => 'Running offer',
            'name_bn' => 'চলতি অফার',
            'starts_on' => Carbon::today(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-ST-0001';
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        return $offer->fresh();
    }

    public function test_a_stale_copy_cannot_pause_a_cancelled_offer(): void
    {
        $offer = $this->aRunningOffer();

        /* ⓘ দ্বিতীয় ট্যাবের কপি — বাতিলের আগে পড়া */
        $staleTab = Promotion::query()->findOrFail($offer->id);

        app(PromotionLifecycle::class)->cancel(Promotion::query()->findOrFail($offer->id));

        try {
            app(PromotionLifecycle::class)->pause($staleTab);
            $this->fail('পুরনো কপি থেকে বাতিল অফার থামানো গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(PromotionStatus::CANCELLED, $offer->fresh()->status,
            'বাতিল অফার আর বাতিল নেই — চূড়ান্ত অবস্থা ভেঙেছে।');
    }

    public function test_a_stale_copy_cannot_reschedule_a_cancelled_offer(): void
    {
        $offer = $this->aRunningOffer();
        $staleTab = Promotion::query()->findOrFail($offer->id);

        app(PromotionLifecycle::class)->cancel(Promotion::query()->findOrFail($offer->id));

        $before = $offer->fresh()->ends_on->toDateString();

        try {
            app(PromotionLifecycle::class)->reschedule($staleTab, Carbon::today()->addMonth()->toDateString());
            $this->fail('পুরনো কপি থেকে বাতিল অফারের মেয়াদ বদলানো গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ends_on', $e->errors());
        }

        $this->assertSame($before, $offer->fresh()->ends_on->toDateString());
    }

    /** ⓘ উল্টো দিক — ঠিক অবস্থায় দরজাটা আগের মতোই খোলে */
    public function test_a_fresh_running_offer_still_pauses(): void
    {
        $offer = $this->aRunningOffer();

        app(PromotionLifecycle::class)->pause($offer);

        $this->assertSame(PromotionStatus::PAUSED, $offer->fresh()->status);
    }
}
