<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\Ui;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সক্রিয় ছাঁকনির চিপে মানুষের ভাষা।
 *
 * ── কী ভাঙা ছিল, ৪ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * চিপগুলো দশটা সাজেই ছিল, আর তাদের কাজ একটাই: **কোন ছাঁকনি এখন চালু
 * তা জানানো**। কিন্তু চিপে উঠত query-র **কাঁচা মান**, আর এই রিপোর
 * সবচেয়ে সাধারণ ছাঁকনি একটা চেকবক্স (`cancelled=1`, `inactive=1`)।
 *
 * ⛔ ফল: বত্রিশটা তালিকার চব্বিশটাতে চিপে লেখা থাকত **"1"**।
 *
 * একটা চিপ যেটা বলে "1" — সেটা তথ্য নয়, ধাঁধা। আর ধাঁধাটা নীরব:
 * পাতাটা ২০০ দেয়, চিপটা দেখা যায়, ছাঁকনিটা কাজও করে। কেবল মানুষটা
 * জানেন না **কেন** তালিকা ছোট হয়ে গেছে — যেটা আটকাতে চিপটা বসানো
 * হয়েছিল।
 *
 * ── ⚠️ দাবিটা দুই ভাগে, আর সেটা ইচ্ছাকৃত ────────────────────────────
 * "চিপে ১ লেখা নেই" — এই দাবিটা **চিপ না থাকলেও পাশ করত**। তাই
 * প্রতিটা পরীক্ষায় আগে দেখা হয় **চিপটা আছে**, তারপর দেখা হয় **তাতে
 * কী লেখা**। অনুপস্থিতির উপর দাবি অনুপস্থিতিতেই পাশ করে।
 */
class TheChipSaidOneAndNobodyKnewWhichFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    /**
     * চিপের ভেতরের লেখা — আর সেটা খুঁজে না পেলে টেস্ট নিজেই থামে।
     *
     * @return list<string>
     */
    private function chips(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();

        preg_match_all(
            '/data-facet.*?<span class="truncate">(.*?)<\/span>/s',
            (string) $html,
            $found,
        );

        /*
         * ⚠️ এই দাবিটা বাকি সব দাবির শর্ত।
         *
         * চিপটাই না থাকলে "চিপে কী লেখা" প্রশ্নের কোনো মানে নেই, আর
         * তখন নিচের প্রতিটা assertion **শূন্য তালিকার উপর** চলত — অর্থাৎ
         * চিরকাল সবুজ, কিছু না দেখেই।
         */
        $this->assertNotEmpty($found[1],
            "ছাঁকনি চালু, অথচ কোনো চিপই নেই: {$url}");

        return array_map('trim', $found[1]);
    }

    public function test_a_ticked_box_says_what_it_is_not_one(): void
    {
        $chips = $this->chips(route('customer.index', ['inactive' => 1]));

        $this->assertContains(__('core.toolbar.filter_names.inactive'), $chips,
            'চেকবক্সের চিপে নামটা নেই — মানুষ জানবেন না কোন ছাঁকনি চালু।');

        /*
         * ⛔ আর কাঁচা মানটা যেন ফিরে না আসে।
         *
         * এটা উপরের দাবির উল্টো পিঠ, আর একা যথেষ্ট নয় — একা রাখলে
         * চিপ মুছে ফেললেও পাশ করত।
         */
        $this->assertNotContains('1', $chips,
            'চিপে কাঁচা মান "1" ফিরে এসেছে — ওটাই আসল সমস্যা ছিল।');
    }

    /**
     * তারিখ কোম্পানির নিজের ছকে, আর নামটাও সাথে।
     *
     * ⚠️ এখানে নাম **আর** মান দুইটাই লাগে। শুধু "থেকে" লিখলে
     * ব্যবহারকারী জানেন একটা তারিখের ছাঁকনি চালু, কিন্তু **কোন তারিখ
     * থেকে** তা জানেন না — একটা ধাঁধার বদলে আরেকটা ধাঁধা।
     */
    public function test_a_date_chip_carries_the_name_and_the_day(): void
    {
        $from = '2026-09-01';

        $chips = $this->chips(route('governance.audit.index', ['from' => $from]));

        $expected = __('core.toolbar.filter_names.from').': '.DateFormat::format($from);

        $this->assertContains($expected, $chips,
            'তারিখের চিপে নাম ও তারিখ দুইটা একসাথে নেই।');

        /*
         * কাঁচা `2026-09-01` যেন পর্দায় না ওঠে — ওটা ডাটাবেসের ভাষা,
         * মানুষের নয়। ছকটা কোম্পানির সেটিংস থেকে আসে।
         */
        $this->assertNotContains($from, $chips,
            'চিপে কাঁচা ISO তারিখ দেখানো হচ্ছে, কোম্পানির ছকে নয়।');
    }

    /**
     * নাম অজানা হলে আজকের আচরণ অটুট — আর এটা **স্বীকৃত ফাঁক**।
     *
     * ── কেন লেবেল বানিয়ে নেওয়া হয় না ───────────────────────────────
     * `warehouse_id` থেকে "Warehouse id" বানানো যেত। কিন্তু বাংলা
     * পর্দায় ইংরেজি শব্দ বসত, আর অনুবাদ অনুমান করা যায় না।
     *
     * ⓘ বাকি তেরোটা নাম নিজ নিজ পর্দা থেকে `filterLabels` দিয়ে আসবে —
     * সেটা এখনো বাকি, আর এই টেস্টটা সেই বাকিটার হিসাব রাখে। কেউ
     * `warehouse_id`-এর লেবেল বসালে এই টেস্ট লাল হবে, আর তখন এখানকার
     * সারিটা সরানোই সঠিক কাজ।
     */
    public function test_an_unnamed_filter_still_shows_its_value(): void
    {
        $warehouse = \App\Modules\Inventory\Models\Warehouse::query()->firstOrFail();

        $chips = $this->chips(route('inventory.stock.index', ['warehouse_id' => $warehouse->id]));

        $this->assertContains((string) $warehouse->id, $chips,
            'নাম অজানা হলে অন্তত মানটা দেখানোর কথা — সেটাও নেই।');
    }

    /**
     * ⭐ `chips` মোডটা সত্যিই আলাদা কিছু করে।
     *
     * ── কেন এই পরীক্ষাটা দরকার ──────────────────────────────────────
     * মোডটা `Ui.php`-তে ঘোষিত ছিল ৪ সেপ্টেম্বরের আগে থেকেই, নিজের
     * ব্যাখ্যাসহ — আর টুলবার সেটা **জিজ্ঞেসই করত না**: `chips` আর
     * `toggle` তার কাছে এক ছিল।
     *
     * ⛔ ঘোষিত অথচ অপঠিত একটা মোড কাউকে কিছু জানায় না, আর কোনো টেস্ট
     * সেটা ধরত না — কারণ পর্দা ঠিকই খুলত।
     */
    public function test_the_chips_mode_actually_changes_the_button(): void
    {
        $this->assertSame('chips', Ui::filters('navy'),
            'navy আর chips মোডে নেই — তাহলে এই পরীক্ষাটা অন্য কিছু মাপছে।');

        $this->user->forceFill(['ui' => 'navy'])->save();

        $navy = $this->get(route('customer.index'))->assertOk()->getContent();

        $this->assertStringContainsString(__('core.toolbar.add_filter'), (string) $navy,
            'chips মোডে "+ ছাঁকনি" নেই — মোডটা তাহলে এখনো কিছুই বদলাচ্ছে না।');
    }

    /**
     * ⛔ আর বাকি সাজগুলোর বোতাম অটুট।
     *
     * মালিকের নিয়ম (২ সেপ্টেম্বর): ABOS-এর সাজ `navy`, বাকিগুলোর
     * চেহারা বদলানো যাবে না।
     *
     * ⚠️ চিপের **লেখা** সব সাজেই বদলেছে, আর সেটা ইচ্ছাকৃত — "1" দেখানো
     * চেহারা নয়, বাগ। কিন্তু বোতামের **আকার** কেবল navy-তে বদলায়, আর
     * এই পরীক্ষাটা তার পাহারা।
     */
    public function test_the_other_looks_keep_the_plain_filter_button(): void
    {
        $others = collect(Ui::keys())->reject(fn (string $key) => Ui::filters($key) === 'chips');

        $this->assertGreaterThan(5, $others->count(),
            'chips ছাড়া সাজ প্রায় নেই — তাহলে এই পাহারাটা কিছুই দেখছে না।');

        foreach ($others as $look) {
            $this->user->forceFill(['ui' => $look])->save();

            $html = (string) $this->get(route('customer.index'))->assertOk()->getContent();

            $this->assertStringContainsString(__('core.toolbar.filter'), $html,
                "{$look} সাজে সাধারণ Filter বোতামটা হারিয়ে গেছে।");

            $this->assertStringNotContainsString(__('core.toolbar.add_filter'), $html,
                "{$look} সাজে chips মোডের বোতামটা ঢুকে পড়েছে — ন'টা সাজ ছোঁয়ার কথা ছিল না।");
        }
    }
}
