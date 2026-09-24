<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\NoticeAudience;
use App\Core\Services\NoticeBoard;
use App\Core\Services\StatusNotices;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notice;
use App\Models\NoticeRead;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * মালিকের নিজের কথা বলার কোনো জায়গা ছিল না।
 *
 * ── ⓘ কী ছিল, আর কী ছিল না ───────────────────────────────────────────
 * নিচের চলন্ত বারটা আগে থেকেই ছিল, কিন্তু ওতে বসত কেবল **যন্ত্রের কথা**
 * — ব্যাকআপ বাসি, খসড়া পড়ে আছে। ⚠️ *"কাল দোকান বন্ধ থাকবে"* বলার পথ
 * ছিল না, তাই ওটা হত হোয়াটসঅ্যাপে, আর যিনি ঐ দলে নেই তিনি জানতেন না।
 *
 * ── ⛔ এই ফাইলের আসল পাহারা ──────────────────────────────────────────
 * *"নোটিশ দেখা যায়"* দাবিটা সহজ। ⚠️ আসল ঝুঁকিগুলো নীরব:
 *
 *   ১. যাঁর ভূমিকা মেলে না, তিনি যেন **না** দেখেন
 *   ২. ভূমিকা না বাছলে **সবাই** দেখেন (উল্টোটা হলে নোটিশ কোথাও পৌঁছাত না)
 *   ৩. মেয়াদ শেষ হলে নিজে থেকেই সরে যায়
 *   ৪. বারে যায় কেবল যেগুলোয় টিক দেওয়া
 *
 * ⓘ (২) সবচেয়ে সহজে উল্টে যায়, আর উল্টে গেলে লেখক ভাবতেন পাঠানো
 * হয়েছে — অথচ কেউ পেত না।
 */
final class TheOwnerHadNoWayToTellEveryoneSomethingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $salesman;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
    }

    // ── ১ ও ২ · কে দেখবেন ─────────────────────────────────────────────

    public function test_a_notice_with_no_roles_reaches_everyone(): void
    {
        $notice = $this->aNotice('সবার জন্য');

        $this->assertTrue($this->seenBy($this->owner, $notice));
        $this->assertTrue($this->seenBy($this->salesman, $notice),
            'ভূমিকা বাছা হয়নি, তবু বিক্রয়কর্মী নোটিশটা পাচ্ছেন না — '
            .'অর্থাৎ ভুলে ভূমিকা না বসালে নোটিশ কোথাও পৌঁছাত না।');
    }

    public function test_a_notice_for_one_role_skips_the_others(): void
    {
        $notice = $this->aNotice('কেবল বিক্রয়ের জন্য', roles: ['salesman']);

        $this->assertTrue($this->seenBy($this->salesman, $notice));

        $this->assertFalse($this->seenBy($this->owner, $notice),
            'যাঁর ভূমিকা বাছা হয়নি তিনিও নোটিশটা দেখছেন — তাহলে বাছাইয়ের কোনো মানে নেই।');
    }

    // ── ৩ · মেয়াদ ─────────────────────────────────────────────────────

    public function test_a_notice_leaves_on_its_own_when_the_date_passes(): void
    {
        $notice = $this->aNotice('কাল দোকান বন্ধ', ends: Carbon::today()->subDay());

        $this->assertFalse($this->seenBy($this->owner, $notice),
            'মেয়াদ পেরোনো নোটিশ এখনো দেখা যাচ্ছে — দুই সপ্তাহে মানুষ বারটাই পড়া বন্ধ করবেন।');
    }

    public function test_a_notice_waits_for_its_start(): void
    {
        $notice = $this->aNotice('আগামী সোমবার', starts: Carbon::today()->addDay());

        $this->assertFalse($this->seenBy($this->owner, $notice));
    }

    /** ⛔ তারিখ না বসালে সীমা নেই — নাহলে নোটিশটা কোনোদিনই দেখা যেত না। */
    public function test_a_notice_with_no_dates_is_always_on(): void
    {
        $notice = $this->aNotice('সবসময়');

        $this->assertTrue($this->seenBy($this->owner, $notice));
    }

    public function test_a_switched_off_notice_is_gone(): void
    {
        $notice = $this->aNotice('আর দরকার নেই');
        $notice->update(['is_active' => false]);

        $this->assertFalse($this->seenBy($this->owner, $notice));
    }

    // ── ৪ · চলন্ত বার ─────────────────────────────────────────────────

    /**
     * ⛔ বারে যায় কেবল টিক দেওয়াগুলো।
     *
     * ⚠️ সবগুলো পাঠালে জরুরি কথাটা রোজকার কথার ভিড়ে হারাত, আর বারটা
     * আবার সেই "কেউ পড়ে না" অবস্থায় ফিরত।
     */
    public function test_only_a_ticked_notice_rides_the_ticker(): void
    {
        $quiet = $this->aNotice('বোর্ডেই থাক');
        $loud = $this->aNotice('সবাইকে জানাও', ticker: true);

        $board = app(NoticeBoard::class);

        $riding = $board->forTicker($this->owner)->pluck('id')->all();

        $this->assertContains($loud->id, $riding);
        $this->assertNotContains($quiet->id, $riding,
            'টিক ছাড়া নোটিশও বারে উঠেছে — তাহলে টিকটার কোনো মানে নেই।');
    }

    /** ⭐ আর বারটা সত্যিই সেটা দেখায় — সেবার তালিকা নয়, পর্দার বার। */
    public function test_the_ticker_really_carries_it(): void
    {
        $this->aNotice('কাল দোকান বন্ধ থাকবে', ticker: true);

        $this->actingAs($this->owner);
        Cache::flush();

        $texts = array_column(app(StatusNotices::class)->all(), 'text');

        $this->assertContains('কাল দোকান বন্ধ থাকবে', $texts,
            'নোটিশটা নিচের বারে পৌঁছায়নি — মালিক লিখলেন, কেউ দেখল না।');
    }

    // ── পড়ার হিসাব ────────────────────────────────────────────────────

    /**
     * ⭐ কে পড়েছেন — আর দ্বিতীয়বার খুললেও প্রথমবারের সময়টাই থাকে।
     *
     * ⚠️ প্রতিবার বসালে *"কবে প্রথম দেখেছিলেন"* প্রশ্নের উত্তর হারাত,
     * আর ঐটাই একমাত্র প্রশ্ন যার জন্য হিসাবটা রাখা।
     */
    public function test_reading_is_recorded_once(): void
    {
        $notice = $this->aNotice('পড়ুন');

        $this->actingAs($this->owner)
            ->get(route('system_admin.notice.show', $notice->id))
            ->assertOk();

        $first = NoticeRead::query()->where('notice_id', $notice->id)->firstOrFail();

        $this->travel(1)->minutes();

        $this->actingAs($this->owner)->get(route('system_admin.notice.show', $notice->id));

        $this->assertSame(1, NoticeRead::query()->where('notice_id', $notice->id)->count());
        $this->assertEquals($first->read_at, NoticeRead::query()
            ->where('notice_id', $notice->id)->firstOrFail()->read_at);
    }

    // ── পর্দা ও দরজা ──────────────────────────────────────────────────

    /**
     * ⛔ যে নোটিশ আপনার জন্য নয়, তার ঠিকানা জানলেও খোলে না।
     *
     * ⚠️ মেনু থেকে লুকানো আর দরজা বন্ধ করা এক নয় — আজ সকালেই এই ভুলটা
     * অন্য এক জায়গায় ধরা পড়েছে।
     */
    public function test_a_notice_that_is_not_yours_does_not_open_by_its_address(): void
    {
        $notice = $this->aNotice('কেবল হিসাবরক্ষকের', roles: ['accountant']);

        $this->actingAs($this->salesman)
            ->get(route('system_admin.notice.show', $notice->id))
            ->assertNotFound();
    }

    /** ⓘ প্রশাসক সবই দেখেন — মেয়াদ পেরোনোগুলোসহ, কারণ "কে পড়েছিল" তখনই জিজ্ঞাসা হয়। */
    public function test_the_writer_can_open_an_expired_notice(): void
    {
        $notice = $this->aNotice('পুরনো', ends: Carbon::today()->subWeek());

        $this->actingAs($this->owner)
            ->get(route('system_admin.notice.show', $notice->id))
            ->assertOk();
    }

    public function test_the_screen_writes_one(): void
    {
        $this->actingAs($this->owner)
            ->post(route('system_admin.notice.store'), [
                'title' => 'কাল দোকান বন্ধ',
                'body' => 'ঈদের ছুটি',
                'is_active' => '1',
                'in_ticker' => '1',

                /*
                 * ⚠️ অগ্রাধিকারটা এখানে **লাগে**, আর কারণটা মেপে শেখা।
                 *
                 * ⓘ [[NoticeLifecycle::draft()]] বারে যাওয়া ঠিক করে
                 * অগ্রাধিকার দেখে (`goesToTheBar()`), ফর্মের ঘরটা দেখে নয়।
                 * ⛔ অগ্রাধিকার না দিলে নোটিশটা `NORMAL` হয়, আর `NORMAL`
                 * বারে যায় না — তখন নিচের দাবিটা লাল হত, যদিও নিয়মটা
                 * ঠিকই কাজ করছে।
                 */
                'priority' => 'important',
                'roles' => ['salesman'],
            ])
            ->assertRedirect();

        $notice = Notice::query()->where('title', 'কাল দোকান বন্ধ')->firstOrFail();

        $this->assertTrue($notice->in_ticker);
        $this->assertSame(['salesman'], $notice->audience()->pluck('role')->all());
        $this->assertSame($this->owner->id, (int) $notice->created_by);
    }

    /**
     * ⛔ সাধারণ নোটিশ বারে ওঠে না, ঘরটা টিক দেওয়া থাকলেও।
     *
     * ── ⭐ মালিকের স্পেক, ধারা ৮ ──────────────────────────────────────
     * `LOW`/`NORMAL` সাধারণ নোটিফিকেশন; বারে যায় `IMPORTANT` থেকে।
     *
     * ── ⚠️ কেন দাবিটা আলাদা করে লাগে ────────────────────────────────
     * ⓘ উপরেরটা একা থাকলে *"ফর্মের ঘরটাই মেনে নাও"* লিখেও সবুজ পাওয়া
     * যেত। ⛔ আর তখন ছুটির খবর রোজ বারে থাকত, আর ভরা বার মানে না-পড়া
     * বার — ঠিক যে রোগটা এই নিয়মটা ঠেকাতে লেখা।
     */
    public function test_an_ordinary_notice_stays_off_the_bar(): void
    {
        $this->actingAs($this->owner)
            ->post(route('system_admin.notice.store'), [
                'title' => 'সাধারণ খবর',
                'body' => 'তেমন জরুরি নয়',
                'is_active' => '1',
                'in_ticker' => '1',
                'priority' => 'normal',
            ])
            ->assertRedirect();

        $this->assertFalse(
            Notice::query()->where('title', 'সাধারণ খবর')->firstOrFail()->in_ticker,
            'সাধারণ নোটিশটা বারে উঠে গেছে — ঘরটা টিক দেওয়া ছিল বলে।',
        );
    }

    /** ⛔ লেখার দরজা চাবির পিছনে — পড়ার দরজা নয়। */
    public function test_writing_needs_the_key(): void
    {
        $this->actingAs($this->salesman)
            ->get(route('system_admin.notice.create'))
            ->assertForbidden();

        $this->actingAs($this->salesman)
            ->get(route('system_admin.notice.index'))
            ->assertOk();
    }

    /**
     * ⛔ শেষ তারিখ শুরুর আগে নয়।
     *
     * ⚠️ ছাড়া এমন নোটিশ বসানো যেত যেটা **কোনোদিন** দেখা যায় না, আর
     * লেখক ভাবতেন পাঠানো হয়েছে।
     */
    public function test_a_notice_cannot_end_before_it_starts(): void
    {
        $this->actingAs($this->owner)
            ->post(route('system_admin.notice.store'), [
                'title' => 'উল্টো তারিখ',
                'starts_on' => Carbon::today()->format('Y-m-d'),
                'ends_on' => Carbon::today()->subWeek()->format('Y-m-d'),
            ])
            ->assertSessionHasErrors('ends_on');

        $this->assertSame(0, Notice::query()->where('title', 'উল্টো তারিখ')->count());
    }

    /**
     * ⛔ তিনটা পর্দাই সত্যিই আঁকা হয়।
     *
     * ⚠️ বাকি পরীক্ষাগুলো সেবা আর দরজা মাপে; ব্লেডগুলো একবারও রেন্ডার
     * হয় না। ⓘ একটা টাইপো, একটা না-থাকা অনুবাদের কী, একটা ভুল
     * কম্পোনেন্টের নাম — সব সবুজ থাকত, আর মালিক সাদা পাতা পেতেন।
     */
    public function test_all_three_screens_actually_draw(): void
    {
        $notice = $this->aNotice('পর্দা পরীক্ষা', roles: ['salesman']);

        $this->actingAs($this->owner);

        $list = $this->get(route('system_admin.notice.index'))->assertOk()->getContent();
        $this->assertTrue(str_contains($list, 'পর্দা পরীক্ষা'));

        $this->get(route('system_admin.notice.show', $notice->id))->assertOk();
        $this->get(route('system_admin.notice.create'))->assertOk();
        $this->get(route('system_admin.notice.edit', $notice->id))->assertOk();

        /*
         * ⓘ কর্মীর চোখেও তালিকাটা আঁকা হয় — ওখানে ছাঁচটা আলাদা
         * (`$mine`, পাতা-ভাগ ছাড়া), তাই আলাদা করে মাপা।
         */
        $this->actingAs($this->salesman)
            ->get(route('system_admin.notice.index'))
            ->assertOk();
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    /** @param  list<string>  $roles */
    private function aNotice(
        string $title,
        array $roles = [],
        ?Carbon $starts = null,
        ?Carbon $ends = null,
        bool $ticker = false,
    ): Notice {
        $notice = Notice::create([
            'title' => $title,
            'body' => $title,
            'starts_on' => $starts,
            'ends_on' => $ends,
            'is_active' => true,
            'in_ticker' => $ticker,
            'created_by' => $this->owner->id,
        ]);

        foreach ($roles as $role) {
            $this->assertNotNull(
                Role::query()->where('company_id', CompanyContext::id())->where('name', $role)->first(),
                "ভূমিকা '{$role}' এই কোম্পানিতে নেই — দৃশ্যটাই বানানো যাচ্ছে না।"
            );

            $notice->audience()->create(['role' => $role]);
        }

        /*
         * ⭐ নতুন ঘরেও বসানো — আর এটা মেপে শেখা, ২৫ সেপ্টেম্বর ২০২৬।
         *
         * ── ⛔ কী ধরা পড়েছিল ──────────────────────────────────────────
         * ⓘ এই সহায়কটা কেবল পুরনো `notice_roles`-এ লিখত, অথচ
         * [[NoticeBoard::queryFor()]] এখন পড়ে [[NoticeAudience]] দিয়ে,
         * নতুন `notice_audiences` ঘর থেকে।
         *
         * ⚠️ ফল ছিল নীরব আর উল্টো: নতুন ঘরে সারি না থাকা মানে *"কাউকে
         * বাছা হয়নি"*, অর্থাৎ *"সবাই দেখবেন"*। ⛔ তাই ভূমিকা বেছে দেওয়া
         * নোটিশ **সবার** কাছে পৌঁছাত, আর দাবিটা লাল হত এমন একটা কারণে
         * যা কোডে নেই — পরীক্ষাটার নিজের দরজায়।
         *
         * ⓘ আসল পর্দা দুই ঘরেই লেখে ([[NoticeController::setAudience()]]),
         * তাই এই সহায়কটাও তাই করে — নাহলে দাবিটা এমন একটা দৃশ্য মাপত
         * যা বাস্তবে কখনো তৈরি হয় না।
         */
        app(NoticeAudience::class)->aimAt(
            $notice,
            array_map(fn (string $role) => 'role:'.$role, $roles),
        );

        return $notice->fresh();
    }

    private function seenBy(User $user, Notice $notice): bool
    {
        return app(NoticeBoard::class)->forUser($user)->contains('id', $notice->id);
    }
}
