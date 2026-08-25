<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Services\LookSkinService;
use App\Core\Support\CompanyContext;
use App\Core\Support\LookRegistry;
use App\Models\Company;
use App\Models\LookSkin;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কোম্পানির নিজের রূপ — উত্তরাধিকার ও স্তর, থিম ইঞ্জিনের ধাপ ২।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * দশটা রূপ, আর দশটাই আমাদের লেখা। একটা কোম্পানি নিজের রং চাইলে
 * একমাত্র উত্তর ছিল "কোডে বসিয়ে দেব" — অর্থাৎ প্রতিটা গ্রাহকের জন্য
 * একটা করে ডিপ্লয়, আর চিরকাল সেটা রক্ষণাবেক্ষণ।
 *
 * ── এই ফাইলের সবচেয়ে জরুরি পরীক্ষা ──────────────────────────────────
 * প্রথমটা: **যা বলা হয়নি তা পূর্বপুরুষ থেকে নামে**।
 *
 * ওটাই পুরো নকশার ভিত। কোম্পানি ছয়টা টোকেন বলে, বাকি চুয়ান্নটা Navy
 * থেকে আসে — তাই আমরা Navy-র একটা রং শোধরালে কোম্পানিরটাও শোধরায়।
 * পুরো সেট কপি করে রাখলে ওটা কোনোদিন হত না।
 */
class ACompanyWearsItsOwnColoursTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /**
     * Navy-র উপর দাঁড়ানো একটা স্কিন, কেবল দুইটা বদল নিয়ে।
     *
     * ── কেন সবগুলো খসড়া ─────────────────────────────────────────────
     * এই ফাইলের প্রায় প্রতিটা পরীক্ষা **উত্তরাধিকার** মাপে, প্রকাশ নয়:
     * সে জিজ্ঞেস করে `tokens()` চেইন ধরে কী নামায়। খসড়াতেই ওটার
     * উত্তর পাওয়া যায়।
     *
     * প্রথম লেখায় এখানে `published_at` **হাতে** বসানো হত। ধাপ ৩-এ
     * ওটা আর যথেষ্ট নয় — প্রকাশ মানে একটা সংস্করণের সারি, আর হাতে
     * বসানো তারিখটা তখন একটা মিথ্যা অবস্থা তৈরি করত: "প্রকাশিত",
     * অথচ দেখানোর মতো কিছুই নেই।
     *
     * তাই ফিক্সচারটা যা, সে তাই বলে। যেখানে সত্যিই প্রকাশ দরকার,
     * সেখানে সেবাটাই ডাকা হয় — গেট সমেত।
     */
    private function skin(array $light = [], string $parent = 'navy'): LookSkin
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
     * যা বলা হয়নি তা পূর্বপুরুষ থেকে নামে।
     *
     * এটাই ভিত্তি। ছয়টা বলে ষাটটা পাওয়া — নাহলে প্রতিটা কোম্পানির রূপ
     * একটা জমে থাকা কপি হয়ে যেত, আর মূল রূপের শোধরানো কোনোদিন পৌঁছাত না।
     */
    public function test_what_the_company_does_not_say_comes_down_from_its_parent(): void
    {
        $skin = $this->skin(['--color-surface-app' => '#101010']);

        $mine = $skin->tokens('light');
        $navy = LookRegistry::tokens('navy', 'light');

        $this->assertSame('#101010', $mine['--color-surface-app'], 'নিজের বদলটাই জেতেনি।');

        $this->assertSame(
            $navy['--color-surface-card'],
            $mine['--color-surface-card'],
            'যা বলা হয়নি সেটা Navy থেকে নামেনি — তাহলে উত্তরাধিকার বলে কিছু নেই।',
        );

        $this->assertGreaterThan(40, count($mine), 'ছয়টা বলে ষাটটা পাওয়া যাচ্ছে না।');
    }

    /**
     * সন্তান জেতে, পূর্বপুরুষ নয়।
     *
     * ক্রমটা উল্টো হলে "Navy + আমার নীল" মানে দাঁড়াত "Navy", আর মানুষ
     * ভাবতেন তাঁর সম্পাদনা সেভ হয়নি — সবচেয়ে বিভ্রান্তিকর ব্যর্থতা,
     * কারণ সেভ তো হয়েছেই।
     */
    public function test_the_child_wins(): void
    {
        $navy = LookRegistry::tokens('navy', 'light');

        $this->assertNotSame('#123456', $navy['--color-surface-card']);

        $skin = $this->skin(['--color-surface-card' => '#123456']);

        $this->assertSame('#123456', $skin->tokens('light')['--color-surface-card']);
    }

    /** স্কিনের উপর স্কিন — তিন স্তরের চেইনও মেলে। */
    public function test_a_skin_may_stand_on_another_skin(): void
    {
        $base = $this->skin(['--color-surface-app' => '#101010']);

        $child = $this->skin(['--color-ink' => '#eeeeee'], parent: $base->public_id);

        $tokens = $child->tokens('light');

        $this->assertSame('#eeeeee', $tokens['--color-ink'], 'নিজের বদল হারিয়েছে।');
        $this->assertSame('#101010', $tokens['--color-surface-app'], 'মাঝের স্তরটা হারিয়েছে।');
        $this->assertArrayHasKey('--color-surface-card', $tokens, 'গোড়ার Navy হারিয়েছে।');
    }

    /**
     * চক্রে পড়লে ঝুলে থাকে না।
     *
     * A দাঁড়ায় B-র উপর, B দাঁড়ায় A-র উপর — সম্পাদনার পর্দায় পূর্বপুরুষ
     * বদলাতে গিয়ে এটা ঘটতে পারে। সীমা না থাকলে পাতাটা চিরকাল ঘুরত, আর
     * লগে কিছুই থাকত না।
     */
    public function test_a_loop_does_not_hang_the_page(): void
    {
        $a = $this->skin(['--color-ink' => '#111111']);
        $b = $this->skin(['--color-ink-body' => '#222222'], parent: $a->public_id);

        $a->forceFill(['parent' => $b->public_id])->save();

        $tokens = $a->fresh()->tokens('light');

        $this->assertNotSame([], $tokens);
        $this->assertSame('#111111', $tokens['--color-ink']);
    }

    /**
     * খসড়া রূপ কারো পর্দায় যায় না।
     *
     * প্রকাশের আগে গেট পাশ করতে হয়। খসড়া দেখা গেলে গেটটার কোনো মানে
     * থাকত না — কেউ বানিয়ে রেখে দিলেই সেটা চলত।
     */
    public function test_a_draft_never_reaches_a_screen(): void
    {
        $skin = $this->skin(['--color-surface-app' => '#101010']);

        $this->assertNull(LookRegistry::skin($skin->public_id));

        $fell = LookRegistry::forUser($skin->public_id, 'light');

        $this->assertSame(
            LookRegistry::tokens('navy', 'light'),
            $fell,
            'খসড়া রূপে নেমে গেছে — ডিফল্টে ফেরার কথা।',
        );
    }

    /**
     * মুছে ফেলা বা অচেনা বাছাইয়ে পাতা ভাঙে না।
     *
     * একটা রঙের ভুলে কেউ কাজ থামাতে পারবে না — ব্যতিক্রম নয়, ডিফল্ট।
     */
    public function test_an_unknown_choice_falls_back_instead_of_breaking(): void
    {
        foreach ([null, 'no-such-skin-at-all', 'zzz'] as $chosen) {
            $tokens = LookRegistry::forUser($chosen, 'light');

            $this->assertNotSame([], $tokens, "'{$chosen}' বাছাইয়ে কিছুই ফেরেনি।");
        }
    }

    /**
     * পড়া যায় না এমন রূপ প্রকাশের আগেই আটকায়।
     *
     * স্কিমা আর গেট দুইটাই এক জায়গা থেকে ডাকা হয়, কারণ আলাদা করে
     * ডাকলে একদিন কেউ একটা ডাকতে ভুলত।
     */
    public function test_faint_or_misspelled_is_refused_before_it_is_published(): void
    {
        $faint = $this->skin([
            '--color-ink' => '#f0f0f0',
            '--color-surface-app' => '#ffffff',
        ]);

        $this->assertNotSame([], $faint->complaints(), 'ফিকে লেখা পার হয়ে যাচ্ছে।');

        $typo = $this->skin(['--color-surfase-app' => '#ffffff']);

        $this->assertNotSame([], $typo->complaints(), 'বানান ভুল পার হয়ে যাচ্ছে।');

        /*
         * জমিন বদলে কালি না বদলানো — গেটটা ঠিক এর জন্যই।
         *
         * প্রথম লেখায় এটাকেই "সঠিক রূপ" ধরা হয়েছিল, আর গেট ১.০৭:১
         * বলে আটকাল। গেটই ঠিক ছিল: কোম্পানি পাতার জমিন প্রায়-কালো
         * করেছে, অথচ কালি Navy থেকে নেমেছে — সেটাও প্রায়-কালো।
         * প্রকাশ হলে গোটা ERP-র লেখা উধাও হত।
         *
         * উত্তরাধিকারের সবচেয়ে সাধারণ ফাঁদ এটাই: একটা টোকেন বদলালে
         * তার জোড়াটাও বদলাতে হয়, আর মানুষ ওটা ভুলে যান।
         */
        $halfDone = $this->skin(['--color-surface-app' => '#101010']);

        $this->assertNotSame([], $halfDone->complaints(),
            'জমিন গাঢ় হয়েছে অথচ কালি হালকা হয়নি — গেটের আটকানোর কথা।');

        // জোড়াসহ বদলালে পার হয়
        $whole = $this->skin([
            '--color-surface-app' => '#101010',
            '--color-ink' => '#f5f5f5',
        ]);

        $this->assertSame([], $whole->complaints(), 'সঠিক রূপ আটকে যাচ্ছে।');
    }

    /**
     * পাতায় স্কিনের টোকেনগুলোই নামে।
     *
     * ── কেন এখানে সত্যিই প্রকাশ করতে হয় ──────────────────────────────
     * বাকি পরীক্ষাগুলো খসড়া নিয়ে চলে, কারণ ওরা চেইনটা মাপে। এটা
     * মাপে **পাতা** — আর পাতা সবসময় প্রকাশিত সংস্করণটাই পরে।
     * খসড়া পরলে সম্পাদনা শুরু করা মাত্র গোটা ডিপোর রং বদলাত।
     */
    public function test_the_page_wears_the_skin(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#1d4ed8']);

        app(LookSkinService::class)->publish($skin, null, $this->owner->id);

        $this->owner->forceFill(['ui' => $skin->public_id])->save();

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('--color-brand-500:#1d4ed8', false);
    }
}
