<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নোটিশের পর্দাগুলো সেটিংসের ভাঁজে ছড়িয়ে ছিল।
 *
 * ── ⭐ মালিকের কথা, ২৪ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Notices holo main menu tar vitore sub menu gulo thakbe egulo bare
 * seting e keno dukala? … Bare Notice er vitore notice er sob thakbe.
 * Control Panel setings er baire thakbe bare. & Control Panel e
 * printing ache aber menute keno dila, ekoi jinis dui jaygay keno?"*
 *
 * আর তার পরেই: *"System Administration e master group bad diye direct
 * bare menu bosaw"*।
 *
 * ── ⛔ আগে যা ছিল ───────────────────────────────────────────────────
 * ⓘ বারে চারটা ঘর — ড্যাশবোর্ড, মাস্টার, প্রতিবেদন, সেটিংস — আর
 * সেটিংসের ভাঁজে **দশটা** সারি: পুরনো খাতা, কন্ট্রোল প্যানেল, শাখা
 * মডিউল, চারটা নোটিশের পর্দা, কোম্পানির সেটিংস, ছাপা, নম্বর সিরিজ।
 *
 * ⚠️ নোটিশের চারটা সারি ওখানে **পাশাপাশিও ছিল না** — কোম্পানির সেটিংস
 * আর নম্বর সিরিজের মাঝখানে। ⛔ তাই *"নোটিশের জিনিসগুলো কোথায়"*
 * প্রশ্নের উত্তর ছিল *"সেটিংস খুলে খুঁজুন"*।
 *
 * ── ⚠️ কেন দাবিগুলো আঁকা পাতার উপরে, মেনুর অ্যারের উপরে নয় ──────────
 * ⓘ `module.php`-তে `'loose' => true` লেখা আছে কি না — ওটা আমি নিজেই
 * টাইপ করেছি, তাই ঐ দাবিটা কোনোদিন লাল হয় না।
 *
 * ⛔ আসল প্রশ্নটা তিন হাত পরে: [[MenuBuilder]] চাবিটা সাথে নিয়ে যায়
 * কি না, আর [[shell.modulebar]] ওটা দেখে ঘর আঁকে কি না। ⚠️ যেকোনো
 * একটা হাত ফেলে দিলে সব সবুজ থাকত আর পর্দায় কিছুই বদলাত না —
 * ঠিক যেভাবে এই কাজগুলোর বেশিরভাগ বাগ হয়: কাজটা হয়েছে, জোড়াটা নয়।
 */
final class TheNoticeScreensWereScatteredThroughSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ⭐ নোটিশের চারটা পর্দাই এক ভাঁজে, আর ভাঁজটা বারেই। */
    public function test_every_notice_screen_sits_inside_one_fold_on_the_bar(): void
    {
        $fold = $this->fold('notice');

        foreach ([
            route('system_admin.notice.index'),
            route('system_admin.notice.analytics'),
            route('system_admin.notice.category.index'),
            route('system_admin.notice.template.index'),
        ] as $door) {
            $this->assertStringContainsString(
                'href="'.e($door).'"', $fold,
                'নোটিশের একটা পর্দা নোটিশের ভাঁজের বাইরে: '.$door,
            );
        }
    }

    /**
     * ⛔ আর নোটিশের ভাঁজটা সেটিংসের ভিতরে নয়।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * ⓘ উপরেরটা একা থাকলে চারটা সারি সেটিংসের ভাঁজের **ভিতরে** একটা
     * উপ-ভাঁজ হয়েও সবুজ পেত। ⛔ তখন নোটিশে পৌঁছাতে দুইটা ক্লিক লাগত,
     * আর মালিকের অভিযোগটা — *"seting e keno dukala"* — অক্ষত থাকত।
     */
    public function test_the_notice_fold_is_not_buried_in_settings(): void
    {
        $bar = $this->bar();

        $this->assertStringContainsString(
            'data-modulebar-fold="notice"',
            $this->outsideEveryFold($bar, except: 'notice'),
            'নোটিশের ভাঁজটা অন্য একটা ভাঁজের ভিতরে বসে আছে।',
        );
    }

    /**
     * ⭐ কন্ট্রোল প্যানেল বারের নিজের ঘর।
     *
     * ⓘ মালিকের কথা: *"Control Panel setings er baire thakbe bare"*।
     */
    public function test_the_control_panel_is_a_cell_on_the_bar(): void
    {
        $this->assertStringContainsString(
            'href="'.e(route('system_admin.control-panel')).'"',
            $this->outsideEveryFold($this->bar()),
            'কন্ট্রোল প্যানেল এখনো কোনো ভাঁজের ভিতরে।',
        );
    }

    /**
     * ⭐ মাস্টারের সারিগুলোও সোজা বারে — কোনো ভাঁজ নয়।
     *
     * ⓘ মালিকের কথা: *"master group bad diye direct bare menu bosaw"*।
     */
    public function test_the_master_rows_stand_on_the_bar_themselves(): void
    {
        $loose = $this->outsideEveryFold($this->bar());

        foreach ([
            route('system_admin.company.index'),
            route('system_admin.user.index'),
            route('system_admin.role.index'),
            route('system_admin.ownership.show'),
        ] as $door) {
            $this->assertStringContainsString(
                'href="'.e($door).'"', $loose,
                'মাস্টারের একটা সারি এখনো ভাঁজের ভিতরে: '.$door,
            );
        }
    }

    /**
     * ⛔ ছাপার পর্দাটা বারে **একবারও** নেই।
     *
     * ── ⓘ কেন শূন্য, এক নয় ──────────────────────────────────────────
     * মালিকের কথা: *"Control Panel e printing ache aber menute keno
     * dila, ekoi jinis dui jaygay keno?"*
     *
     * ⚠️ পর্দাটা মুছে ফেলা হয়নি — সে কন্ট্রোল প্যানেলের একটা **ট্যাব**
     * ([[ControlPanelTabs]]), মালিকেরই ২৩ সেপ্টেম্বরের নির্দেশে। ⓘ তাই
     * দরজাটা আছে, কেবল একটাই।
     */
    public function test_printing_has_one_address_not_two(): void
    {
        $this->assertStringNotContainsString(
            'href="'.e(route('system_admin.print_control')).'"',
            $this->bar(),
            'ছাপার পর্দাটা আবার মেনুতে ফিরে এসেছে — কন্ট্রোল প্যানেলে সে এমনিতেই আছে।',
        );
    }

    /**
     * সিস্টেম প্রশাসনের পাতা খুলে কেবল উপরের বারটা কেটে আনে।
     *
     * ── ⚠️ হাতলটা `data-module-bar`, `aria-label` নয় ─────────────────
     * ⓘ একই লেখা নিচের মোবাইল-নেভেও আছে, আর যে চিহ্ন দিয়ে খোঁজা হয়
     * সেটা অনন্য না হলে মাপটাই মিথ্যা ([[shell.modulebar]]-এর নোট)।
     */
    private function bar(): string
    {
        $page = $this->get(route('module.dashboard', ['module' => 'system_admin']))
            ->assertOk()
            ->getContent();

        $from = strpos($page, 'data-module-bar');
        $this->assertNotFalse($from, 'পাতায় মডিউল-বারই নেই — দাবিগুলো কিছুই মাপছে না।');

        $to = strpos($page, '</nav>', $from);
        $this->assertNotFalse($to, 'বারের `nav` শেষ হয়নি।');

        return substr($page, $from, $to - $from);
    }

    /**
     * ভাঁজগুলোর ভিতরটা ফেলে দিয়ে কেবল বারের খোলা ঘরগুলো রাখে।
     *
     * ⚠️ ভাঁজের পাতায় `<div>` নেই — কেবল `<a>` আর আইকন। ⓘ থাকলে নিচের
     * কাটাটা ভুল জায়গায় থামত, তাই ধরে নেওয়া হয়নি, **মাপা হয়েছে**:
     * কেটে আনা টুকরোয় `<div` পাওয়া গেলে দাবিটা নিজেই থেমে যায়।
     */
    private function outsideEveryFold(string $bar, ?string $except = null): string
    {
        return (string) preg_replace_callback(
            '/<div x-show="open".*?data-modulebar-fold="([^"]*)".*?<\/div>/s',
            function (array $m) use ($except): string {
                $this->assertStringNotContainsString(
                    '<div', substr($m[0], 5),
                    'ভাঁজের পাতায় একটা `div` ঢুকেছে — কাটাটা এখন ভুল জায়গায় থামছে।',
                );

                return $m[1] === $except ? $m[0] : '';
            },
            $bar,
        );
    }

    /** একটা নির্দিষ্ট ভাঁজের ভিতরটা। */
    private function fold(string $name): string
    {
        preg_match(
            '/<div x-show="open".*?data-modulebar-fold="'.preg_quote($name, '/').'".*?<\/div>/s',
            $this->bar(), $found,
        );

        $this->assertNotEmpty($found, "বারে `{$name}` নামে কোনো ভাঁজই নেই।");

        return $found[0];
    }
}
