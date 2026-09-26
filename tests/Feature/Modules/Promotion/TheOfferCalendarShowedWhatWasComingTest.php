<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Services\PromotionCalendar;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * অফারের ক্যালেন্ডার — স্পেক §১৬।
 *
 * ── ⚠️ কী ঘটছিল ────────────────────────────────────────────────────
 * ⓘ অফারগুলো কেবল একটা তালিকায় ছিল। ⛔ কোন অফার আগামী সপ্তাহে শেষ,
 * কোনটা পরের মাসে আসছে — জানতে হলে প্রতিটা সারির তারিখ পড়ে মনে মনে
 * হিসাব করতে হত, আর শেষ দিনে কেউ টের পেতেন অফারটা ফুরিয়েছে।
 *
 * ⭐ শ্রেণিভাগের দাবিগুলো সরাসরি [[PromotionCalendar::classify()]]-এ —
 * পাতা যে নিয়মে আঁকে, ঠিক সেই নিয়মে। ⓘ চাবির দাবি **একই মানুষ** —
 * কেবল চাবিটা বদলায়।
 */
final class TheOfferCalendarShowedWhatWasComingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private int $serial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function offer(PromotionStatus $status, Carbon $from, Carbon $to, string $name = 'Calendar offer'): Promotion
    {
        $offer = new Promotion([
            'name_en' => $name,
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
        ]);
        $offer->code = sprintf('PROM-K-%04d', ++$this->serial);
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = $status;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        return $offer->fresh();
    }

    private function stateOf(Promotion $offer, Carbon $today): ?string
    {
        return app(PromotionCalendar::class)->classify($offer, $today);
    }

    /** ⭐ প্রতিটা অবস্থার একটা অফার — আর প্রতিটা নিজের রঙে। */
    public function test_each_offer_falls_into_its_own_state(): void
    {
        $today = Carbon::today();

        $this->assertSame(PromotionCalendar::ACTIVE, $this->stateOf(
            $this->offer(PromotionStatus::ACTIVE, $today->copy()->subDays(3), $today->copy()->addDays(30)), $today));

        $this->assertSame(PromotionCalendar::UPCOMING, $this->stateOf(
            $this->offer(PromotionStatus::APPROVED, $today->copy()->addDays(5), $today->copy()->addDays(20)), $today));

        $this->assertSame(PromotionCalendar::EXPIRING, $this->stateOf(
            $this->offer(PromotionStatus::ACTIVE, $today->copy()->subDays(3), $today->copy()->addDays(PromotionCalendar::SOON_DAYS)), $today));

        $this->assertSame(PromotionCalendar::EXPIRED, $this->stateOf(
            $this->offer(PromotionStatus::EXPIRED, $today->copy()->subDays(30), $today->copy()->subDays(10)), $today));

        /* ⛔ খসড়া কারও সই পায়নি — ক্যালেন্ডারে আসেই না */
        $this->assertNull($this->stateOf(
            $this->offer(PromotionStatus::DRAFT, $today->copy()->subDays(3), $today->copy()->addDays(30)), $today));
    }

    /**
     * ⛔ সীমানার দিন: আজ শেষ হওয়া অফার **এখনো চলছে** — "শেষ হতে চলেছে", "শেষ" নয়।
     *
     * ⚠️ পাল্টা-দাবি: গতকাল শেষ হওয়া অফার, অবস্থা এখনো `active` — ওটা "শেষ"।
     * ⓘ নাহলে *"সব কিছু শেষ হতে চলেছে"* লিখেও প্রথম দাবিটা সবুজ হত।
     * ⓘ আর সাত দিনের ঠিক পরের দিনটা আবার "চলছে" — সীমানার অন্য পাশ।
     */
    public function test_an_offer_ending_today_is_expiring_not_expired(): void
    {
        $today = Carbon::today();

        $this->assertSame(PromotionCalendar::EXPIRING, $this->stateOf(
            $this->offer(PromotionStatus::ACTIVE, $today->copy()->subDays(10), $today->copy()), $today),
            'আজ শেষ হওয়া অফারকে "শেষ" দেখানো হচ্ছে — অথচ আজও বিলে খাটে।');

        $this->assertSame(PromotionCalendar::EXPIRED, $this->stateOf(
            $this->offer(PromotionStatus::ACTIVE, $today->copy()->subDays(10), $today->copy()->subDay()), $today),
            'গতকাল শেষ হওয়া অফার এখনো চলছে বলে দেখাচ্ছে — অবস্থা লেখা বাকি বলে।');

        $this->assertSame(PromotionCalendar::ACTIVE, $this->stateOf(
            $this->offer(PromotionStatus::ACTIVE, $today->copy()->subDays(10), $today->copy()->addDays(PromotionCalendar::SOON_DAYS + 1)), $today));
    }

    /**
     * ⭐ পাতায় এই মাসের অফার আছে, অন্য মাসের নেই, খসড়া নেই।
     *
     * ⓘ অফারের পাতার লিংকটাও দেখা হয় — দাগটা কেবল রং নয়, দরজা।
     */
    public function test_the_page_shows_this_months_offers_and_nothing_else(): void
    {
        $today = Carbon::today();

        $here = $this->offer(PromotionStatus::ACTIVE, $today->copy(), $today->copy(), 'Ends today');
        $later = $this->offer(PromotionStatus::APPROVED,
            $today->copy()->startOfMonth()->addMonths(3), $today->copy()->startOfMonth()->addMonths(3)->addDays(5));
        $draft = $this->offer(PromotionStatus::DRAFT, $today->copy(), $today->copy());

        $this->actingAs($this->owner)
            ->get(route('promotion.calendar', ['month' => $today->format('Y-m')]))
            ->assertOk()
            ->assertSee($here->code)
            ->assertSee(route('promotion.show', $here), false)
            ->assertSee('data-state="'.PromotionCalendar::EXPIRING.'"', false)
            ->assertDontSee($later->code)
            ->assertDontSee($draft->code);

        /* ⓘ পাল্টা-দাবি: ঐ মাসে গেলে পরের অফারটা সত্যিই আছে — নাহলে উপরের "নেই" কিছুই প্রমাণ করত না */
        $this->actingAs($this->owner)
            ->get(route('promotion.calendar', ['month' => $later->starts_on->format('Y-m')]))
            ->assertOk()
            ->assertSee($later->code)
            ->assertDontSee($here->code);
    }

    /** ⓘ হাতে ভুল মাস লিখলে ৫০০ নয় — চলতি মাস। */
    public function test_a_garbled_month_opens_this_month(): void
    {
        $this->actingAs($this->owner)
            ->get(route('promotion.calendar', ['month' => '2026-13']))
            ->assertOk();
    }

    /** ⭐ একই মানুষ: `view` চাবি ছাড়া দরজা বন্ধ, চাবি পেলে খোলে। */
    public function test_the_same_person_needs_the_view_key(): void
    {
        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($clerk)
            ->get(route('promotion.calendar'))
            ->assertForbidden();

        $clerk->givePermissionTo(Permission::findOrCreate('promotion.view', 'web'));

        $this->actingAs($clerk->fresh())
            ->get(route('promotion.calendar'))
            ->assertOk();
    }
}
