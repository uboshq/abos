<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

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
 * অফারের মেয়াদ শেষ, অথচ তালিকা বলত *"চলছে"* — স্পেক §১৮।
 *
 * ── ⭐ স্পেক ─────────────────────────────────────────────────────────
 * *"End Date/Time পার হলে promotion automatically Inactive/Expired হবে।"*
 *
 * ── ⚠️ কেন কমান্ডটা মাপা হয়, সেবাটা নয় ──────────────────────────────
 * ⓘ [[PromotionExpiry]]-র হিসাব আগেই মাপা
 * ([[TheBillWasCancelledAndTheGiftStayedGivenTest]])। ⛔ কিন্তু ঐ দাবি
 * কোম্পানির প্রসঙ্গ হাতে বসিয়ে চলে — আর নির্ধারিত কাজে প্রসঙ্গ বসায়
 * কমান্ড নিজে। ⚠️ কমান্ড প্রসঙ্গ না বসালে সেবাটা একটাও অফার পেত না, আর
 * সফল হয়েই ফিরত।
 *
 * ⭐ তাই দুই কোম্পানিতে দুইটা অফার: একটা কোম্পানিতে দাঁড়িয়ে চালালে
 * অন্যটা ফসকাত, আর সেটাই ধরা পড়ে।
 */
final class TheOfferEndedAndTheListStillSaidRunningTest extends TestCase
{
    use RefreshDatabase;

    private Company $depot;

    private Company $mart;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->depot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mart = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($this->depot->id, $this->depot->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /** একটা চালু অফার, যে কোম্পানিতে বলা হয় সেখানে। */
    private function offerIn(Company $company, string $code, Carbon $endsOn): Promotion
    {
        return CompanyContext::forCompany($company->id, function () use ($code, $endsOn) {
            $offer = new Promotion([
                'name_en' => 'Offer '.$code,
                'starts_on' => Carbon::today()->subMonth(),
                'ends_on' => $endsOn,
            ]);
            $offer->code = $code;
            $offer->type = PromotionType::QUANTITY_SLAB;
            $offer->status = PromotionStatus::ACTIVE;
            $offer->combines = PromotionCombines::BEST;
            $offer->created_by = $this->owner->id;
            $offer->save();

            return $offer;
        });
    }

    /* ⚠️ কোম্পানির ছাঁকনি ছাড়া পড়া — নাহলে অন্য কোম্পানির অফার "নেই" দেখাত, আর দাবিটা ভুল কারণে লাল হত */
    private function statusOf(Promotion $offer): PromotionStatus
    {
        return Promotion::query()->withoutGlobalScope('company')->findOrFail($offer->id)->status;
    }

    /**
     * ⭐ গতকাল শেষ হওয়া অফার দুই কোম্পানিতেই `EXPIRED` হয়।
     *
     * ⛔ কমান্ড যদি চলতি কোম্পানিতেই থেকে যেত, দোকানের অফারটা চিরকাল
     * *"চলছে"* দেখাত — আর কোনো লাল হত না।
     */
    public function test_a_lapsed_offer_expires_in_every_company(): void
    {
        $depotOffer = $this->offerIn($this->depot, 'PROM-X-0001', Carbon::yesterday());
        $martOffer = $this->offerIn($this->mart, 'PROM-X-0002', Carbon::yesterday());

        $this->artisan('promotion:expire')->assertSuccessful();

        $this->assertSame(PromotionStatus::EXPIRED, $this->statusOf($depotOffer),
            'চলতি কোম্পানির মেয়াদ-পেরোনো অফার EXPIRED হয়নি।');
        $this->assertSame(PromotionStatus::EXPIRED, $this->statusOf($martOffer),
            'অন্য কোম্পানির অফারটা ফসকেছে — কমান্ড কোম্পানি ধরে ধরে ঘোরেনি।');
    }

    /** ⭐ পাল্টা-দাবি: যে অফার এখনো চলছে তা ছোঁয়া হয় না — নাহলে "সব বন্ধ করো" লিখেও উপরেরটা সবুজ হত। */
    public function test_a_running_offer_is_left_alone(): void
    {
        $running = $this->offerIn($this->mart, 'PROM-X-0003', Carbon::today()->addWeek());

        $this->artisan('promotion:expire')->assertSuccessful();

        $this->assertSame(PromotionStatus::ACTIVE, $this->statusOf($running));
    }

    /**
     * ⚠️ কাজ শেষে প্রসঙ্গটা যেখানে ছিল সেখানেই ফেরে।
     *
     * ⓘ শিডিউলার এক প্রক্রিয়ায় কয়েকটা কমান্ড চালাতে পারে। ⛔ প্রসঙ্গ শেষ
     * কোম্পানিতে আটকে থাকলে পরের কাজটা ভুল কোম্পানির খাতায় লিখত।
     */
    public function test_the_company_context_is_given_back(): void
    {
        $this->offerIn($this->mart, 'PROM-X-0004', Carbon::yesterday());

        $this->artisan('promotion:expire')->assertSuccessful();

        $this->assertSame($this->depot->id, CompanyContext::id());
    }
}
