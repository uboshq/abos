<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Promotion\Models\LoyaltyEntry;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Services\LoyaltyLedger;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\LoyaltyKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * একটা বিলের পয়েন্ট দুইবার জমা পড়ত — স্পেক §৭-ঠ ও §১৩।
 *
 * ── ⚠️ কী ঘটতে পারত ────────────────────────────────────────────────
 * ⓘ পয়েন্ট আসলে টাকার দায়। ⛔ দুইবার জমা, ব্যালান্সের বেশি খরচ, দুইবার
 * ফেরত, ফুরোনো পয়েন্টে কেনা, বা অন্য ক্রেতার পয়েন্ট গোনা — প্রতিটাই
 * নীরব, আর প্রতিটাই টাকা বের করে দেয়।
 *
 * ⓘ ব্যালান্স মাপা হয় [[LoyaltyLedger::balance()]] থেকে, আর সারির সংখ্যা
 * সরাসরি খাতার টেবিল থেকে — সেবার নিজের কথা বিশ্বাস করে নয়।
 */
final class ThePointsWereEarnedTwiceForOneBillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Promotion $offer;

    private int $buyer;

    private int $stranger;

    private LoyaltyLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $ids = Customer::query()->orderBy('id')->limit(2)->pluck('id');
        $this->assertCount(2, $ids, 'দুইজন ক্রেতা না থাকলে "অন্যের পয়েন্ট" দাবিটা কিছুই মাপে না।');
        [$this->buyer, $this->stranger] = [(int) $ids[0], (int) $ids[1]];

        $offer = new Promotion([
            'name_en' => 'Points for every bill',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addMonth(),
        ]);
        $offer->code = 'PROM-L-0001';
        $offer->type = PromotionType::LOYALTY;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::ADDS;
        $offer->created_by = $this->owner->id;
        $offer->save();
        $this->offer = $offer;

        $this->ledger = app(LoyaltyLedger::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** ⓘ একটা বিলে বসানো পয়েন্টের অফার — বিক্রয়ের দরজা যা লিখত। */
    private function applied(int $billId, string $points = '100', ?int $customerId = null, BenefitKind $kind = BenefitKind::POINTS): PromotionApplication
    {
        return PromotionApplication::query()->create([
            'promotion_id' => $this->offer->id,
            'source_type' => 'sales_invoice',
            'source_id' => $billId,
            'customer_id' => $customerId ?? $this->buyer,
            'benefit_kind' => $kind,
            'benefit_amount' => $points,
            'worth' => $points,
        ]);
    }

    private function assertPoints(string $expected, int $customerId, ?Carbon $at = null, string $why = ''): void
    {
        $actual = $this->ledger->balance($customerId, $at);

        $this->assertSame(0, bccomp($actual, $expected, 4),
            ($why !== '' ? $why.' ' : '')."ব্যালান্স {$expected} হওয়ার কথা, পাওয়া গেল {$actual}।");
    }

    /** @param  callable(): mixed  $act */
    private function assertRefused(callable $act, string $field, string $why): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), $why);

            return;
        }

        $this->fail($why);
    }

    /**
     * ⛔ একই বসানো অফারে দুইবার জমা ডাকলে একটাই সারি।
     *
     * ⚠️ বিপজ্জনক ইনপুট: একই `PromotionApplication` দুইবার — বিলের সংরক্ষণ
     * দুইবার চাপা। ⓘ ধরা না পড়লে ক্রেতা ২০০ পয়েন্ট পেতেন, আর খরচও করতেন।
     */
    public function test_earning_twice_for_one_bill_writes_one_entry(): void
    {
        $applied = $this->applied(7501);

        $first = $this->ledger->earn($applied);
        $second = $this->ledger->earn($applied);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id, 'দ্বিতীয় ডাকে নতুন সারি ফিরেছে — পয়েন্ট দুইবার জমেছে।');

        $this->assertSame(1, LoyaltyEntry::query()
            ->where('promotion_application_id', $applied->id)
            ->where('kind', LoyaltyKind::EARN->value)
            ->count(), 'একটা বসানো অফারে একাধিক অর্জনের সারি।');

        $this->assertPoints('100', $this->buyer, why: 'দুইবার ডাকার পরে');
    }

    /** ⭐ পাল্টা-দাবি: পয়েন্ট নয় এমন সুবিধা বা বাতিল বিলে কিছুই জমে না। */
    public function test_only_a_live_points_benefit_earns(): void
    {
        $this->assertNull($this->ledger->earn($this->applied(7502, '5', kind: BenefitKind::PERCENT)),
            'শতকরা ছাড়ের অফার পয়েন্ট জমিয়েছে।');

        $cancelled = $this->applied(7503);
        $cancelled->reversed_at = now();
        $cancelled->save();

        $this->assertNull($this->ledger->earn($cancelled), 'বাতিল বিলের পয়েন্ট জমেছে।');
        $this->assertPoints('0', $this->buyer);
    }

    /**
     * ⛔ ব্যালান্সের বেশি খরচ থামে — সমান চলে।
     *
     * ⚠️ বিপজ্জনক ইনপুট: ১০০ পয়েন্টে ১০০.০০০১ — সীমানার ঠিক এক ঘর উপরে।
     * ⓘ `>=` লিখলে সমানটাও থামত, `>`-এর জায়গায় কিছু না লিখলে বেশিটাও যেত।
     */
    public function test_spending_more_than_the_balance_is_refused_and_equal_is_allowed(): void
    {
        $this->ledger->earn($this->applied(7511));

        $this->assertRefused(
            fn () => $this->ledger->redeem($this->buyer, '100.0001', 'sales_invoice', 8511),
            'points', 'ব্যালান্সের বেশি পয়েন্ট খরচ হয়ে গেছে।');

        $this->assertPoints('100', $this->buyer, why: 'থামানো খরচের পরে');

        $this->ledger->redeem($this->buyer, '100', 'sales_invoice', 8512);
        $this->assertPoints('0', $this->buyer, why: 'পুরো ব্যালান্স খরচের পরে');

        $this->assertRefused(
            fn () => $this->ledger->redeem($this->buyer, '0', 'sales_invoice', 8513),
            'points', 'শূন্য পয়েন্টের খরচ চলে গেছে।');
    }

    /** ⛔ একই বিলে দুইবার খরচ নয় — ব্যালান্স থাকলেও। */
    public function test_the_same_bill_does_not_spend_twice(): void
    {
        $this->ledger->earn($this->applied(7521, '300'));

        $this->ledger->redeem($this->buyer, '50', 'sales_invoice', 8521);

        $this->assertRefused(
            fn () => $this->ledger->redeem($this->buyer, '50', 'sales_invoice', 8521),
            'points', 'একই বিলে দ্বিতীয়বার পয়েন্ট কেটেছে।');

        $this->assertPoints('250', $this->buyer);
    }

    /**
     * ⛔ ফেরত একবারই — খরচের ফেরত পয়েন্ট ফেরায়, অর্জনের ফেরত কেড়ে নেয়।
     *
     * ⚠️ বিপজ্জনক ইনপুট: একই বিলের ফেরত দুইবার। ⓘ ধরা না পড়লে খরচের
     * ৪০ পয়েন্ট দুইবার ফিরত — ভুয়া পয়েন্ট।
     */
    public function test_a_reversal_restores_exactly_once(): void
    {
        $this->ledger->earn($this->applied(7531));
        $this->ledger->redeem($this->buyer, '40', 'sales_invoice', 8531);
        $this->assertPoints('60', $this->buyer);

        $this->assertSame(1, $this->ledger->reverse('sales_invoice', 8531));
        $this->assertPoints('100', $this->buyer, why: 'খরচের বিল বাতিলের পরে');

        $this->assertSame(0, $this->ledger->reverse('sales_invoice', 8531), 'দ্বিতীয় ফেরতে সারি লেখা হয়েছে।');
        $this->assertPoints('100', $this->buyer, why: 'দ্বিতীয় ফেরতের পরে');

        $this->assertSame(1, $this->ledger->reverse('sales_invoice', 7531));
        $this->assertPoints('0', $this->buyer, why: 'অর্জনের বিল বাতিলের পরে');

        $this->assertSame(0, $this->ledger->reverse('sales_invoice', 7531));
        $this->assertPoints('0', $this->buyer, why: 'অর্জনের দ্বিতীয় ফেরতের পরে');

        $this->assertSame(2, LoyaltyEntry::query()->where('kind', LoyaltyKind::REVERSE->value)->count(),
            'দুইটা কাগজ, দুইটা ফেরতের সারি — এর বেশি নয়।');
    }

    /**
     * ⛔ ফুরোনো পয়েন্ট ব্যালান্সে নেই — রাতের কাজ চলুক বা না চলুক।
     *
     * ⚠️ বিপজ্জনক ইনপুট: মেয়াদের পরের দিন, মেয়াদ-শেষের সারি লেখার **আগে**
     * খরচের চেষ্টা। ⓘ সরল `SUM` এখানে ১০০ বলত।
     */
    public function test_expired_points_are_not_in_the_balance(): void
    {
        $today = Carbon::parse('2026-10-01 10:00:00');
        Carbon::setTestNow($today);

        $this->ledger->earn($this->applied(7541), validDays: 30);

        $this->assertPoints('100', $this->buyer, $today->copy()->addDays(30), 'মেয়াদের শেষ দিনে');
        $this->assertPoints('0', $this->buyer, $today->copy()->addDays(31), 'মেয়াদের পরের দিন');

        Carbon::setTestNow($today->copy()->addDays(31));

        $this->assertRefused(
            fn () => $this->ledger->redeem($this->buyer, '1', 'sales_invoice', 8541),
            'points', 'ফুরোনো পয়েন্ট খরচ হয়ে গেছে।');

        $this->assertSame(1, $this->ledger->expireDue(), 'মেয়াদ-শেষের সারি লেখা হয়নি।');
        $this->assertSame(0, $this->ledger->expireDue(), 'মেয়াদ-শেষ দুইবার লেখা হয়েছে।');

        $this->assertPoints('0', $this->buyer, why: 'মেয়াদ-শেষ লেখার পরে (দুইবার বিয়োগ হলে ঋণাত্মক হত)');

        /* ⓘ ফুরোনো বিলের বাতিল — ফুরোনো অংশ আবার কাটে না */
        $this->ledger->reverse('sales_invoice', 7541);
        $this->assertPoints('0', $this->buyer, why: 'ফুরোনো অর্জনের বিল বাতিলের পরে');
    }

    /** ⭐ পাল্টা-দাবি: মেয়াদের আগে খরচ চলে, আর আগে-ফুরোবে-যে সেটা থেকেই কাটে। */
    public function test_the_soonest_to_expire_points_are_spent_first(): void
    {
        $today = Carbon::parse('2026-10-01 10:00:00');
        Carbon::setTestNow($today);

        $this->ledger->earn($this->applied(7551, '100'), validDays: 0);
        $this->ledger->earn($this->applied(7552, '100'), validDays: 10);

        $this->ledger->redeem($this->buyer, '100', 'sales_invoice', 8551);

        /* ⓘ দশ দিনের লটটাই খরচ হয়েছে, তাই মেয়াদের পরেও চিরস্থায়ী ১০০ থাকে */
        $this->assertPoints('100', $this->buyer, $today->copy()->addDays(20), 'মেয়াদ পেরোনোর পরে');
    }

    /**
     * ⛔ অন্য ক্রেতার পয়েন্ট কখনো গোনা হয় না।
     *
     * ⚠️ বিপজ্জনক ইনপুট: ক্রেতা ক-এর ১০০ পয়েন্ট থাকতে ক্রেতা খ-এর নামে
     * ১ পয়েন্ট খরচ। ⓘ `customer_id` ছাড়া যোগ করলে খ-ও ১০০ দেখাত।
     */
    public function test_another_customers_points_never_count(): void
    {
        $this->ledger->earn($this->applied(7561));

        $this->assertPoints('0', $this->stranger, why: 'যিনি কিছুই পাননি');

        $this->assertRefused(
            fn () => $this->ledger->redeem($this->stranger, '1', 'sales_invoice', 8561),
            'points', 'অন্য ক্রেতার পয়েন্টে খরচ হয়ে গেছে।');

        $this->assertPoints('100', $this->buyer, why: 'অন্যের থামানো খরচের পরে');
    }

    /** ⛔ খাতার সারি বদলানো বা মোছা যায় না — সেবা এড়িয়ে গেলেও। */
    public function test_a_ledger_entry_is_never_edited_or_deleted(): void
    {
        $entry = $this->ledger->earn($this->applied(7571));
        $this->assertNotNull($entry);

        try {
            $entry->points = '999';
            $entry->save();
            $this->fail('খাতার সারি বদলানো গেছে।');
        } catch (LogicException) {
        }

        try {
            $entry->refresh()->delete();
            $this->fail('খাতার সারি মোছা গেছে।');
        } catch (LogicException) {
        }

        $this->assertPoints('100', $this->buyer);
    }
}
