<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চালানের বাক্সটা পুরো চওড়া নিত, আর ডান কলামের উপরটা খালি পড়ে থাকত।
 *
 * ── ⛔ মালিক ছবিতে তিনটা ঘর মার্ক করে দিলেন, ২১ সেপ্টেম্বর ২০২৬ ───────
 * *"কোন চালানের জন্য box bame mark er vitor, কীভাবে দেওয়া হলো daner
 * markora boxer jaygay, সংযুক্তি niche cuto mark kora box e ei vabe
 * sajiye daw"*।
 *
 * ⓘ অর্থাৎ পর্দাটা দুই কলাম:
 *
 *      বাম কলাম          ডান কলাম
 *      কোন চালানের জন্য   কীভাবে দেওয়া হলো
 *      উৎসে কর্তন         (নোটের হিসাব ঐ বাক্সেরই লেজ)
 *      সংযুক্তি
 *
 * ⚠️ আগে "কোন চালানের জন্য" গ্রিডের **বাইরে** ছিল — পুরো চওড়ায়। ফলে
 * ছয় কলামের টেবিলটা পর্দা জুড়ে টানা যেত, আর তার নিচের দুই-কলাম
 * গ্রিডের ডান ঘরটা উপরের দিকে কিছুই ধরত না।
 *
 * ── ⭐ কেন চোখে দেখা যথেষ্ট নয় ──────────────────────────────────────
 * রং, ফাঁক বা গোল কোণা যন্ত্রে মিলানো যায় না — ওগুলো মালিকের চোখের
 * কাজ। ⓘ কিন্তু **কোন বাক্স কোন ঘরের ভিতরে** সেটা HTML-ই বলে দেয়।
 *
 * ⛔ আর এটাই আসল ফাঁদ ছিল: ক্রম ঠিক রেখেও কেউ একটা বাক্স কলামের বাইরে
 * বের করে দিতে পারে, আর ক্রম-মাপা পরীক্ষা তখনও সবুজ থাকে। ⚠️ তাই
 * এখানে ক্রম নয়, **ধারণ** মাপা হয়: বাম কলামের `<div>`-টার খোলা থেকে
 * তার নিজের বন্ধ পর্যন্ত গুনে দেখা হয় ভিতরে কে কে আছে।
 *
 * ⓘ নোঙরগুলো সবই ঘরের `name` বা Alpine-এর অবস্থা — কোনো বাংলা লেখা
 * নয়। ⚠️ লেখা ধরে খুঁজলে অনুবাদ বদলালেই পরীক্ষাটা মিথ্যা লাল হত।
 */
final class TheBillTagRanTheFullWidthAndTheRightColumnStayedEmptyTest extends TestCase
{
    use RefreshDatabase;

    /** বাম কলামে যা যা থাকার কথা — মালিকের ছবির ক্রমেই। */
    private const LEFT_COLUMN = [
        'onlyUntagged: true' => 'কোন চালানের জন্য',
        'name="ait_amount"' => 'উৎসে কর্তন',
        'name="attachment"' => 'সংযুক্তি',
    ];

    /** ডান কলামের বাক্সটা — "কীভাবে দেওয়া হলো", নোট গোনা তার ভিতরেই। */
    private const RIGHT_COLUMN = 'name="note_counts[';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    /**
     * ⭐ তিনটা বাক্সই এক কলামে, আর মালিকের ক্রমেই।
     */
    public function test_the_three_boxes_sit_in_the_left_column_in_the_owners_order(): void
    {
        $left = $this->leftColumn($this->screen());

        $at = [];

        foreach (self::LEFT_COLUMN as $anchor => $what) {
            $where = strpos($left, $anchor);

            $this->assertNotFalse($where, implode("\n", [
                'বাম কলামে "'.$what.'" বাক্সটা নেই।',
                '',
                'ⓘ মালিকের ছবি, ২১ সেপ্টেম্বর ২০২৬: বাঁয়ে তিনটা —',
                '   কোন চালানের জন্য → উৎসে কর্তন → সংযুক্তি।',
                '⛔ খোঁজা নোঙর: '.$anchor,
            ]));

            $at[$what] = $where;
        }

        $sorted = $at;
        asort($sorted);

        $this->assertSame(array_keys($at), array_keys($sorted), implode("\n", [
            'বাম কলামের বাক্সগুলো মালিকের ক্রমে নেই।',
            '',
            'ⓘ মালিকের ক্রম:  '.implode(' → ', array_keys($at)),
            '⛔ পর্দার ক্রম:   '.implode(' → ', array_keys($sorted)),
        ]));
    }

    /**
     * ⭐ "কীভাবে দেওয়া হলো" বাম কলামের **বাইরে** — অর্থাৎ ডান ঘরে।
     *
     * ── ⚠️ কেন আলাদা দাবি ───────────────────────────────────────────
     * উপরের পরীক্ষাটা কেবল বলে বাঁয়ে কী **আছে**, কী **নেই** তা নয়।
     * ⛔ কেউ টাকার ব্লকটাও বাম কলামে ঢুকিয়ে দিলে ওটা সবুজই থাকত, আর
     * ডান কলাম আবার খালি পড়ে থাকত — ঠিক যে অবস্থাটা মালিক ছবিতে
     * মার্ক করে ধরিয়ে দিয়েছেন।
     */
    public function test_the_payment_box_is_not_inside_the_left_column(): void
    {
        $html = $this->screen();

        $this->assertStringContainsString(self::RIGHT_COLUMN, $html,
            'পর্দায় "কীভাবে দেওয়া হলো" বাক্সটাই নেই — নোট গোনার ঘর পাওয়া যায়নি।');

        $this->assertStringNotContainsString(self::RIGHT_COLUMN, $this->leftColumn($html), implode("\n", [
            '"কীভাবে দেওয়া হলো" বাক্সটা বাম কলামের ভিতরে ঢুকে গেছে।',
            '',
            'ⓘ মালিকের কথা: *"কীভাবে দেওয়া হলো daner markora boxer jaygay"*।',
            '⛔ বাঁয়ে বসলে ডান কলামটা আবার খালি পড়ে থাকবে।',
        ]));
    }

    /**
     * ⭐ বাম কলামটা সত্যিই একটা কলাম — পুরো পাতা নয়।
     *
     * ── ⛔ নিজের যন্ত্রটার উপরেই অবিশ্বাস ───────────────────────────
     * `leftColumn()` `<div>` গুনে বন্ধটা খোঁজে। ⚠️ গোনাটা ভুল হলে সে
     * পাতার **শেষ** পর্যন্ত টেনে নিত — তখন "সব কিছুই বাম কলামে আছে"
     * হয়ে যেত, আর উপরের দুইটা পরীক্ষাই অর্থহীনভাবে সবুজ থাকত।
     *
     * ⓘ তাই এখানে দাবি করা হয়: কলামটা পাতার শেষে থামে না, আর তার
     * পরে এখনো যথেষ্ট HTML বাকি আছে (ডান কলাম, নিচের সারি, ফুটার)।
     */
    public function test_the_left_column_really_closes_before_the_page_does(): void
    {
        $html = $this->screen();
        $left = $this->leftColumn($html);

        $this->assertGreaterThan(500, strlen($left),
            'বাম কলামটা সন্দেহজনকভাবে ছোট — `<div>` গোনা বোধহয় আগেই থেমে গেছে।');

        $this->assertLessThan(strlen($html) - 2000, strlen($left), implode("\n", [
            'বাম কলামটা কার্যত পুরো পাতা গিলে ফেলেছে।',
            '',
            '⛔ মানে `<div>` গোনা বন্ধটা খুঁজে পায়নি, আর এই ফাইলের বাকি',
            '   দাবিগুলো তখন যা-ই থাক সবুজই থাকত।',
        ]));
    }

    /**
     * বাম কলামের `<div>`-টার ভিতরের HTML — খোলা থেকে তার নিজের বন্ধ পর্যন্ত।
     *
     * ⓘ গোনাটা সরল: `<div` পেলে গভীরতা বাড়ে, `</div>` পেলে কমে। ⚠️ Alpine
     * বা অ্যাট্রিবিউটের ভিতরে আক্ষরিক `<div` লেখা থাকলে গোনা ভাঙত — তাই
     * উপরে আলাদা একটা পরীক্ষা আছে যা ধরে ফেলবে।
     */
    private function leftColumn(string $html): string
    {
        $grid = strpos($html, 'lg:grid-cols-2 lg:items-start');

        $this->assertNotFalse($grid, implode("\n", [
            'খরচের পর্দায় দুই কলামের গ্রিডটাই নেই।',
            '',
            'ⓘ খোঁজা হচ্ছিল: lg:grid-cols-2 lg:items-start',
            '⛔ কেউ কি গ্রিডটা বদলে ফেলেছে?',
        ]));

        $marker = strpos($html, 'min-w-0 space-y-4', $grid);

        $this->assertNotFalse($marker,
            'গ্রিডের ভিতরে বাম কলামের ঘরটা (min-w-0 space-y-4) পাওয়া গেল না।');

        $open = strrpos(substr($html, 0, $marker), '<div');

        $this->assertNotFalse($open, 'বাম কলামের `<div` খোলাটাই পাওয়া গেল না।');

        $depth = 0;
        $at = $open;
        $end = strlen($html);

        while ($at < $end) {
            $next = strpos($html, '<div', $at + 1);
            $close = strpos($html, '</div>', $at + 1);

            if ($close === false) {
                break;
            }

            if ($next !== false && $next < $close) {
                $depth++;
                $at = $next;

                continue;
            }

            if ($depth === 0) {
                return substr($html, $open, $close + 6 - $open);
            }

            $depth--;
            $at = $close;
        }

        return substr($html, $open);
    }

    private function screen(): string
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $user->switchCompany($company->id);

        $response = $this->actingAs($user)->get('/accounts/vouchers/expense/create');

        $response->assertOk();

        return (string) $response->getContent();
    }
}
