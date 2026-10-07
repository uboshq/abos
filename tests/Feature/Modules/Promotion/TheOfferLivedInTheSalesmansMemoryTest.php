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
 * অফারটা থাকত বিক্রয়কর্মীর মনে।
 *
 * ── ⭐ মালিকের স্পেক, ২৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"Trade Promotion & Incentive Management"* — আলাদা মডিউল, আর হিসাবটা
 * কখনো বিক্রয়ের পর্দার ভিতরে নয় (ধারা ২২)।
 *
 * ── ⓘ এই ফাইল আজ যা পাহারা দেয় ─────────────────────────────────────
 * মডিউলের প্রথম ইট: অফারের কাগজটা সত্যিই আছে, তালিকায় দেখা যায়, আর
 * অবস্থার চক্রটা অনুমোদনের ধাপ এড়াতে দেয় না।
 *
 * ⚠️ ইঞ্জিন এখনো নেই — কোনো বিলে কিছু বসে না। ⓘ তাই এখানে সেই দাবি
 * করাও হয়নি: যে দাবি কিছু মাপে না, সে সবুজ থেকে মিথ্যা বলে।
 */
final class TheOfferLivedInTheSalesmansMemoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /*
         * ⭐ একজন যাঁর `promotion.view` চাবিটা নেই।
         *
         * ── ⚠️ কেন এটা বাধ্যতামূলক ──────────────────────────────────
         * ⓘ বাকি প্রতিটা দাবি চলে মালিকের চাবি দিয়ে, যাঁর সব অধিকার
         * আছে। ⛔ তাই দরজার তালাটা খুলে দিলেও ঐ দাবিগুলো সবুজই থাকত —
         * একটা অরক্ষিত পর্দা আর একটা ঠিকঠাক পর্দা দেখতে হুবহু এক।
         */
        $this->stranger = User::factory()->create(['is_active' => true]);
        $this->stranger->companies()->attach($company->id, ['is_active' => true]);
        $this->stranger->switchCompany($company->id);
    }

    private function anOffer(array $overrides = []): Promotion
    {
        /*
         * ⚠️ সুরক্ষিত ঘরগুলো `new Promotion()`-এ যায় না — আর এটা মেপে শেখা।
         *
         * ⓘ প্রথম রানে দুইটা দাবি লাল হয়েছিল: *"Add fillable property
         * [code]"*। ⛔ `code`, `type`, `status`, `combines` ইচ্ছা করে
         * `fillable`-এর বাইরে, আর এখানকার Laravel চুপচাপ ফেলে না দিয়ে
         * থামিয়ে দেয় — ⭐ যেটা ভালো, কারণ নীরবে বাদ পড়লে দাবিটা ভুল
         * কোড নিয়ে সবুজ হতে পারত।
         */
        $fillable = array_diff_key($overrides, array_flip(['code', 'type', 'status', 'combines']));

        $offer = new Promotion(array_merge([
            'name_en' => 'Eid mega trade offer',
            'name_bn' => 'ঈদের বড় অফার',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
            'priority' => 10,
        ], $fillable));

        /*
         * ⚠️ `type` আর `status` হাতে বসানো, `fill()` দিয়ে নয়।
         *
         * ⓘ দুইটাই ইচ্ছাকৃতভাবে `fillable`-এর বাইরে — নাহলে একটা সাধারণ
         * সম্পাদনার অনুরোধেই কেউ খসড়া অফারকে সোজা `active` করে দিতে
         * পারতেন, আর অনুমোদনের গোটা ধাপটা নীরবে এড়ানো যেত।
         */
        $offer->code = $overrides['code'] ?? 'PROM-2026-000001';
        $offer->type = $overrides['type'] ?? PromotionType::BUY_X_GET_Y;
        $offer->status = $overrides['status'] ?? PromotionStatus::ACTIVE;
        $offer->combines = $overrides['combines'] ?? PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        return $offer;
    }

    /** ⭐ কাগজটা আছে, আর তালিকায় দেখা যায়। */
    public function test_an_offer_can_be_written_down_and_then_found(): void
    {
        $offer = $this->anOffer();

        $this->actingAs($this->owner)
            ->get(route('promotion.index'))
            ->assertOk()
            ->assertSee('PROM-2026-000001')
            ->assertSee($offer->name_bn);
    }

    /**
     * ⛔ অবস্থার মানচিত্রে খসড়া থেকে সোজা চালু হওয়ার পথ নেই।
     *
     * ── ⚠️ নামটা ইচ্ছাকৃতভাবে সীমিত, আর কারণটা মেপে শেখা ─────────────
     * ⓘ প্রথম খসড়ায় দাবিটার নাম ছিল *"খসড়া সোজা চালু হতে পারে না"* —
     * আর ওটা **মিথ্যা বলত**। ⛔ দাবিটা কেবল enum-এর মানচিত্র পড়ে; কোনো
     * দরজা ধাক্কা দিয়ে দেখে না। ⚠️ আরও খারাপ: এই ফাইলের নিজের
     * `anOffer()`-ই অবস্থাটা সরাসরি বসায়, অর্থাৎ যে পথ নিষেধ সেটা দিয়েই
     * ঢোকা হয়, আর দাবিটা তবু সবুজ থাকে।
     *
     * ⓘ যেন তালাটা টেনে না দেখে কেবল দেয়ালে সাঁটা *"তালা লাগানো আছে"*
     * নোটিশটা পড়া।
     *
     * ⭐ তাই নামটা এখন যা মাপে ঠিক তাই বলে। ▶️ আসল দরজার দাবিটা আসবে
     * [[PromotionLifecycle]]-এর সাথে: `promotion.activate` চাবিওয়ালা
     * ব্যবহারকারী, খসড়া অফার, HTTP অনুরোধ → প্রত্যাখ্যাত।
     */
    public function test_the_state_map_forbids_a_draft_going_straight_to_running(): void
    {
        $this->assertFalse(
            PromotionStatus::DRAFT->canBecome(PromotionStatus::ACTIVE),
            'খসড়া সোজা চালু হয়ে যাচ্ছে — অনুমোদনের ধাপটা তাহলে সাজসজ্জা।',
        );

        $this->assertTrue(PromotionStatus::DRAFT->canBecome(PromotionStatus::SUBMITTED));
        $this->assertTrue(PromotionStatus::APPROVED->canBecome(PromotionStatus::ACTIVE));
    }

    /**
     * ⛔ চাবি ছাড়া তালিকার পর্দা খোলে না।
     *
     * ── ⚠️ কেন এই পাল্টা-দাবিটা ছাড়া উপরেরটা কিছুই প্রমাণ করে না ─────
     * ⓘ উপরের দাবিটা চলে মালিকের চাবি দিয়ে, যাঁর সব অধিকার আছে। ⛔ তাই
     * কন্ট্রোলার থেকে `can:promotion.view` তুলে দিলেও ওটা সবুজই থাকত।
     * ⚠️ একটা অরক্ষিত পর্দা আর একটা ঠিকঠাক পর্দা — পরীক্ষার চোখে হুবহু এক।
     *
     * ⓘ অফারের তালিকা ব্যবসার খবর: কাকে কত ছাড় দেওয়া হচ্ছে, কোন
     * ক্রেতা কী পাচ্ছেন। ⛔ ওটা সবার দেখার জিনিস নয়।
     */
    public function test_without_the_key_the_list_does_not_open(): void
    {
        $this->actingAs($this->stranger)
            ->get(route('promotion.index'))
            ->assertForbidden();
    }

    /**
     * ⛔ মেয়াদ শেষ হলে আর ফেরা নেই।
     *
     * ⚠️ ফেরা গেলে একটা পুরনো অফার তারিখ বদলে আবার চালু করা যেত, আর যে
     * বিলগুলো ওটার অধীনে কাটা হয়েছিল তারা হঠাৎ অন্য নিয়মের অধীনে পড়ত।
     */
    public function test_an_expired_offer_has_nowhere_left_to_go(): void
    {
        $this->assertTrue(PromotionStatus::EXPIRED->isFinal());
        $this->assertTrue(PromotionStatus::CANCELLED->isFinal());

        /* ⭐ পাল্টা-দাবি: চলতি অবস্থাগুলোর পথ আছে — নাহলে "সবই চূড়ান্ত" লিখেও সবুজ হত */
        $this->assertFalse(PromotionStatus::ACTIVE->isFinal());
        $this->assertFalse(PromotionStatus::DRAFT->isFinal());
    }

    /**
     * ⭐ তারিখটাই শেষ কথা, অবস্থাটা নয়।
     *
     * ── ⚠️ কেন দুইটা প্রশ্ন, একটা নয় ───────────────────────────────
     * ⓘ কেবল অবস্থা দেখলে মেয়াদ পেরোনো অফার খাটত যতক্ষণ না কেউ
     * `expired` লেখে — আর ঐ লেখাটা একটা নির্ধারিত কাজের উপর দাঁড়াত,
     * যে কাজ একদিন চলতে ভুলে যায়। ⛔ তখন অফারটা নীরবে চলতেই থাকত।
     */
    public function test_the_dates_have_the_last_word_not_the_status(): void
    {
        $lapsed = $this->anOffer([
            'code' => 'PROM-2026-000002',
            'starts_on' => Carbon::today()->subMonth(),
            'ends_on' => Carbon::today()->subDay(),
        ]);

        $this->assertSame(PromotionStatus::ACTIVE, $lapsed->status,
            'দৃশ্যটাই বানানো যায়নি — অবস্থাটা চলতি হওয়া দরকার ছিল।');

        $this->assertFalse($lapsed->isLiveOn(Carbon::today()),
            'তারিখ পেরিয়ে গেছে, তবু অফারটা খাটছে।');

        $this->assertTrue($lapsed->hasLapsed());

        /* ⓘ আর SQL-এর নিয়মটাও একই — দুই জায়গায় দুই নিয়ম হতে দেওয়া যায় না */
        $this->assertSame(0, Promotion::query()->liveOn(Carbon::today())
            ->whereKey($lapsed->id)->count());
    }

    /**
     * ⛔ *"সন্ধ্যা ৬টায় শেষ"* মানে সন্ধ্যা ৬টায় শেষ, রাত ১২টায় নয়।
     *
     * ── ⚠️ প্রথম খসড়ায় ঘর দুইটা ছিল, অথচ কেউ পড়ত না ─────────────────
     * ⓘ `starts_at` আর `ends_at` টেবিলে বসানো হয়েছিল, পর্দায় দেখানোর
     * কথা ছিল, আর [[Promotion::isLiveOn()]] ওদের **ছুঁতও না**। ⛔ ফল:
     * ছয় ঘণ্টার বাড়তি ছাড় চলে যেত, আর কোথাও কিছু লাল হত না।
     *
     * ⚠️ যে ঘর দেখানো হয় অথচ মানা হয় না, সেটা না থাকার চেয়ে খারাপ —
     * মানুষ ওটা ভরেন, আর বিশ্বাস করেন ওটা কাজ করছে।
     */
    public function test_an_offer_that_ends_at_six_does_not_run_until_midnight(): void
    {
        $offer = $this->anOffer([
            'code' => 'PROM-2026-000003',
            'starts_on' => Carbon::today(),
            'ends_on' => Carbon::today(),
            'starts_at' => '10:00:00',
            'ends_at' => '18:00:00',
        ]);

        $this->assertTrue($offer->isLiveOn(Carbon::today()->setTime(12, 0)),
            'দুপুরেই অফারটা খাটছে না — দৃশ্যটাই বানানো যায়নি।');

        $this->assertFalse($offer->isLiveOn(Carbon::today()->setTime(20, 0)),
            'সন্ধ্যা ৬টায় শেষ, তবু রাত ৮টায় অফারটা খাটছে।');

        $this->assertFalse($offer->isLiveOn(Carbon::today()->setTime(9, 0)),
            'সকাল ১০টায় শুরু, তবু ৯টায় অফারটা খাটছে।');

        /* ⓘ আর SQL-এর নিয়মটাও একই — দুই জায়গায় দুই নিয়ম হতে দেওয়া যায় না */
        $this->assertSame(1, Promotion::query()
            ->liveOn(Carbon::today()->setTime(12, 0))->whereKey($offer->id)->count());

        $this->assertSame(0, Promotion::query()
            ->liveOn(Carbon::today()->setTime(20, 0))->whereKey($offer->id)->count());
    }

    /**
     * ⭐ একসাথে খাটার নিয়মটা **অফারই বলে** — মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর।
     *
     * ── ⛔ আগে দুইটা ঘর ছিল, আর ওরা একসাথে মিথ্যা বলতে পারত ───────────
     * ⓘ `is_exclusive` আর `is_stackable` দুইটাই `true` বসানো যেত। ⚠️ তখন
     * অফারটা একই সাথে *"একা"* আর *"সবার সাথে"* — আর কোনটা জিতবে তা
     * নির্ভর করত ইঞ্জিন কোন শর্তটা আগে পড়ে তার উপর।
     *
     * ⭐ এখন একটা ঘর, তিনটা মান — স্ববিরোধী জোড়টা **লেখাই যায় না**।
     */
    public function test_the_offer_itself_says_how_it_combines(): void
    {
        /* ⛔ একজন "একা চলব" বললেই যথেষ্ট — অন্যজন যাই বলুক */
        $this->assertFalse(PromotionCombines::ALONE->sitsWith(PromotionCombines::ADDS));
        $this->assertFalse(PromotionCombines::ADDS->sitsWith(PromotionCombines::ALONE));

        /* ⓘ দুইজনেই "যোগ হব" বললে তবেই যোগ হয় */
        $this->assertTrue(PromotionCombines::ADDS->sitsWith(PromotionCombines::ADDS));

        /* ⛔ "সবচেয়ে ভালোটা" মানে "আমাকে কারও সাথে যোগ কোরো না" */
        $this->assertFalse(PromotionCombines::BEST->sitsWith(PromotionCombines::ADDS));
        $this->assertFalse(PromotionCombines::BEST->sitsWith(PromotionCombines::BEST));
    }

    /**
     * ⛔ যে ধরন ইঞ্জিন চেনে না, সেটা বাছতে দেওয়া হয় না।
     *
     * ── ⚠️ কেন এটা আলাদা করে পাহারা দেওয়া ───────────────────────────
     * ⓘ স্পেকে বারোটা ধরন, ইঞ্জিন আজ তিনটা চেনে। ⛔ বাকি ন'টা বাছতে
     * দিলে অফারটা তৈরি হত, তালিকায় বসত, *"চলছে"* দেখাত — আর বিলে
     * **কিছুই করত না**। ⚠️ কোথাও লাল হত না, অথচ ক্রেতা তাঁর প্রাপ্য
     * ছাড়টা পেতেন না।
     */
    public function test_only_the_types_the_engine_knows_are_offered(): void
    {
        $built = PromotionType::built();

        $this->assertNotEmpty($built, 'একটাও ধরন চালু নেই — তাহলে মডিউলটা কিছুই করতে পারে না।');

        $this->assertContains(PromotionType::BUY_X_GET_Y, $built);

        $this->assertNotContains(PromotionType::LOYALTY, $built,
            'লয়্যালটি পয়েন্ট বাছা যাচ্ছে, অথচ ইঞ্জিন ওটা চেনে না।');

        foreach ($built as $type) {
            $this->assertTrue($type->isBuilt());
        }
    }
}
