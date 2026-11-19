<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Core\Support\Ui;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ নতুন "ABOS" (`rose`, ১৯ নভেম্বর ২০২৬ থেকে ডিফল্ট) — মালিকের অনুমোদিত নমুনা (claude.ai artifact AAjAfjZ8TnCfnjtt3Kthjz v11)।
 *
 * দাবি:
 *   ১ নতুন মানুষ "ABOS" পান; বাঁয়ের রেল নেই, পাতার মাথায় মডিউল-বার বা পথ নেই — মেনু আর কাজের প্যানেল সরাসরি
 *   ২ মেনুর নিচে মডিউল-তালিকা: রেলের **হুবহু** মডিউলগুলো ([[MenuBuilder::forUser()]], যার খোলা পাতা আছে), প্রত্যেকটা নিজের
 *     Lucide চিহ্নে রঙিন টাইলে, চলতি মডিউল চিহ্নিত, খোঁজার ঘর, "⇅ সাজান" আর "আগের ক্রম"
 *   ৩ সাজানো ক্রম নিজের সারিতে (রেলের একই ঘর `users.nav_layout`, একই দরজা `rail.layout`) — পরের পাতায় সেই ক্রমে, অন্য কারও নয়;
 *     "আগের ক্রম" নিজের ক্রম মোছে, পিন রাখে
 *   ৪ নিচের বার: চিকন পিলের গড়ন কেবল এই রূপে, চলন্ত নোটিশের আগে নীল "Notice"
 *   ৫ উপরের রঙিন ⊞ একই তালিকা খোলে (তালিকা পর্দায় থাকলে)
 */
final class TheModulePickerSitsUnderTheMenuTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_a_new_person_wears_the_new_abos_without_the_rail(): void
    {
        $this->assertSame('rose', User::factory()->create()->fresh()->ui ?? Ui::DEFAULT, '⛔ নতুন মানুষ নতুন "ABOS" পান না।');
        $this->assertSame('rose', Ui::clean($this->owner->fresh()->ui), 'প্রস্তুতিটাই ভুল — মালিক নতুন "ABOS"-এ নেই।');

        $html = (string) $this->get(route('sales.invoice.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-ui="rose"', $html);
        $this->assertStringNotContainsString('data-rail-module=', $html, '⛔ "ABOS"-এ বাঁয়ের রেল আঁকা হলো।');
        // ⭐ মেনু-সারি আছে, কিন্তু কেবল মেনু গুটালে দেখা দেয় (মালিক, ১৯ নভেম্বর ২০২৬ রাত: "মেনু বারটা হাইড করলে উপর দিকে চলে যেত মেনুগুলা")
        $this->assertStringContainsString('data-module-bar', $html, '⛔ "ABOS"-এ মেনু গুটালে উপরে আসার মেনু-সারিটাই নেই।');
        $css = (string) file_get_contents(resource_path('css/abos-look.css'));
        $this->assertStringContainsString(":root[data-ui='rose']:not(:has([data-site-map][style*='display: none'])) [data-module-bar]", $css,
            '⛔ "ABOS"-এ মেনু খোলা থাকলেও উপরের মেনু-সারি দেখা দেয় — একই মেনু দুই জায়গায়।');
        $this->assertStringNotContainsString('data-page-path', $html, '⛔ "ABOS"-এ পাতার পথ — নমুনায় নেই।');
        $this->assertStringContainsString('data-site-map', $html, '⛔ মেনুটাই নেই।');

        // ⓘ ABOS Blue বেছে নিলে রেল আর মডিউল-বার ফেরে — অন্য রূপ ছোঁয়া হয়নি
        $this->owner->forceFill(['ui' => 'blue'])->save();
        $blue = (string) $this->actingAs($this->owner->fresh())->get(route('sales.invoice.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-rail-module=', $blue, '⛔ ABOS Blue-র রেল হারাল।');
        $this->assertStringNotContainsString('data-module-picker', $blue, '⛔ মডিউল-তালিকা অন্য রূপেও বসল।');
    }

    public function test_the_list_holds_exactly_the_modules_the_menu_opens_each_with_its_own_icon(): void
    {
        $html = (string) $this->get(route('sales.invoice.index'))->assertOk()->getContent();
        $picker = $this->between($html, 'data-module-picker', 'data-modpick');

        $expected = [];
        foreach (app(MenuBuilder::class)->arranged(app(MenuBuilder::class)->forUser($this->owner), $this->owner) as $module) {
            if (collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null) !== null) {
                $expected[] = $module['code'];
            }
        }
        preg_match_all('/data-picker-row="([a-z_]+)"/', $picker, $rows);
        $this->assertGreaterThan(8, count($expected), 'প্রস্তুতিটাই ভুল — মালিকের মেনুতে মডিউলই নেই।');
        $this->assertSame($expected, $rows[1], '⛔ তালিকার মডিউল (বা ক্রম) রেলের/মেনুর সাথে মেলে না।');

        // ⓘ প্রতিটা সারিতে Lucide চিহ্ন, রঙিন টাইলে; বিক্রয়ে আছি — বিক্রয় চিহ্নিত
        $this->assertSame(count($expected), substr_count($picker, 'class="abos-modtile"'), '⛔ কোনো সারিতে রঙিন টাইল নেই।');
        $this->assertSame(count($expected), preg_match_all('/abos-modtile"[^>]*>\s*<svg[^>]*data-licon="[a-z0-9-]+"/', $picker), '⛔ কোনো সারিতে Lucide চিহ্ন নেই।');
        $this->assertStringContainsString('data-licon="shopping-bag"', $this->between($picker, 'data-picker-row="sales"', '</li>'), '⛔ বিক্রয়ের চিহ্ন নমুনার নয়।');
        $this->assertMatchesRegularExpression('/data-picker-row="sales".*?aria-current="true"/s', $picker, '⛔ চলতি মডিউল চিহ্নিত নয়।');
        $this->assertSame(1, substr_count($picker, 'aria-current="true"'), '⛔ একাধিক মডিউল "এখানে আছি"।');

        // ⓘ খোঁজা, সাজানো, আগের ক্রম, ▲ ▼
        foreach (['data-picker-search', 'data-arrange', 'data-reset', 'data-up', 'data-down'] as $mark) {
            $this->assertStringContainsString($mark, $picker, "⛔ তালিকায় {$mark} নেই।");
        }
        $this->assertStringContainsString('data-save="'.route('rail.layout').'"', $html, '⛔ সাজানো ক্রম রাখার দরজা রেলেরটা নয়।');
    }

    public function test_the_arranged_order_is_kept_on_the_person_and_reset_keeps_the_pins(): void
    {
        $codes = array_column(app(MenuBuilder::class)->forUser($this->owner), 'code');
        $last = end($codes);

        $this->postJson(route('rail.layout'), ['order' => [$last, ...array_slice($codes, 0, -1)], 'pinned' => [$codes[0]]])->assertOk();
        $html = (string) $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/data-picker-row="([a-z_]+)"/', $this->between($html, 'data-module-picker', 'data-modpick'), $first);
        $this->assertSame($last, $first[1] ?? null, '⛔ সাজানো ক্রম তালিকায় বসেনি।');

        // ⛔ অন্য মানুষের তালিকা বদলায়নি
        $other = User::query()->where('email', '!=', 'owner@abos.test')->whereNull('nav_layout')->firstOrFail();
        $this->assertNull($other->fresh()->nav_layout, '⛔ একজনের ক্রম আরেকজনের ঘরে গেছে।');

        // ⓘ "আগের ক্রম" — নিজের ক্রম মোছা, পিন থাকে (তালিকা যা পাঠায় হুবহু)
        $this->actingAs($this->owner->fresh())->postJson(route('rail.layout'), ['order' => [], 'pinned' => [$codes[0]]])->assertOk();
        $layout = $this->owner->fresh()->nav_layout;
        $this->assertSame([], $layout['order'] ?? null, '⛔ "আগের ক্রম" নিজের ক্রম মোছেনি।');
        $this->assertSame([$codes[0]], $layout['pinned'] ?? null, '⛔ "আগের ক্রম" রেলের পিন মুছে দিল।');
    }

    public function test_the_footer_carries_the_notice_tag_and_the_pill_lives_only_in_this_look(): void
    {
        $chrome = (string) file_get_contents(resource_path('views/components/shell/chrome/rose.blade.php'));
        $this->assertStringContainsString("region === 'ticker-start'", $chrome);
        $this->assertStringContainsString('data-notice-tag', $chrome);
        $this->assertStringContainsString('<x-shell.chrome region="ticker-start" />',
            (string) file_get_contents(resource_path('views/components/shell/statusbar.blade.php')), '⛔ নিচের বারে রূপের "Notice"-এর জায়গা নেই।');

        // ⓘ চিকন গোল পিল, কিনারা থেকে ভেতরে — কেবল এই রূপে; চলা থামানোর বোতাম আর কম-নড়াচড়ার নিয়ম ফুটারের নিজের, অটুট
        $css = (string) file_get_contents(resource_path('css/abos-look.css'));
        $this->assertMatchesRegularExpression("/:root\\[data-ui='rose'\\] footer\\[data-footer\\] \\{[^}]*border-radius: 999px/", $css, '⛔ নিচের বার পিল নয়।');
        $this->assertStringContainsString('--abos-pill-h: 28px', $css, '⛔ নিচের পিল চিকন (~২৮px) নয়।');
        $bar = (string) file_get_contents(resource_path('views/components/shell/statusbar.blade.php'));
        $this->assertStringContainsString('data-ticker-toggle', $bar, '⛔ চলা থামানোর বোতাম হারাল।');
        $this->assertStringContainsString('motion-safe:', $bar, '⛔ কম-নড়াচড়ার নিয়ম হারাল।');

        // ⓘ দুইটা দুই জায়গায় (মালিক, ১৯ নভেম্বর ২০২৬ রাত): উপরের ⊞ মাঝের পপআপ খোলে, মেনুর নিচের তালিকা নিজের বোতামে
        $shell = (string) file_get_contents(resource_path('js/components/shell.js'));
        $this->assertStringNotContainsString("new CustomEvent('open-module-picker')", $shell, '⛔ ⊞ আবার মেনুর নিচের তালিকা খুলছে — ওটার নিজের পপআপ।');
        $this->assertMatchesRegularExpression("/:root\\[data-ui='rose'\\] \\[data-module-launcher-sheet\\]/", $css, '⛔ ⊞-এর পাতা মাঝের পপআপ নয়।');
        $this->assertStringContainsString('@open-module-picker.window="toggle()"', $chrome);
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "পাতায় {$from} নেই।");
        $end = strpos($html, $to, $start + strlen($from));

        return substr($html, $start, $end === false ? null : $end - $start + strlen($to));
    }
}
