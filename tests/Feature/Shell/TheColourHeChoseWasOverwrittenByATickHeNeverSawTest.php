<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বাছা রংটা একটা টিকের নিচে চাপা পড়ত — যে টিক কেউ দেয়নি।
 *
 * ── মালিকের কথা, ৫ সেপ্টেম্বর ২০২৬ ───────────────────────────────────
 * *"রং বাছলে রং পরিবর্তন হয় না।"* ⓘ আর সেটা সত্যি ছিল **প্রতিবার,
 * সবার জন্য** — কোনো নির্দিষ্ট রঙে নয়, সবগুলোতেই।
 *
 * ── কেন কেউ ধরতে পারেনি ──────────────────────────────────────────────
 * ⛔ কিছুই ভাঙত না। ফর্ম জমা হত, ৩০২ ফিরত, "সংরক্ষিত" বার্তা আসত।
 * **সেভটা সফলই হত — শুধু অন্য একটা মান নিয়ে।** ⚠️ নীরব ভুল, তাই
 * পর্দা দেখে বোঝার কোনো উপায় ছিল না; ব্যবহারকারী ভাবতেন রঙের সারিটা
 * কাজ করে না।
 *
 * ── আসল কারণ, তিনটা নিরীহ সিদ্ধান্তের যোগফল ─────────────────────────
 *   ১. রঙের রেডিওতে `onchange="this.form.requestSubmit()"` — বাছলেই
 *      **পুরো ফর্মটা** যায়, শুধু রংটা নয়।
 *   ২. `match_accent` টিকটা ব্লেডে **হার্ডকোড `checked`** — কলাম নেই,
 *      তাই প্রতিবার পাতা খুললেই সে ফিরে আসে।
 *   ৩. কন্ট্রোলার টিক দেখলেই `accent`-কে রূপের নিজের রঙে বদলে দিত।
 *
 * ⓘ তিনটার একটাও নিজে ভুল নয়। ⭐ **ভুলটা তাদের যোগফলে** — আর সেজন্যই
 * এটা একটা টেস্ট দাবি করে: পরের কেউ যদি রেডিওর `requestSubmit` তোলে
 * বা টিকটাকে কলামে বসায়, এই ফাইলটাই বলবে সম্পর্কটা এখনো ঠিক আছে কি না।
 *
 * ── নিয়মটা এক লাইনে ──────────────────────────────────────────────────
 * টিকটার মানে *"আমি যখন একটা **রূপ** বাছি, তার রংটাও নিও"* — অর্থাৎ সে
 * রূপ বাছার সঙ্গী, রং বাছার নয়। তাই সে কথা বলে **কেবল `ui` সত্যিই
 * বদলালে**।
 */
class TheColourHeChoseWasOverwrittenByATickHeNeverSawTest extends TestCase
{
    use RefreshDatabase;

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

    /** পরীক্ষার জন্য একটা কর্মীর অ্যাকাউন্ট — মালিকেরটা নয়। */
    private function someone(): User
    {
        return User::query()->where('email', 'accounts@abos.test')->firstOrFail();
    }

    /**
     * পর্দাটা যা পাঠায়, হুবহু।
     *
     * ⚠️ `match_accent` এখানে **সবসময় থাকে**, কারণ ব্লেডে সে হার্ডকোড
     * `checked` — অর্থাৎ ব্রাউজার প্রতিবার এটাই পাঠায়। ⓘ টেস্টটা যদি
     * ওটা বাদ দিত, সে এমন একটা জমা মাপত যা বাস্তবে কখনো ঘটে না, আর
     * তখন সবুজ থেকেও বাগটা লাইভে থাকত।
     *
     * @return array<string, string>
     */
    private function asTheScreenSends(User $u, string $accent, ?string $ui = null): array
    {
        return [
            'match_accent' => '1',
            'ui' => $ui ?? (string) $u->ui,
            'accent' => $accent,
            'theme' => (string) $u->theme,
            'locale' => (string) $u->locale,
        ];
    }

    public function test_choosing_a_colour_actually_changes_the_colour(): void
    {
        $u = $this->someone();
        $u->forceFill(['ui' => 'navy', 'accent' => 'abos'])->save();

        $this->actingAs($u)
            ->post('/appearance', $this->asTheScreenSends($u->fresh(), 'pink'))
            ->assertRedirect();

        $this->assertSame(
            'pink',
            $u->fresh()->accent,
            "রং বাছার পরেও রং বদলায়নি।\n"
            .'রঙের রেডিও পুরো ফর্মটা জমা দেয়, আর `match_accent` টিকটা '
            .'মার্কআপে হার্ডকোড — তাই টিকটা যদি রূপ না বদলালেও কথা বলে, '
            .'প্রতিটা রং বাছাই নীরবে মুছে যায়।',
        );
    }

    /**
     * ⚠️ টিকটার নিজের কাজটা এখনো করতে হবে — নাহলে সারানোটা অন্য দিকে ভাঙা।
     *
     * ⓘ এই দুইটা পরীক্ষা একসাথে না থাকলে "রং বাছাই কাজ করে" বানানোর
     * সহজ পথ হত টিকটাকে পুরোপুরি নিষ্ক্রিয় করা — আর তখন Odoo বেছেও
     * তার বেগুনি পাওয়া যেত না।
     */
    public function test_the_tick_still_brings_the_looks_own_colour_when_the_look_changes(): void
    {
        $u = $this->someone();
        $u->forceFill(['ui' => 'navy', 'accent' => 'pink'])->save();

        $this->actingAs($u)
            ->post('/appearance', $this->asTheScreenSends($u->fresh(), 'pink', 'linear'))
            ->assertRedirect();

        $this->assertSame(
            'linear',
            $u->fresh()->ui,
            'রূপটাই বদলায়নি — এই পরীক্ষাটা তাহলে কিছুই মাপছে না।',
        );

        $this->assertNotSame(
            'pink',
            $u->fresh()->accent,
            'রূপ বদলেছে আর টিকও দেওয়া, তবু রূপের নিজের রং বসেনি। '
            .'টিকটার একমাত্র কাজই এটা।',
        );
    }

    /**
     * টিক তোলা থাকলে রূপ বদলালেও রং নিজের থাকে।
     *
     * ⓘ কোডের মন্তব্যে এই প্রতিশ্রুতিটা লেখা আছে (*"যিনি নিজের রং ঠিক
     * করে রেখেছেন, তিনি টিক তুলে দেবেন"*), তাই সেটাও মেপে রাখা।
     */
    public function test_without_the_tick_the_chosen_colour_survives_a_look_change(): void
    {
        $u = $this->someone();
        $u->forceFill(['ui' => 'navy', 'accent' => 'pink'])->save();

        $sent = $this->asTheScreenSends($u->fresh(), 'pink', 'linear');
        unset($sent['match_accent']);

        $this->actingAs($u)->post('/appearance', $sent)->assertRedirect();

        $this->assertSame(
            'pink',
            $u->fresh()->accent,
            'টিক তোলা ছিল, তবু রূপ বদলানোর সময় বাছা রংটা হারিয়ে গেছে।',
        );
    }
}
