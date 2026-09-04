<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সাইডবারের ক্রম — মালিকের দেওয়া কাঠামো, ২ সেপ্টেম্বর ২০২৬।
 *
 * ── কেন এটার একটা টেস্ট দরকার ────────────────────────────────────────
 * ক্রমটা আসে বারোটা আলাদা `module.php`-র `nav` ঘোষণা থেকে। অর্থাৎ এটা
 * এমন এক জিনিস যা **কোথাও এক জায়গায় লেখা নেই** — যে কেউ নিজের মডিউলের
 * `order` বদলে দিলে পুরো তালিকা নড়ে যাবে, আর কোনো ভুলের বার্তা আসবে না।
 * ছড়ানো ঘোষণার সুবিধা (§১৯.৭) আর তার ঝুঁকি একই মুদ্রার দুই পিঠ; এই
 * ফাইলটা সেই ঝুঁকির দিকটা ধরে রাখে।
 *
 * ── কেন এখানে মডিউলের নাম লেখা আছে, অথচ কোরে নেই ─────────────────────
 * কোরে নাম লেখা নিষিদ্ধ কারণ কোরকে জানতে হয় না কারা আছে। কিন্তু টেস্টের
 * কাজই হলো **মালিক যা চেয়েছেন তা হুবহু লিখে রাখা** — নাম ছাড়া সেটা লেখা
 * যায় না, আর তালিকাটা এখানে না থাকলে টেস্টটা শুধু বলত "কিছু একটা ক্রমে
 * আছে", যা কোনো পাহারা নয়।
 */
class TheMenuStandsInTheOrderTheOwnerAskedForTest extends TestCase
{
    use RefreshDatabase;

    /**
     * মালিকের পাঠানো তালিকা, হুবহু — ২ সেপ্টেম্বর ২০২৬।
     *
     * ড্যাশবোর্ড এখানে নেই: ওটা কোনো মডিউল নয়, [[shell.sidebar]] ওটাকে
     * রেলের মাথায় আলাদা করে আঁকে।
     */
    private const AS_HE_ASKED = [
        ['top', 'master_data'],

        ['finance', 'accounts'],
        ['finance', 'finance'],

        /*
         * ── গেল, আর ফিরে এল — একই দিনে, ৪ সেপ্টেম্বর ২০২৬ ──────────────
         * সকালে মালিক গ্রাহককে বিক্রয়ের ভেতরে আর সরবরাহকারীকে ক্রয়ের
         * ভেতরে ঢোকাতে বলেছিলেন, আর এই দুইটা সারি তখন এখান থেকে উঠে
         * গিয়েছিল। **বিকেলে তিনি নিজেই ফিরিয়ে নিয়েছেন** — *"আগের মতো
         * বাইরেই রাখো, সেটাই মনে হয় ভালো।"*
         *
         * তাই তালিকাটা আবার ২ সেপ্টেম্বরের রূপে। ⓘ ইতিহাসটা রাখা হলো
         * কারণ `MenuBuilder`-এ ভেতরে বসানোর যন্ত্রটা এখনো আছে, আর ছয় মাস
         * পরে কেউ ওটা দেখে ভাববেন তালিকাটা ভুল।
         */
        ['business', 'customer'],
        ['business', 'supplier'],
        ['business', 'purchase'],
        ['business', 'inventory'],
        ['business', 'sales'],

        ['people', 'hr'],
        ['people', 'governance'],
        ['people', 'approval'],

        ['system', 'system_admin'],
    ];

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

    public function test_the_sidebar_stands_in_the_order_he_asked_for(): void
    {
        $menu = app(MenuBuilder::class)->forUser($this->owner());

        $actual = array_map(
            static fn (array $m): array => [$m['section'], $m['code']],
            $menu,
        );

        $this->assertSame(
            self::AS_HE_ASKED,
            $actual,
            "সাইডবারের ক্রম মালিকের দেওয়া কাঠামোর সাথে মেলেনি।\n"
            .'ক্রমটা `module.php`-র nav.section আর nav.order থেকে আসে — '
            .'একটা মডিউলের order বদলালে পুরো তালিকা নড়ে।',
        );
    }

    /**
     * নির্ভরতার ক্রম আর চোখের ক্রম — দুইটা আলাদা, আর আলাদাই থাকতে হবে।
     *
     * এই টেস্টটা নিশ্চিত করে যে দুইটা সত্যিই ভিন্ন। এক হয়ে গেলে বুঝতে
     * হবে কেউ মেনুর ক্রম ফিরিয়ে নিয়েছে [[ModuleRegistry::sortByDependency()]]-তে,
     * আর তখন পরের মডিউলটা যোগ করলেই মেনু আবার নিজে থেকে নড়বে।
     */
    public function test_the_human_order_is_not_the_boot_order(): void
    {
        $boot = array_map(
            static fn (ModuleDefinition $d): string => $d->code,
            array_values(app(ModuleRegistry::class)->all()),
        );

        $human = array_column(self::AS_HE_ASKED, 1);

        $this->assertNotSame(
            $boot,
            $human,
            'নির্ভরতার ক্রম আর সাইডবারের ক্রম এক হয়ে গেছে। '
            .'দুইটা আলাদা প্রশ্নের উত্তর — একটা মেশিনের, একটা মানুষের।',
        );

        /*
         * তবু দুইটাতে একই মডিউলগুলোই থাকতে হবে, নাহলে একটা কোথাও হারিয়েছে।
         *
         * রেলে এখন সবাই থাকে না: যারা `nav.under` দিয়ে অন্যের ভেতরে বসে
         * তাদের নিজের টাইল নেই। তাই তুলনাটা রেল **আর** অতিথিদের নিয়ে —
         * এতে "হারিয়ে গেছে" আর "ভেতরে বসেছে" আলাদা থাকে। প্রথমটা ভুল,
         * দ্বিতীয়টা মালিকের সিদ্ধান্ত।
         */
        $guests = array_map(
            static fn (ModuleDefinition $d): string => $d->code,
            array_values(array_filter(
                app(ModuleRegistry::class)->all(),
                static fn (ModuleDefinition $d): bool => isset($d->nav['under']),
            )),
        );

        sort($boot);
        $sortedHuman = array_merge($human, $guests);
        sort($sortedHuman);

        $this->assertSame($sortedHuman, $boot, 'দুইটা ক্রমে একই মডিউলগুলো নেই।');
    }

    /**
     * অতিথি মডিউল সত্যিই আশ্রয়দাতার ভেতরে বসেছে — হারিয়ে যায়নি।
     *
     * ── কেন এই পাহারাটা দরকার ────────────────────────────────────────
     * উপরের `AS_HE_ASKED` থেকে গ্রাহক ও সরবরাহকারী তুলে দেওয়া হয়েছে।
     * ওই একটা বদল **নিজে থেকেই** সবুজ থাকত যদি মডিউল দুইটা রেল থেকে
     * উধাও হয়ে যেত আর কোথাও না বসত — অর্থাৎ পর্দাগুলো আর কোনো মেনু
     * থেকে খোলা যেত না, আর কোনো টেস্ট কিছু বলত না।
     *
     * তাই এখানে **উল্টো দিকটা** দেখা হয়: সারিগুলো আশ্রয়দাতার টাইলে
     * আছে তো? আর অতিথির নিজের টাইলটা সত্যিই নেই তো?
     */
    public function test_a_guest_module_sits_inside_its_host(): void
    {
        $menu = app(MenuBuilder::class)->forUser($this->owner());
        $codes = array_column($menu, 'code');

        $guests = array_filter(
            app(ModuleRegistry::class)->all(),
            static fn (ModuleDefinition $d): bool => isset($d->nav['under']),
        );

        /*
         * ⚠️ আজ একটাও অতিথি নেই — মালিক ৪ সেপ্টেম্বর সিদ্ধান্ত ফিরিয়ে
         * নিয়েছেন, তাই গ্রাহক ও সরবরাহকারী আবার নিজের টাইলে।
         *
         * ── কেন এটা চুপচাপ সবুজ হয় না ────────────────────────────────
         * খালি তালিকার উপর চালানো লুপ **সবসময় পাস করে**, আর তখন এই
         * ফাইলটা দেখে মনে হত পাহারাটা কাজ করছে। কেউ কাল আবার `under`
         * বসালে সে ভাবত গার্ডটা তাকে দেখছে — অথচ ওটা মাস ছয়েক ধরে
         * কিছুই দেখেনি।
         *
         * তাই জোরে বলে থামা: পাহারাটা আছে, কিন্তু আজ পাহারা দেওয়ার
         * কিছু নেই।
         */
        if ($guests === []) {
            $this->markTestSkipped(
                'কোনো মডিউল nav.under ঘোষণা করে না, তাই ভেতরে বসার কিছু নেই। '
                .'কেউ আবার under বসালে এই পরীক্ষাটা নিজে থেকেই জেগে উঠবে।'
            );
        }

        foreach ($guests as $module) {
            $host = $module->nav['under'] ?? null;

            if ($host === null) {
                continue;
            }

            $this->assertNotContains(
                $module->code,
                $codes,
                "{$module->code} নিজের টাইল নিয়েই রেলে দাঁড়িয়ে আছে, অথচ তার nav.under বলছে "
                ."সে {$host}-এর ভেতরে বসার কথা।",
            );

            $tile = collect($menu)->firstWhere('code', $host);

            $this->assertNotNull(
                $tile,
                "{$module->code}-এর আশ্রয়দাতা {$host} মেনুতেই নেই, তাই তার সারিগুলো কোথাও বসেনি।",
            );

            $this->assertContains(
                $module->code,
                $tile['codes'],
                "{$host}-এর টাইল {$module->code}-এর রুটগুলো নিজের বলে চেনে না, তাই ওই পাতাগুলোয় "
                .'কোনো টাইলে "এখানে আছি" দাগ পড়বে না।',
            );

            /* সারিগুলো সত্যিই ঢুকেছে — কী-তে অতিথির কোড বসে। */
            $carried = collect($tile['groups'])->keys()
                ->filter(fn (string $key): bool => str_starts_with($key, $module->code.':'));

            $this->assertNotEmpty(
                $carried,
                "{$host}-এর টাইলে {$module->code}-এর একটা সারিও নেই — মডিউলটা রেল থেকেও গেছে, "
                .'ভেতরেও বসেনি।',
            );
        }
    }

    /**
     * প্রতিটা মডিউল নিজে বলে সে কোথায় বসবে।
     *
     * `ModuleDefinition` এটা বুট-টাইমেই ছুঁড়ে ফেলে, তাই এই টেস্টটা
     * কার্যত সেই নিয়মটার একটা রসিদ — কেউ ডিফল্ট বসিয়ে নিয়মটা নরম করে
     * ফেললে এটা লাল হবে।
     */
    public function test_every_module_says_where_it_belongs(): void
    {
        foreach (app(ModuleRegistry::class)->all() as $module) {
            $this->assertContains(
                $module->nav['section'],
                ModuleDefinition::NAV_SECTIONS,
                "{$module->code} একটা অচেনা দলে বসতে চাইছে।",
            );

            $this->assertIsInt($module->nav['order'], "{$module->code}-এর nav order সংখ্যা নয়।");
        }
    }

    /**
     * ড্যাশবোর্ডে পাশের প্যানেলটা রেফারেন্স ডাটা দেখায় না।
     *
     * ── কী ধরার জন্য ─────────────────────────────────────────────────
     * [[shell.sidebar]] কোনো মডিউল সক্রিয় না থাকলে একটা বেছে নেয়। আগে
     * ওটা ছিল `$menu[0]`, আর নতুন ক্রমে `$menu[0]` মানে **মাস্টার ডাটা** —
     * একক, ব্র্যান্ড, কারণ কোড। ফলে রোজ সকালে সবাই এমন একটা তালিকা
     * দেখতেন যা বছরে কয়েকবার লাগে।
     *
     * সারাইটা ছিল "প্রথম মডিউল" নয়, "প্রথম কাজের মডিউল"। এই টেস্টটা
     * সেটাই ধরে রাখে — কেউ `$menu[0]`-এ ফিরে গেলে লাল হবে।
     */
    public function test_the_dashboard_does_not_open_on_reference_data(): void
    {
        $html = $this->actingAs($this->owner())->get('/')->assertOk()->getContent();

        $menu = app(MenuBuilder::class)->forUser($this->owner());

        $daily = collect($menu)->first(fn (array $m): bool => $m['section'] !== 'top');
        $reference = collect($menu)->first(fn (array $m): bool => $m['section'] === 'top');

        $this->assertNotNull($daily);
        $this->assertNotNull($reference);

        /*
         * প্যানেলটা নিজের `data-active-module`-এ কোডটা বলে।
         *
         * নাম ধরে না খোঁজার কারণ ওই অ্যাট্রিবিউটের মন্তব্যেই লেখা:
         * "মাস্টার ডাটা" শব্দটা মেনুর সারিতেও আছে, তাই কাঁচা HTML-এ
         * নাম খুঁজলে গার্ডটা সবসময় লাল থাকত — অর্থাৎ অকেজো।
         */
        $this->assertMatchesRegularExpression(
            '/data-active-module="'.preg_quote($daily['code'], '/').'"/',
            (string) $html,
            'ড্যাশবোর্ডে পাশের প্যানেল ভুল মডিউল খুলেছে — '
            ."'{$reference['label']}' রোজকার কাজ নয়, আর ফলব্যাক সম্ভবত \$menu[0]-এ ফিরে গেছে।",
        );
    }
}
