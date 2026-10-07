<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * এককের ফর্মে সংখ্যাটা কোন দিকে যায়, কোথাও লেখা ছিল না।
 *
 * ── ⛔ কী ভাঙা ছিল, ২২ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * ঘরটার নাম ছিল শুধু **"রূপান্তর"**। ⚠️ কার্টনে ১২ পিস, নাকি পিসে ১২
 * কার্টন? দুইটাই সমান যুক্তিসঙ্গত পড়া, আর উল্টো বসালে ওই এককে মাপা
 * প্রতিটা সংখ্যা ১৪৪ গুণ ভুল হয়ে যায়।
 *
 * ⓘ কিছুই লাল হয় না। ফর্ম নেয়, সারিটা বসে, আর ভুলটা ধরা পড়ে মাস পরে
 * — যখন কেউ মজুদের সংখ্যাটা চোখে দেখা বস্তার সাথে মেলাতে যান।
 *
 * ── ⚠️ প্যাক আসার পর দ্বিতীয় একটা বিভ্রান্তি ─────────────────────────
 * এখানকার সংখ্যাটা **সার্বজনীন** (ডজন = ১২ পিস, সব পণ্যে)। ⛔ কিন্তু
 * কার্টনে কত সেটা পণ্যভেদে আলাদা — ওটা [[ProductUnit]]-এ, পণ্যের
 * ফর্মে। ⓘ এখানে "কার্টন = ১২" বসিয়ে কেউ ধরে নিতে পারতেন সব পণ্যের
 * কার্টনেই বারোটা।
 *
 * ── ⭐ কেন এটা একটা পরীক্ষা, শুধু একটা বাক্য নয় ──────────────────────
 * বাক্যটা `MasterListController`-এর ঘোষণায় লেখা, আর আঁকা হয় সবার ভাগের
 * ফর্মে। ⚠️ দুইটার যেকোনো একটা বদলালে বাক্যটা নীরবে উধাও হয়ে যেত —
 * ঘোষণায় আছে, পর্দায় নেই, আর কিছুই ভাঙত না।
 */
final class TheUnitFormNeverSaidWhichWayTheNumberWentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany((int) $company->id);
    }

    /**
     * ⭐ তিনটা ঘরের তিনটা ইঙ্গিতই পর্দায় পৌঁছায়।
     *
     * ── ⓘ তিনটাই কেন ───────────────────────────────────────────────
     * ফর্মটা ঘরের ধরন ধরে **তিনটা আলাদা শাখায়** আঁকে: switch, select,
     * আর সাধারণ ঘর। ⚠️ একটা শাখায় ইঙ্গিত আঁকতে ভুলে গেলে ওই ধরনের
     * প্রতিটা ঘর নীরবে ইঙ্গিতহীন থাকত।
     *
     * ⭐ তাই তিনটা ধরনেরই একটা করে এখানে মাপা হয় — একটা দাবি তিনটা
     * শাখা ঢাকে, আর এই ফর্মটা সব মাস্টার তালিকার ভাগের।
     */
    public function test_every_field_says_what_its_number_means(): void
    {
        $html = (string) $this->actingAs($this->owner)
            ->get(route('master_data.unit.create'))
            ->assertOk()
            ->getContent();

        foreach (['base_unit_hint', 'factor_hint', 'allows_fraction_hint'] as $key) {
            $this->assertStringContainsString(
                e(__('master_data::message.'.$key)),
                $html,
                implode(PHP_EOL, [
                    'এই ইঙ্গিতটা ফর্মে নেই: '.$key,
                    '',
                    '⛔ ঘোষণায় লেখা আছে, পর্দায় নেই — আর কিছুই ভাঙেনি।',
                ]),
            );
        }
    }

    /**
     * ⭐ ইঙ্গিতটা বলে সংখ্যাটা **কোন দিকে**, আর কোনটা এখানে নয়।
     *
     * ── ⚠️ কেন লেখাটাও মাপা হয় ──────────────────────────────────────
     * উপরের দাবিটা কেবল বলে *"কিছু একটা লেখা আছে"*। ⛔ কেউ যদি
     * বাক্যটা বদলে "একক রূপান্তর" লিখে দেন, ওটা সবুজই থাকত — আর
     * বিভ্রান্তিটা ফিরে আসত, এবার একটা পাহারার আড়ালে।
     *
     * ⓘ তাই দুইটা জিনিস নাম ধরে খোঁজা হয়: উদাহরণটা (১ ডজন = ১২ পিস),
     * আর **সীমাটা** — কার্টনের মাপ পণ্যের ফর্মে।
     */
    public function test_the_hint_gives_the_direction_and_names_the_limit(): void
    {
        $hint = __('master_data::message.factor_hint');

        $this->assertStringContainsString('১২', $hint, implode(PHP_EOL, [
            'ইঙ্গিতে কোনো উদাহরণ নেই।',
            '',
            'ⓘ "এককের রূপান্তর" লেখা একটা বাক্য কিছুই পরিষ্কার করে না —',
            'দিকটা একটা সংখ্যা দিয়ে দেখানো ছাড়া বোঝানো যায় না।',
        ]));

        $this->assertStringContainsString('পণ্যের', $hint, implode(PHP_EOL, [
            'ইঙ্গিতটা বলে না যে পণ্যভেদে কার্টনের মাপ আলাদা।',
            '',
            '⛔ তাহলে কেউ এখানে "কার্টন = ১২" বসিয়ে ধরে নিতেন সব পণ্যের',
            'কার্টনেই বারোটা।',
        ]));
    }
}
