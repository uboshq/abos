<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Core\Support\PageCss;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ প্রতিটা পাতা নিজের রূপ আর নিজের পাতার CSS পায় — আর কেবল সেটাই, ঠিক ক্রমে (abos-b1, ২৮ নভেম্বর ২০২৬)।
 *
 * ── কেন ───────────────────────────────────────────────────────────────
 * আগে সব রূপ আর সব পাতার CSS একটা app.css-এ, প্রতিটা পাতায় (Lighthouse: প্রতি পাতায় ২৩৭ KiB অব্যবহৃত)। এখন ভাগ করা
 * ([[PageCss]])। ⛔ ভাগ করার বিপদ দুইটা, আর এই পরীক্ষা দুইটাই ধরে:
 *   ১. কোনো পাতা নিজের CSS পায় না — কাউন্টার সাদামাটা দেখাত, "ABOS" রূপ Navy-র খোলসে।
 *   ২. ক্রম উল্টে যায় — রূপের ফাইল app.css-এর পরে বসলে app.css-এর তিন রূপের ভাগ করা নিয়মগুলো নীরবে হারত।
 */
final class EveryPageCssIsBuiltTest extends TestCase
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
    }

    /** @return array<string, string> উৎস → বিল্ড করা ফাইলের নাম */
    private function manifest(): array
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $out = [];

        foreach (PageCss::all() as $entry) {
            $this->assertIsArray($manifest[$entry] ?? null, "⛔ {$entry} বিল্ড হয়নি — vite.config.js-এর input-এ নেই, বা npm run build চলেনি।");
            $out[$entry] = (string) $manifest[$entry]['file'];
        }

        return $out;
    }

    /** @return list<string> পাতার `<head>`-এ যে CSS-গুলো আসে, ক্রমে — উৎসের নাম ধরে */
    private function sheetsOn(string $html): array
    {
        $byFile = array_flip($this->manifest());
        preg_match_all('#<link rel="stylesheet" href="[^"]*/build/(assets/[^"]+\.css)"#', $html, $m);

        return array_values(array_map(fn (string $file) => $byFile[$file] ?? 'অচেনা: '.$file, $m[1]));
    }

    public function test_vite_builds_exactly_the_css_page_css_knows(): void
    {
        $config = (string) file_get_contents(base_path('vite.config.js'));
        preg_match_all("#'(resources/css/[^']+\.css)'#", $config, $m);

        $this->assertSame(PageCss::all(), $m[1], '⛔ vite.config.js-এর CSS আর PageCss-এর তালিকা মেলে না — একটায় নাম লিখে অন্যটায় ভোলা।');
        $this->manifest();
    }

    public function test_each_look_gets_its_own_shell_css_and_no_other_in_the_order_the_single_file_had(): void
    {
        foreach (['rose' => [PageCss::LOOKS['rose']], 'blue' => [PageCss::LOOKS['blue']], 'tiles' => [PageCss::LOOKS['tiles']],
            'dynamic' => [PageCss::LOOKS['dynamic']], 'navy' => []] as $look => $own) {
            $this->owner->forceFill(['ui' => $look])->save();
            $html = (string) $this->actingAs($this->owner->fresh())->get(route('approval.inbox.index'))->assertOk()->getContent();

            $this->assertSame([PageCss::HEAD, ...$own, PageCss::BODY], $this->sheetsOn($html),
                "⛔ {$look}: পাতার CSS ভুল — base.css, নিজের রূপ (থাকলে), app.css — ঠিক এই ক্রমে নয়।");
        }
    }

    public function test_the_counter_gets_the_counter_css_between_the_look_and_app_css_and_the_home_does_not(): void
    {
        $this->owner->forceFill(['ui' => 'rose'])->save();
        $owner = $this->owner->fresh();

        $counter = (string) $this->actingAs($owner)->get(route('sales.direct.create'))->assertOk()->getContent();
        $this->assertSame([PageCss::HEAD, PageCss::LOOKS['rose'], PageCss::PAGES['counter'], PageCss::BODY], $this->sheetsOn($counter),
            '⛔ সরাসরি বিক্রয়ের পাতা কাউন্টারের CSS পায় না, বা ভুল জায়গায়।');

        $home = (string) $this->actingAs($owner)->get(route('approval.inbox.index'))->assertOk()->getContent();
        $this->assertNotContains(PageCss::PAGES['counter'], $this->sheetsOn($home), '⛔ কাউন্টারের CSS কাউন্টারের বাইরের পাতাতেও যায়।');

        // ⓘ কাউন্টার মোডের সারি যেকোনো পাতায় — তখন কাউন্টারের CSS-ও, একবারই
        $strip = (string) $this->actingAs($owner)->get(route('approval.inbox.index', ['counter' => 1]))->assertOk()->getContent();
        $this->assertSame(1, array_count_values($this->sheetsOn($strip))[PageCss::PAGES['counter']] ?? 0,
            '⛔ কাউন্টার মোডের পাতায় কাউন্টারের CSS নেই, বা দুইবার।');
    }

    public function test_a_page_css_name_that_does_not_exist_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PageCss::page('counterr');
    }
}
