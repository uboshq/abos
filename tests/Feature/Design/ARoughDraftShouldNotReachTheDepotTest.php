<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Services\LookSkinService;
use App\Core\Support\CompanyContext;
use App\Core\Support\LookPreview;
use App\Core\Support\LookRegistry;
use App\Models\Company;
use App\Models\LookSkin;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * অর্ধেক লেখা একটা রূপ ডিপোর পর্দায় পৌঁছায় না — থিম ইঞ্জিনের ধাপ ৩।
 *
 * ── ধাপ ২-এর পর যা ভাঙা ছিল ─────────────────────────────────────────
 * একটা রূপ মানে একটা সারি, আর সম্পাদনা মানে ওই সারিটাই বদলে ফেলা।
 * ফলে কেউ রং নিয়ে বসলে **সেই মুহূর্তেই** গোটা ডিপো তাঁর অর্ধেক কাজ
 * দেখতে শুরু করত — আর আগেরটা কী ছিল তা কোথাও না থাকায় ফেরার পথও ছিল না।
 *
 * ── এই ফাইলের সবচেয়ে জরুরি পরীক্ষা ──────────────────────────────────
 * প্রথমটা: **সংরক্ষণ করলে কারো পর্দা বদলায় না**।
 *
 * ওটাই গোটা ধাপ ৩-এর ভিত। ওটা না থাকলে প্রিভিউ, সংস্করণ ও ফেরা —
 * তিনটারই কোনো মানে থাকে না, কারণ ভুলটা ততক্ষণে সবার পর্দায় পৌঁছে গেছে।
 */
class ARoughDraftShouldNotReachTheDepotTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private LookSkinService $looks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->looks = app(LookSkinService::class);
    }

    private function skin(array $light = ['--color-brand-500' => '#1d4ed8'], string $parent = 'navy'): LookSkin
    {
        return LookSkin::query()->create([
            'company_id' => $this->company->id,
            'name' => 'পরীক্ষার রূপ '.uniqid(),
            'parent' => $parent,
            'tokens' => ['light' => $light, 'dark' => []],
            'created_by' => $this->owner->id,
        ]);
    }

    /**
     * সংরক্ষণ করলে কারো পর্দা বদলায় না।
     *
     * এটাই ধাপ ৩-এর ভিত। প্রকাশিত সংস্করণটাই পর্দায় থাকে, আর খসড়া
     * যত খুশি বদলাক — সবাই আগেরটাই দেখেন।
     */
    public function test_saving_a_draft_changes_nobody_screen(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#1d4ed8']);
        $this->looks->publish($skin, 'প্রথমটা', $this->owner->id);

        // খসড়ায় নতুন একটা রং — এখনো প্রকাশ হয়নি
        $skin->update(['tokens' => ['light' => ['--color-brand-500' => '#dc2626'], 'dark' => []]]);

        $seen = LookRegistry::forUser($skin->public_id, 'light');

        $this->assertSame('#1d4ed8', $seen['--color-brand-500'],
            'খসড়ার রংটা সবার পর্দায় পৌঁছে গেছে — প্রকাশের আগেই।');

        $this->assertTrue($skin->fresh()->hasUnpublishedChanges(),
            'অপ্রকাশিত বদল আছে, অথচ ব্যাজটা উঠবে না।');
    }

    /** প্রকাশ করলে তবেই সবাই দেখেন, আর সংস্করণ একটা করে বাড়ে। */
    public function test_publishing_is_what_everyone_sees(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#1d4ed8']);

        $first = $this->looks->publish($skin, 'প্রথমটা', $this->owner->id);
        $this->assertSame(1, $first->version);

        $skin->update(['tokens' => ['light' => ['--color-brand-500' => '#dc2626'], 'dark' => []]]);
        $second = $this->looks->publish($skin->fresh(), 'লাল করা হলো', $this->owner->id);

        $this->assertSame(2, $second->version);

        $this->assertSame(
            '#dc2626',
            LookRegistry::forUser($skin->public_id, 'light')['--color-brand-500'],
        );

        $this->assertFalse($skin->fresh()->hasUnpublishedChanges());
    }

    /**
     * ফেরা মানে মুছে ফেলা নয় — ইতিহাসের উপরে আরেকটা সারি।
     *
     * ── কেন এটা আলাদা করে মাপা হয় ────────────────────────────────────
     * সহজ পথটা হত পুরনো সারিতে ফিরে গিয়ে পরেরগুলো মুছে দেওয়া। কিন্তু
     * ফেরাটাও একটা ভুল হতে পারে, আর তখন ফেরার-ফেরাটাও লাগে। মুছে
     * ফেললে দ্বিতীয়বার আর কিছু করার থাকত না।
     */
    public function test_going_back_adds_to_history_instead_of_erasing_it(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#1d4ed8']);
        $one = $this->looks->publish($skin, 'নীল', $this->owner->id);

        $skin->update(['tokens' => ['light' => ['--color-brand-500' => '#dc2626'], 'dark' => []]]);
        $this->looks->publish($skin->fresh(), 'লাল', $this->owner->id);

        $three = $this->looks->revert($skin->fresh(), $one, $this->owner->id);

        $this->assertSame(3, $three->version, 'ফেরা নতুন একটা সংস্করণ বসায়নি।');
        $this->assertSame(1, $three->reverted_from, 'কোথা থেকে ফেরা, সেটা লেখা হয়নি।');
        $this->assertSame(3, $skin->versions()->count(), 'ইতিহাস মুছে গেছে।');

        $this->assertSame(
            '#1d4ed8',
            LookRegistry::forUser($skin->public_id, 'light')['--color-brand-500'],
            'ফেরার পরেও পুরনো রংটা পর্দায় আসেনি।',
        );

        // খসড়াও ফিরেছে — নাহলে সম্পাদনার পর্দা খুললে ভুল রূপটাই দেখা যেত
        $this->assertSame(
            '#1d4ed8',
            $skin->fresh()->ownTokens('light')['--color-brand-500'],
        );
    }

    /**
     * পড়া যায় না এমন রূপ প্রকাশ হয় না, আর কোনো সংস্করণও বসে না।
     *
     * দ্বিতীয় অংশটাই আসল: গেট আটকানোর পরেও যদি সারিটা বসে যেত, তবে
     * ইতিহাসে এমন একটা সংস্করণ থাকত যেটা কেউ কোনোদিন দেখেনি — আর
     * পরে কেউ ওটাতেই "ফিরে" যেতেন।
     */
    public function test_an_unreadable_look_is_refused_and_leaves_no_version(): void
    {
        $skin = $this->skin([
            '--color-ink' => '#f0f0f0',
            '--color-surface-app' => '#ffffff',
        ]);

        try {
            $this->looks->publish($skin, null, $this->owner->id);
            $this->fail('ফিকে লেখার রূপটা প্রকাশ হয়ে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tokens', $e->errors());
        }

        $this->assertSame(0, $skin->versions()->count(), 'আটকানোর পরেও সংস্করণ বসেছে।');
        $this->assertNull($skin->fresh()->published_at);
    }

    /**
     * যা প্রকাশ হয়নি তার উপর দাঁড়ানো রূপ প্রকাশ হয় না।
     *
     * সন্তান কেবল নিজের বদলগুলো রাখে; বাকিটা পূর্বপুরুষ থেকে নামে।
     * পূর্বপুরুষ খসড়া হলে সন্তান প্রকাশ করা মানে এমন কিছু সবার পর্দায়
     * পাঠানো যা গেটও পাশ করেনি।
     */
    public function test_a_look_cannot_stand_on_something_unpublished(): void
    {
        $base = $this->skin(['--color-brand-500' => '#1d4ed8']);

        /*
         * সন্তানের বদলটা একটা **মাপ**, রং নয় — ইচ্ছাকৃতভাবে।
         *
         * এই পরীক্ষাটা উত্তরাধিকারের নিয়ম মাপে, কনট্রাস্ট নয়। রং
         * বসালে গেট আগে আটকাত (হালকার মান গাঢ়েও নামে), আর তখন
         * পরীক্ষাটা সবুজ-লাল দুইটাই ভুল কারণে হত।
         */
        $child = $this->skin(['--radius-card' => '4px'], parent: $base->public_id);

        try {
            $this->looks->publish($child, null, $this->owner->id);
            $this->fail('খসড়ার উপর দাঁড়ানো রূপটা প্রকাশ হয়ে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parent', $e->errors());
        }

        // পূর্বপুরুষ প্রকাশ হলে সন্তানও পারে
        $this->looks->publish($base, null, $this->owner->id);

        $this->assertSame(1, $this->looks->publish($child->fresh(), null, $this->owner->id)->version);
    }

    /**
     * প্রকাশিত সন্তান পূর্বপুরুষের **অপ্রকাশিত** কাজ টেনে আনে না।
     *
     * ── কেন এটা সহজেই ভুল হয় ─────────────────────────────────────────
     * চেইন ধরে হাঁটার সময় স্বাভাবিক লেখাটা হয় "পূর্বপুরুষের টোকেন
     * নাও"। কিন্তু পূর্বপুরুষেরও একটা খসড়া আছে, আর সেটা নিলে একজনের
     * অসমাপ্ত কাজ অন্যজনের প্রকাশের সাথে নীরবে সবার পর্দায় চলে যেত।
     */
    public function test_a_published_child_does_not_carry_its_parent_draft(): void
    {
        $base = $this->skin(['--radius-card' => '12px']);
        $this->looks->publish($base, null, $this->owner->id);

        $child = $this->skin(['--radius-field' => '2px'], parent: $base->public_id);
        $this->looks->publish($child, null, $this->owner->id);

        // পূর্বপুরুষের খসড়ায় নতুন কিছু — প্রকাশ করা হয়নি
        $base->update(['tokens' => ['light' => ['--radius-card' => '99px'], 'dark' => []]]);

        $seen = LookRegistry::forUser($child->public_id, 'light');

        $this->assertSame('12px', $seen['--radius-card'],
            'পূর্বপুরুষের অপ্রকাশিত কাজটা সন্তানের মাধ্যমে পর্দায় পৌঁছেছে।');
    }

    /**
     * প্রিভিউ কেবল নিজের সেশনে, আর খসড়াটাই দেখায়।
     *
     * খসড়া দেখানোটাই প্রিভিউয়ের গোটা কারণ — প্রকাশিতটা দেখতে প্রিভিউ
     * লাগে না, সেটা তো এমনিতেই পর্দায়।
     */
    public function test_a_preview_shows_the_draft_and_only_to_me(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#1d4ed8']);
        $this->looks->publish($skin, null, $this->owner->id);

        $skin->update(['tokens' => ['light' => ['--color-brand-500' => '#dc2626'], 'dark' => []]]);

        // এক · বোতামটা সত্যিই প্রিভিউ চালু করে
        $this->actingAs($this->owner)
            ->post(route('system_admin.look.preview', $skin))
            ->assertRedirect()
            ->assertSessionHas(LookPreview::KEY);

        /*
         * দুই · চলমান প্রিভিউ নিয়ে পাতা খুললে খসড়াটাই দেখা যায়।
         *
         * ── কেন সেশনটা হাতে সাজাতে হয় ────────────────────────────────
         * পরীক্ষায় সেশন-ড্রাইভার `array`, তাই উপরের POST-এ লেখা সেশন
         * পরের অনুরোধে পৌঁছায় না — অ্যাপে পৌঁছায়, পরীক্ষায় নয়।
         *
         * তাই দুইটা অর্ধেক আলাদা করে মাপা: বোতামটা লেখে কি না, আর
         * লেখা থাকলে পাতাটা মানে কি না।
         */
        $this->actingAs($this->owner)
            ->withSession([LookPreview::KEY => [
                'skin' => $skin->public_id,
                'until' => now()->addMinutes(10)->getTimestamp(),
            ]])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('--color-brand-500:#dc2626', false)
            ->assertSee(__('core.look.preview_stop'), false);

        /*
         * তিন · অন্য কারো পর্দায় কিছুই বদলায়নি।
         *
         * তাঁর সেশনে প্রিভিউ নেই, অথচ তিনি ওই একই রূপটাই পরে আছেন —
         * তাই তিনি **প্রকাশিতটা** দেখেন। এটাই প্রমাণ করে প্রিভিউ
         * ব্যবহারকারীর সারিতে লেখা হয়নি; লিখলে এই দাবিটা ভাঙত।
         */
        /*
         * `withSession()` কেবল পরের অনুরোধের জন্য নয় — ওটা এই
         * পরীক্ষার **বাকি সব** অনুরোধে থেকে যায়। না মুছলে নিচের
         * ব্যবহারকারীও প্রথমজনের প্রিভিউ নিয়ে পাতা খুলতেন, আর
         * পরীক্ষাটা ঠিক সেই জিনিসটাই মাপত যেটা সে অস্বীকার করতে
         * চাইছে। বাস্তবে এটা অন্য একটা ব্রাউজার, অন্য একটা সেশন।
         */
        $this->flushSession();

        $other = User::query()->where('email', '!=', 'owner@abos.test')->firstOrFail();
        $other->forceFill(['ui' => $skin->public_id])->save();

        $this->actingAs($other)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('--color-brand-500:#1d4ed8', false)
            ->assertDontSee(__('core.look.preview_stop'), false);
    }

    /**
     * ভুলে যাওয়া প্রিভিউ নিজে থেকেই থামে।
     *
     * মানুষ প্রিভিউ চালু করে অন্য কাজে চলে যান। সীমা না থাকলে তিন দিন
     * পরে তিনি ভাবতেন ERP-র রংটাই বদলে গেছে, আর সেটা নিয়ে অভিযোগ
     * করতেন — অথচ কেবল তাঁরই পর্দা।
     */
    public function test_a_forgotten_preview_stops_by_itself(): void
    {
        $skin = $this->skin();

        session()->put(LookPreview::KEY, [
            'skin' => $skin->public_id,
            'until' => now()->addMinutes(LookPreview::MINUTES)->getTimestamp(),
        ]);

        $this->assertNotNull(LookPreview::skin());

        Carbon::setTestNow(now()->addMinutes(LookPreview::MINUTES + 1));

        $this->assertNull(LookPreview::skin(), 'মেয়াদ শেষেও প্রিভিউ চলছে।');

        // মেয়াদ শেষ হলে সেশন থেকে মুছেও যায়, নাহলে প্রতিটা পাতায়
        // একই হিসাব বারবার হত
        $this->assertFalse(session()->has(LookPreview::KEY));

        Carbon::setTestNow();
    }

    /**
     * উত্তরাধিকারে পাওয়া ফিকে কালির জন্য কোম্পানি আটকায় না।
     *
     * ── কেন এই নিয়মটা লাগল ───────────────────────────────────────────
     * নকলের দাম হিসেবে আজ বারোটা জোড়া AA-র নিচে — Odoo-র ফিকে কালি
     * (#8A7F90, ৩.৮:১), Redwood-এর ধূসর, Fiori-র সতর্ক-ব্যাজ। মালিকের
     * সিদ্ধান্ত: আসল পণ্যের মতোই রাখা।
     *
     * প্রথম লেখায় গেট পুরো সেটটা মাপত, আর তাতে **Odoo, Redwood ও
     * Fiori-র উপর দাঁড়ানো কোনো রূপ কোনোদিন প্রকাশ হত না** — দশটার
     * তিনটাই বন্ধ, অথচ কোম্পানি ওই টোকেনগুলো ছোঁয়নি পর্যন্ত।
     *
     * ধরা পড়েছে এই ফাইলেরই আরেকটা পরীক্ষায়, যে Odoo-র উপর একটা রূপ
     * বানাতে গিয়েছিল সম্পূর্ণ অন্য কারণে।
     */
    public function test_faintness_that_came_with_the_parent_is_not_the_company_fault(): void
    {
        // `apps` = Odoo, যার নিজের ফিকে কালি AA-র নিচে
        $skin = $this->skin(['--color-brand-500' => '#1d4ed8'], parent: 'apps');

        $this->assertSame([], $skin->complaints(),
            'উত্তরাধিকারে পাওয়া ফিকে কালির জন্য কোম্পানির রূপ আটকে যাচ্ছে।');

        $this->assertSame(1, $this->looks->publish($skin, null, $this->owner->id)->version);
    }

    /**
     * কিন্তু আরও ফিকে করলে আটকায়।
     *
     * নিয়মটা "কনট্রাস্ট মাপা হয় না" নয় — নিয়মটা **"খারাপ করা যাবে
     * না"**। এই পরীক্ষাটা না থাকলে উপরেরটা পাশ করানোর সহজতম উপায় হত
     * গেটটাই বন্ধ করে দেওয়া, আর কেউ টের পেত না।
     */
    public function test_but_making_it_worse_is_still_refused(): void
    {
        $skin = $this->skin([
            '--color-ink' => '#f0f0f0',
            '--color-surface-app' => '#ffffff',
        ], parent: 'apps');

        $this->assertNotSame([], $skin->complaints(),
            'কোম্পানির নিজের বসানো ফিকে জোড়াটা পার হয়ে যাচ্ছে।');
    }

    /**
     * বানানো রূপটা সত্যিই পরা যায়।
     *
     * ── কেন এটা আলাদা করে মাপতে হয় ───────────────────────────────────
     * ধাপ ৩ শেষ করার সময় Control Panel-এ রূপ বানানো যেত, কিন্তু
     * "চেহারা" পর্দায় ছিল কেবল দশটা কোড-রূপ। অর্থাৎ একটা কোম্পানি
     * নিজের রূপ বানাতে পারত আর **কেউ সেটা পরতে পারত না**।
     *
     * প্রতিটা টুকরা কাজ করত, আর তবু ফিচারটা কাজ করত না — কারণ শেষ
     * ধাপটা কোথাও লেখা ছিল না। এই পরীক্ষাটা ওই ধাপটাই ধরে।
     */
    public function test_a_published_look_can_actually_be_worn(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#1d4ed8']);
        $this->looks->publish($skin, null, $this->owner->id);

        $this->actingAs($this->owner)
            ->get(route('appearance'))
            ->assertOk()
            ->assertSee($skin->name);

        $this->actingAs($this->owner)->post(route('appearance.save'), [
            'accent' => 'blue',
            'theme' => 'light',
            'ui' => $skin->public_id,
            'locale' => 'bn',
        ])->assertRedirect();

        $this->assertSame($skin->public_id, $this->owner->fresh()->ui);
    }

    /**
     * খসড়া রূপ বাছাই করাই যায় না।
     *
     * প্রকাশের গেটটা তখনই একমাত্র দরজা, যখন তাকে পাশ কাটানোর দ্বিতীয়
     * কোনো পথ নেই। বাছাইয়ের ঘরে খসড়ার id মেনে নিলে গেটটা কেবল একটা
     * বোতামের নিয়ম হয়ে থাকত।
     */
    public function test_a_draft_cannot_be_chosen_on_the_appearance_screen(): void
    {
        $draft = $this->skin(['--color-brand-500' => '#1d4ed8']);
        $before = $this->owner->ui;

        $this->actingAs($this->owner)->post(route('appearance.save'), [
            'accent' => 'blue',
            'theme' => 'light',
            'ui' => $draft->public_id,
            'locale' => 'bn',
        ])->assertSessionHasErrors('ui');

        $this->assertSame($before, $this->owner->fresh()->ui);
    }

    /**
     * খোলসটা গোড়ার রূপের — কেবল রংটা নয়।
     *
     * ── কেন এটা সহজেই ভুল হয় ─────────────────────────────────────────
     * রং আসে টোকেন থেকে, কিন্তু মেনু বাঁয়ে না উপরে সেটা **markup**,
     * আর সেটা ঠিক হয় কোড-রূপের নাম দেখে। `ui` ঘরে একটা `public_id`
     * থাকলে পুরনো লেখাটা ওই নামটা খুঁজে না পেয়ে ডিফল্টে নামত।
     *
     * ফল: Odoo-র উপর দাঁড়ানো একটা রূপ Odoo-র রং নিয়ে Navy-র খোলসে
     * বসত। রংটা ঠিক দেখে মানুষ ধরে নিতেন নকলটা কাজ করছে, আর গড়নটা
     * কেন মেলে না তা কেউ ব্যাখ্যা করতে পারত না।
     */
    public function test_the_shell_follows_the_look_it_stands_on(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#1d4ed8'], parent: 'apps');
        $this->looks->publish($skin, null, $this->owner->id);

        $this->owner->forceFill(['ui' => $skin->public_id])->save();

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-ui="apps"', false);
    }

    /** প্রিভিউতেও খোলসটা প্রিভিউ করা রূপের — খসড়া হলেও। */
    public function test_a_preview_wears_the_whole_shell_not_just_the_colours(): void
    {
        $draft = $this->skin(['--color-brand-500' => '#1d4ed8'], parent: 'apps');

        $this->actingAs($this->owner)
            ->withSession([LookPreview::KEY => [
                'skin' => $draft->public_id,
                'until' => now()->addMinutes(10)->getTimestamp(),
            ]])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-ui="apps"', false);
    }

    /** পর্দাগুলো খোলে, আর অনুমতি ছাড়া খোলে না। */
    public function test_the_screens_open_only_for_someone_allowed(): void
    {
        $skin = $this->skin();

        $this->actingAs($this->owner)
            ->get(route('system_admin.look.index'))
            ->assertOk()
            ->assertSee($skin->name);

        $this->actingAs($this->owner)
            ->get(route('system_admin.look.edit', $skin))
            ->assertOk()
            ->assertSee('--color-brand-500', false);

        $plain = User::query()->where('email', '!=', 'owner@abos.test')->firstOrFail();

        $this->actingAs($plain)
            ->get(route('system_admin.look.index'))
            ->assertForbidden();
    }
}
