<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Dashboard\CardColours;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মডিউল ড্যাশবোর্ড মালিকের রঙিন নমুনার রং পরে (মালিক, ৩১ অক্টোবর ২০২৬: "desh board e multi color ba 10 12 ti color used korte
 * bolecilam", "Sample file dekhe koro"; নমুনা: claude.ai artifact 4YJNJQosVyXzJjEYzEMvbv "সব মডিউলের রঙিন ড্যাশবোর্ড")।
 *
 * ⛔ আগে (প্রথম খসড়া): গোটা ড্যাশবোর্ড এক মডিউলের এক রঙে — নমুনায় বারো রং, প্রতিটা কার্ড আলাদা।
 *
 * দাবি:
 *   ক — মানহীন কার্ড নমুনার ক্রমে (বিক্রয়: নীল · আকাশি · কমলা · সবুজ);
 *   খ — "এখনই দেখুন" লাল, "নজর দিন" হলুদ; একই মানের দ্বিতীয়টা পরের ছায়ায় — চারটার মধ্যে কোনো রং দুইবার নয়;
 *   গ — মানহীন কার্ডে লাল বা হলুদ কখনো নয় (নমুনায় পরের রং লাল হলেও);
 *   ঘ — নমুনার প্রতিটা রং `tokens.css`-এ আছে, আর সাদা লেখা তার উপরে ≥ ৪.৫ঃ১;
 *   ঙ — পাতায় মাথার পট্টি নমুনার দুই রঙের ঢাল, আর কার্ডগুলো এই নিয়মের রং পরে।
 */
final class TheDashboardWearsTheSamplesColoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_k_cards_without_meaning_follow_the_sample(): void
    {
        $this->assertSame(['blue', 'sky', 'orange', 'green'], CardColours::names('sales', [Stat::GOOD, Stat::NEUTRAL, Stat::GOOD, Stat::NEUTRAL]),
            '⛔ রঙিন ড্যাশবোর্ড: বিক্রয়ের কার্ড নমুনার রঙে নয় (নীল · আকাশি · কমলা · সবুজ)।');
    }

    public function test_kh_meaning_colours_and_no_colour_twice(): void
    {
        $this->assertSame(['red', 'amber', 'purple', 'yellow'], CardColours::names('purchase', [Stat::BAD, Stat::NEUTRAL, Stat::GOOD, Stat::WARN]),
            '⛔ রঙিন ড্যাশবোর্ড: "জরুরি" লাল নয়, বা "সতর্কতা" হলুদ নয়।');

        $two = CardColours::names('inventory', [Stat::NEUTRAL, Stat::NEUTRAL, Stat::WARN, Stat::WARN]);
        $this->assertSame(['yellow', 'amber'], array_slice($two, 2), '⛔ রঙিন ড্যাশবোর্ড: দুই "সতর্কতা" পাশাপাশি একই হলুদ — পরেরটা অ্যাম্বার হওয়ার কথা।');

        foreach (array_keys((array) config('abos.dashboard_colours')) as $module) {
            foreach ([[Stat::BAD, Stat::BAD, Stat::WARN, Stat::WARN], [Stat::GOOD, Stat::GOOD, Stat::GOOD, Stat::GOOD]] as $tones) {
                $names = CardColours::names($module, $tones);
                $this->assertSame(count($names), count(array_unique($names)), "⛔ রঙিন ড্যাশবোর্ড: {$module}-এ একই রং দুইবার: ".implode(', ', $names));
            }
        }
    }

    public function test_g_red_and_yellow_only_carry_meaning(): void
    {
        foreach (array_keys((array) config('abos.dashboard_colours')) as $module) {
            $names = CardColours::names($module, [Stat::GOOD, Stat::NEUTRAL, Stat::GOOD, Stat::NEUTRAL]);
            $this->assertSame([], array_values(array_intersect($names, ['red', 'yellow'])),
                "⛔ রঙিন ড্যাশবোর্ড: {$module}-এ মানহীন কার্ড লাল বা হলুদ — লাল মানে \"এখনই দেখুন\", সব মডিউলে।");
        }
    }

    public function test_gh_every_sample_colour_exists_and_carries_white_text(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/tokens.css')));
        $names = collect((array) config('abos.dashboard_colours'))->flatMap(fn (array $p) => [...$p['head'], ...$p['cards']])
            ->merge(['red', 'yellow', 'amber', 'pink', 'orange', 'green', 'purple', 'teal', 'sky', 'indigo', 'blue', 'lime'])->unique();
        $problems = [];
        foreach ($names as $name) {
            if (preg_match('/--color-pal-'.$name.':\s*(#[0-9a-fA-F]{6})\s*;/', $css, $hit) !== 1) {
                $problems[] = "{$name}: --color-pal-{$name} নেই";

                continue;
            }
            if (($ratio = $this->onWhite($hit[1])) < 4.5) {
                $problems[] = sprintf('%s: %s — সাদা লেখা %.2f:1', $name, $hit[1], $ratio);
            }
        }
        $this->assertSame([], $problems, "⛔ রঙিন ড্যাশবোর্ড: নমুনার রং নেই, বা তার উপরে সাদা লেখা পড়া যায় না:\n".implode("\n", $problems));
    }

    public function test_ng_the_page_wears_the_head_gradient_and_the_card_colours(): void
    {
        config(['abos.dashboards_v2' => true]);
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $html = (string) $this->actingAs($owner)->get(route('module.dashboard', 'sales'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-dashboard-head="sales"[^>]*style="background: linear-gradient\(100deg, var\(--color-pal-blue\), var\(--color-pal-sky\)\)"/s', $html,
            '⛔ রঙিন ড্যাশবোর্ড: বিক্রয়ের মাথার পট্টি নমুনার নীল → আকাশি নয়।');
        preg_match_all('/data-stat-colour="([^"]+)"/', $html, $cards);
        $this->assertCount(4, $cards[1], '⛔ রঙিন ড্যাশবোর্ড: উপরে চারটা রঙিন কার্ড নেই।');
        $this->assertSame(count($cards[1]), count(array_unique($cards[1])), '⛔ রঙিন ড্যাশবোর্ড: পাতায় একই রঙের দুই কার্ড।');
    }

    private function onWhite(string $hex): float
    {
        $channel = function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $hex = ltrim($hex, '#');
        $l = 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));

        return 1.05 / ($l + 0.05);
    }
}
