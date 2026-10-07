<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "এখানে আছি" চিহ্নটা বোতামের গায়ে, রেলের দেয়ালে নয়।
 *
 * ── কী ঘটেছিল, ৫ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
 * মালিক দুইটা ছবি পাশাপাশি রেখে জিজ্ঞেস করলেন *"কোনটা সুন্দর"*, তারপর
 * বললেন *"আমি এই রকম চাই ১০০%"*। ⓘ আর ছবিটা কল্পনা নয় — **আমাদেরই
 * নকশার নমুনা**, `brand/design/_ui.css`:
 *
 *     .rail a.on { background: rgba(255,255,255,.12);
 *                  box-shadow: inset 2px 0 0 var(--brand-bright) }
 *
 * ⛔ কোডে বসেছিল অন্য জিনিস: `a`-র `::before`-এ **রেলের বাইরের কিনারা
 * ঘেঁষে একটা সোজা দাগ**। টাইল আর দাগের মাঝে ফাঁকা জায়গা থাকত, আর navy
 * রূপে টাইলের জমিন স্বচ্ছ বলে দাগটা আঁকড়ে ধরার মতো কিছুই পেত না। ⓘ
 * ফলে চোখ বলত না *"এই টাইলটা চালু"*, বলত *"রেলের এই উচ্চতায় কিছু আছে"*।
 *
 * ── কেন এটার একটা পাহারা দরকার ───────────────────────────────────────
 * ⚠️ মালিক নিজেই একবার এই চিহ্নটা **তুলে দিয়েছিলেন** — *"এটা বাদ
 * দিয়েছিলাম কারণ লোগোগুলো হোম বাটনের কাজ করে।"* ⓘ অর্থাৎ এটা এমন একটা
 * সিদ্ধান্ত যা একবার এসেছে, গেছে, আবার ফিরেছে — আর তিনবারের কারণ
 * তিনটাই আলাদা। কোডে চুপচাপ ফিরে গেলে কেউ ধরতে পারত না কোন রূপটা
 * ইচ্ছাকৃত।
 *
 * ⭐ আর দুইটা জিনিস বিরোধী নয়, এই টেস্টটা সেটাও ধরে রাখে:
 *
 *     হোম বোতাম   →  "চাপলে কোথায় যাব"   ভবিষ্যৎ
 *     চালু চিহ্ন   →  "এখন কোথায় আছি"     বর্তমান
 *
 * তাই তৃতীয় পরীক্ষাটা দেখে চালু টাইলটা **এখনো ক্লিকযোগ্য** — চিহ্ন
 * বসাতে গিয়ে কেউ `href` তুলে দিলে বোতামটা ছবিতে পরিণত হত।
 */
class TheMarkOfWhereYouAreSatOnTheWallNotTheButtonTest extends TestCase
{
    use RefreshDatabase;

    /** যে পর্দায় একটা মডিউল সত্যিই চালু হয় — ব্রাউজারে মেপে বাছা। */
    private const INSIDE_A_MODULE = '/dashboard/purchase';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        CompanyContext::clear();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function shell(): string
    {
        return $this->actingAs($this->owner())
            ->get(self::INSIDE_A_MODULE)
            ->assertOk()
            ->getContent();
    }

    /**
     * চিহ্নটা টাইলের গায়ে — জমিন ও বাঁকা দাগ, দুইটাই।
     *
     * ⚠️ `inset` শব্দটাই এখানে আসল: ওটা ছাড়া `box-shadow` বাইরে পড়ত,
     * আর টাইলের গোল কোণ ধরে বাঁকত না — নমুনার ঐ চাঁদের আকৃতিটা
     * `inset`-এরই ফল।
     */
    public function test_the_active_tile_carries_the_mark_on_its_own_face(): void
    {
        $html = $this->shell();

        $this->assertStringContainsString(
            'box-shadow: inset var(--rail-tile-on-edge-w) 0 0 var(--rail-tile-on-edge)',
            $html,
            'চালু টাইলে বাঁকা দাগটা নেই। '
            .'নমুনা: `.rail a.on{box-shadow:inset 2px 0 0 var(--brand-bright)}` '
            .'— `brand/design/_ui.css`।',
        );

        $this->assertStringContainsString(
            'var(--rail-tile-on-bg, var(--rail-tile-bg))',
            $html,
            'চালু টাইলের জমিনটা নেই। জমিন ছাড়া দাগটা কিছুই আঁকড়ে ধরে না — '
            .'ঠিক যে দোষটার জন্য মালিক আগে চিহ্নটা তুলে দিয়েছিলেন।',
        );

        /*
         * ⚠️ আর পুরনো দাগটা যেন **ফিরে না আসে** — দুইটা একসাথে থাকলে
         * চালু টাইলের দুইটা চিহ্ন হত, একটা গায়ে একটা দেয়ালে।
         *
         * ⓘ মাপা হয় চালু `<a>`-র নিজের ক্লাসে: `before:w-1` ছিল ঐ সোজা
         * দাগের প্রস্থ। ⚠️ পুরো পাতায় খোঁজা যেত না — ব্র্যান্ডের
         * পট্টিটাও একই সোনালি `::before` ব্যবহার করে, আর সেটা বৈধ।
         */
        preg_match('/<a\s[^>]*aria-current="true"[^>]*>/s', $html, $tag);

        $this->assertNotEmpty($tag, 'চালু টাইলটাই পাওয়া গেল না।');
        $this->assertStringNotContainsString(
            'before:w-1',
            $tag[0],
            'পুরনো সোজা দাগটা চালু টাইলে ফিরে এসেছে — চিহ্ন এখন দুইটা।',
        );
    }

    /**
     * রেলের একদম প্রথম সারির উপরে ভাগের রেখা বসে না।
     *
     * ── ⛔ এই দোষটা কীভাবে ধরা পড়ল ───────────────────────────────────
     * রেখাটা সোনালি ও মোটা করার পর ব্রাউজারে দেখা গেল **ড্যাশবোর্ডের
     * সাদা `border-b`-র ঠিক নিচেই একটা সোনালি দাগ** — পরপর দুইটা রেখা,
     * দুই রঙে। ⚠️ দোষটা নতুন ছিল না, **পুরনো আর অদৃশ্য**: আগে রেখাটা
     * ২০% সাদা ছিল বলে কেউ ওটা দেখেনি।
     *
     * ⓘ নিয়মটা `sidebar.blade.php`-র মন্তব্যে লেখাই ছিল ("top দলে রেখা
     * নেই"), কিন্তু **কোডে বসানো ছিল না** — `top` দলে যখন এই
     * ব্যবহারকারীর একটাও মডিউল নেই, তখন প্রথম দলটা হয়ে যায় FINANCE,
     * আর সে রেখা পায়। ⭐ আজকের ছাঁচ: *নিয়ম মন্তব্যে আছে, কোডে নেই*।
     *
     * ── ⛔ প্রথম চেষ্টাটা অন্ধ ছিল, আর সেটা ভেঙেই ধরা পড়েছে ────────
     * প্রথমে মাপা হয়েছিল পুরো পাতায়: `--rail-tile-bg`-র অবস্থান
     * `role="separator"`-এর আগে কি না। ⚠️ পরীক্ষাটা সবুজ ছিল, আর
     * দোষটা ইচ্ছে করে ফিরিয়ে আনার পরেও **সবুজই থাকল**।
     *
     * ⓘ কারণ ড্যাশবোর্ডের টাইলটা রেলের `<nav>`-এর **বাইরে**, তার
     * ঠিক উপরে — আর সে-ও `--rail-tile-bg` লেখে। ⭐ তাই "একটা টাইল
     * প্রথম রেখার আগে আছে" কথাটা **সবসময়ই সত্যি** ছিল, রেলের
     * ভিতরে যা-ই ঘটুক।
     *
     * ── এখন যেভাবে মাপা ─────────────────────────────────────────
     * রেলের `<nav>` শুরু হওয়ার জায়গা থেকে **তার প্রথম রেখা পর্যন্ত**
     * টুকরোটা কাটা হয়, আর ওখানে অন্তত একটা `<a ` থাকতেই হবে। ⓘ
     * অর্থাৎ প্রশ্নটা ঠিক নিয়মটাই: *"এই রেখার উপরে ভাগ করার মতো
     * কিছু আছে তো?"*
     */
    public function test_no_divider_sits_above_the_very_first_tile(): void
    {
        $html = $this->shell();

        $firstLine = strpos($html, 'role="separator"');
        $this->assertNotFalse($firstLine, 'রেলে একটাও ভাগের রেখা পাওয়া গেল না।');

        $railOpens = strrpos(substr($html, 0, $firstLine), '<nav');
        $this->assertNotFalse($railOpens, 'রেলের `<nav>`-টাই পাওয়া গেল না।');

        $between = substr($html, $railOpens, $firstLine - $railOpens);

        $this->assertStringContainsString(
            '<a ',
            $between,
            "রেলের প্রথম টাইলের **উপরে** একটা ভাগের রেখা বসেছে।\n"
            .'ড্যাশবোর্ডের নিজের `border-b`-র ঠিক নিচে ওটা পরপর দুইটা দাগ হয়ে যায় — '
            .'আর উপরে ভাগ করার মতো কিছুই নেই।',
        );
    }

    /**
     * চিহ্ন বসেছে, কিন্তু বোতামটা বোতামই আছে।
     *
     * ⚠️ মালিকের নিজের কথা: *"লোগোগুলো হোম বাটনের কাজ করে।"* ⓘ চালু
     * টাইলে `href` না থাকলে ব্যবহারকারী মডিউলের হোমে ফিরতে পারতেন না —
     * আর ঠিক ঐ কারণেই তিনি একবার চিহ্নটা তুলে দিয়েছিলেন।
     */
    public function test_the_active_tile_is_still_a_button(): void
    {
        $html = $this->shell();

        $this->assertMatchesRegularExpression(
            '/<a\s[^>]*href="[^"]+"[^>]*aria-current="true"/s',
            $html,
            'চালু টাইলটা আর ক্লিকযোগ্য নয় — চিহ্ন বসাতে গিয়ে `href` হারিয়েছে। '
            .'রেলের চিহ্নগুলো হোম বোতামের কাজ করে (মালিক, ৫ সেপ্টেম্বর ২০২৬)।',
        );
    }
}
