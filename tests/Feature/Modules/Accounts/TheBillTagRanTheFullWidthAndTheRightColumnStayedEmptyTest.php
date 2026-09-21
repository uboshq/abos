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
 * ── ⛔ মালিক ছবিতে ঘরগুলো মার্ক করে দিলেন, ২১ সেপ্টেম্বর ২০২৬ ─────────
 * প্রথম দফা: *"কোন চালানের জন্য box bame mark er vitor, কীভাবে দেওয়া
 * হলো daner markora boxer jaygay, সংযুক্তি niche cuto mark kora box e"*।
 *
 * দ্বিতীয় দফা, একই দিনে: *"সংযুক্তি dane lal mark kora jaygay niye
 * daw"* — অর্থাৎ সংযুক্তি বাম কলাম থেকে **ডানে**, চেকের ঘরগুলোর নিচে।
 *
 * ⓘ চূড়ান্ত সাজ:
 *
 *      বাম কলাম          ডান কলাম
 *      কোন চালানের জন্য   কীভাবে দেওয়া হলো
 *      উৎসে কর্তন         সংযুক্তি
 *
 * ── ⭐ কেন চোখে দেখা যথেষ্ট নয় ──────────────────────────────────────
 * রং, ফাঁক বা গোল কোণা যন্ত্রে মিলানো যায় না — ওগুলো মালিকের চোখের
 * কাজ। ⓘ কিন্তু **কোন বাক্স কোন ঘরের ভিতরে** সেটা HTML-ই বলে দেয়।
 *
 * ⛔ আর এটাই আসল ফাঁদ: ক্রম ঠিক রেখেও কেউ একটা বাক্স কলামের বাইরে
 * বের করে দিতে পারে, আর ক্রম-মাপা পরীক্ষা তখনও সবুজ থাকে। ⚠️ তাই
 * এখানে ক্রম নয়, **ধারণ** মাপা হয়: কলামের `<div>`-টার খোলা থেকে তার
 * নিজের বন্ধ পর্যন্ত গুনে দেখা হয় ভিতরে কে কে আছে।
 *
 * ⓘ নোঙরগুলো সবই ঘরের `name` বা Alpine-এর অবস্থা — কোনো বাংলা লেখা
 * নয়। ⚠️ লেখা ধরে খুঁজলে অনুবাদ বদলালেই পরীক্ষাটা মিথ্যা লাল হত।
 */
final class TheBillTagRanTheFullWidthAndTheRightColumnStayedEmptyTest extends TestCase
{
    use RefreshDatabase;

    /** বাম কলামে যা যা, মালিকের ক্রমেই। */
    private const LEFT = [
        'onlyUntagged: true' => 'কোন চালানের জন্য',
        'name="ait_amount"' => 'উৎসে কর্তন',
    ];

    /** ডান কলামে যা যা, মালিকের ক্রমেই। */
    private const RIGHT = [
        'name="note_counts[' => 'কীভাবে দেওয়া হলো',
        'name="attachment"' => 'সংযুক্তি',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_the_left_column_holds_the_bill_tag_then_the_deduction(): void
    {
        $this->assertColumnHolds($this->column(1), self::LEFT, 'বাম');
    }

    public function test_the_right_column_holds_the_payment_then_the_attachment(): void
    {
        $this->assertColumnHolds($this->column(2), self::RIGHT, 'ডান');
    }

    /**
     * ⭐ সংযুক্তি বাম কলামে **নেই** — মালিকের দ্বিতীয় নির্দেশ।
     *
     * ── ⚠️ কেন আলাদা দাবি ───────────────────────────────────────────
     * উপরের দুইটা কেবল বলে কোন কলামে কী **আছে**, কী **নেই** তা নয়।
     * ⛔ কেউ সংযুক্তিকে দুই জায়গাতেই রেখে দিলে ওরা সবুজই থাকত — আর
     * তখন একই নামের দুইটা ফাইল-ঘর ফর্মে থাকত, যার একটা সবসময় খালি।
     * ⓘ খালিটা ভরাটাকে মুছে দিত, ঠিক money-movement-এর পুরনো বাগের মতো।
     */
    public function test_the_attachment_is_no_longer_in_the_left_column(): void
    {
        $this->assertStringNotContainsString('name="attachment"', $this->column(1), implode("\n", [
            'সংযুক্তি বাম কলামে ফিরে এসেছে।',
            '',
            'ⓘ মালিকের কথা: *"সংযুক্তি dane lal mark kora jaygay niye daw"*।',
            '⛔ দুই কলামে থাকলে ফর্মে একই নামের দুইটা ফাইল-ঘর — একটা চিরকাল খালি।',
        ]));
    }

    /**
     * ⭐ কলাম দুইটা সত্যিই কলাম — পুরো পাতা নয়।
     *
     * ── ⛔ নিজের যন্ত্রটার উপরেই অবিশ্বাস ───────────────────────────
     * `column()` `<div>` গুনে বন্ধটা খোঁজে। ⚠️ গোনাটা ভুল হলে সে পাতার
     * **শেষ** পর্যন্ত টেনে নিত — তখন "সব কিছুই এই কলামে আছে" হয়ে যেত,
     * আর উপরের দাবিগুলো অর্থহীনভাবে সবুজ থাকত।
     */
    public function test_each_column_really_closes_before_the_page_does(): void
    {
        $html = $this->screen();

        foreach ([1 => 'বাম', 2 => 'ডান'] as $which => $side) {
            $column = $this->column($which, $html);

            $this->assertGreaterThan(500, strlen($column),
                $side.' কলামটা সন্দেহজনকভাবে ছোট — `<div>` গোনা বোধহয় আগেই থেমে গেছে।');

            $this->assertLessThan(strlen($html) - 2000, strlen($column), implode("\n", [
                $side.' কলামটা কার্যত পুরো পাতা গিলে ফেলেছে।',
                '',
                '⛔ মানে `<div>` গোনা বন্ধটা খুঁজে পায়নি, আর এই ফাইলের বাকি',
                '   দাবিগুলো তখন যা-ই থাক সবুজই থাকত।',
            ]));
        }

        // ⚠️ দুইটা যেন একই টুকরো না হয় — নাহলে "দুই কলাম" দাবিটাই ফাঁকা।
        $this->assertNotSame($this->column(1, $html), $this->column(2, $html),
            'দুই কলাম একই HTML ফেরত দিচ্ছে — গ্রিডে কি সত্যিই দুইটা ঘর আছে?');
    }

    /**
     * @param  array<string, string>  $expected
     */
    private function assertColumnHolds(string $column, array $expected, string $side): void
    {
        $at = [];

        foreach ($expected as $anchor => $what) {
            $where = strpos($column, $anchor);

            $this->assertNotFalse($where, implode("\n", [
                $side.' কলামে "'.$what.'" বাক্সটা নেই।',
                '',
                'ⓘ মালিকের ছবি, ২১ সেপ্টেম্বর ২০২৬ —',
                '   বাঁয়ে: কোন চালানের জন্য → উৎসে কর্তন',
                '   ডানে:  কীভাবে দেওয়া হলো → সংযুক্তি',
                '⛔ খোঁজা নোঙর: '.$anchor,
            ]));

            $at[$what] = $where;
        }

        $sorted = $at;
        asort($sorted);

        $this->assertSame(array_keys($at), array_keys($sorted), implode("\n", [
            $side.' কলামের বাক্সগুলো মালিকের ক্রমে নেই।',
            '',
            'ⓘ মালিকের ক্রম:  '.implode(' → ', array_keys($at)),
            '⛔ পর্দার ক্রম:   '.implode(' → ', array_keys($sorted)),
        ]));
    }

    /**
     * গ্রিডের `$which`-তম কলামের HTML — খোলা থেকে তার নিজের বন্ধ পর্যন্ত।
     *
     * ⓘ দুইটা কলামই `min-w-0 space-y-4` দিয়ে চিহ্নিত, তাই কত নম্বরটা
     * লাগবে সেটাই একমাত্র পার্থক্য।
     */
    private function column(int $which, ?string $html = null): string
    {
        $html ??= $this->screen();

        $grid = strpos($html, 'lg:grid-cols-2 lg:items-start');

        $this->assertNotFalse($grid, implode("\n", [
            'খরচের পর্দায় দুই কলামের গ্রিডটাই নেই।',
            '',
            'ⓘ খোঁজা হচ্ছিল: lg:grid-cols-2 lg:items-start',
        ]));

        $marker = $grid;

        for ($n = 0; $n < $which; $n++) {
            $marker = strpos($html, 'min-w-0 space-y-4', (int) $marker + 1);

            $this->assertNotFalse($marker,
                'গ্রিডে '.$which.' নম্বর কলামের ঘরটা (min-w-0 space-y-4) পাওয়া গেল না।');
        }

        $open = strrpos(substr($html, 0, (int) $marker), '<div');

        $this->assertNotFalse($open, $which.' নম্বর কলামের `<div` খোলাটাই পাওয়া গেল না।');

        return $this->untilItCloses($html, (int) $open);
    }

    /**
     * `<div` গুনে নিজের `</div>` খোঁজা।
     *
     * ⚠️ অ্যাট্রিবিউটের ভিতরে আক্ষরিক `<div` লেখা থাকলে গোনা ভাঙত —
     * তাই উপরে আলাদা একটা পরীক্ষা আছে যা সেটা ধরে ফেলবে।
     */
    private function untilItCloses(string $html, int $open): string
    {
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
