<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\AiMark;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ⭐ The living "Ai" mark of Copilot — owner, 21 Nov 2026 (sample copilot-icon-options, option ★ o7; golden i-dot v5).
 *
 * (1) In the ABOS looks (ABOS Smart = rose, Navy, Blue) the top-bar Copilot button draws [[x-ui.ai-mark]] alone — no
 *     "Copilot" text — and keeps its tooltip/aria-label with Ctrl+J.
 * (2) ⛔ SAP (tiles) and Dynamic stay 100% as they were: no Ai mark anywhere on the page; the old brain stays.
 * (3) The component: no background, a gradient id of its own per instance, the golden dot, and a "thinking" state.
 */
final class CopilotAiMarkOnlyInAbosLooksTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    public function test_the_abos_looks_draw_the_ai_mark_in_the_top_bar_without_the_copilot_text(): void
    {
        foreach (AiMark::LOOKS as $look) {
            $button = $this->copilotButton($this->pageAs($look));

            $this->assertNotSame('', $button, "{$look}: the top bar has no Copilot button");
            $this->assertStringContainsString('data-ai-mark', $button, "{$look}: the top-bar Copilot button does not draw the Ai mark");
            $this->assertStringContainsString('cq-btn-ai', $button, "{$look}: the button is not sized to the search box");
            $this->assertStringContainsString('width="40" height="40"', $button, "{$look}: the mark is not as tall as the search box (40px)");
            $this->assertStringNotContainsString('cq-label', $button, "{$look}: the \"Copilot\" text is still under the mark");
            $this->assertStringNotContainsString('cq-orb-mark', $button, "{$look}: the old brain is still on the button");
            $this->assertStringContainsString('(Ctrl+J)', $button, "{$look}: the tooltip / aria-label lost Ctrl+J");
            $this->assertMatchesRegularExpression('/aria-label="[^"]*\(Ctrl\+J\)"/', $button, "{$look}: no aria-label on the button");
        }
    }

    public function test_sap_and_dynamic_keep_their_old_mark_and_never_draw_the_ai_mark(): void
    {
        foreach (['tiles', 'dynamic'] as $look) {
            $this->assertFalse(AiMark::isFor($look), "{$look} is counted as an ABOS look");

            $html = $this->pageAs($look);

            $this->assertStringNotContainsString('data-ai-mark', $html, "{$look}: the Ai mark leaked into a look that must stay 100% original");
            $this->assertStringNotContainsString('cq-btn-ai', $html, "{$look}: the ABOS button sizing leaked in");

            $button = $this->copilotButton($html);
            if ($button !== '') {
                $this->assertStringContainsString('cq-orb-mark', $button, "{$look}: the old Copilot mark is gone from the top bar");
            }
        }
    }

    /**
     * ⭐ মালিক, ২৩ নভেম্বর ২০২৬: রেলে কোপাইলটের টাইলে কোনো পট নেই, "Ai" চিহ্ন টাইলের পুরো মাপে (৩২px) — অন্য মডিউলের
     * রঙিন বাক্স যত বড়, তত বড়। SAP আর Dynamic-এ কিছুই বদলায় না।
     */
    public function test_the_rail_copilot_tile_is_bare_and_the_mark_fills_the_tile_in_the_abos_looks(): void
    {
        foreach (['rose', 'navy', 'blue'] as $look) {
            $html = $this->pageAs($look);

            if (! str_contains($html, 'data-rail-copilot')) {
                continue; // ⓘ এই চেহারায় রেল নেই বা কোপাইলট রেলে নেই
            }

            $this->assertMatchesRegularExpression('/data-rail-tile-bare\s+style="background: transparent;/', $html, "{$look}: the Copilot tile still has a background");
            $this->assertMatchesRegularExpression('/<svg[^>]*width="32"[^>]*data-ai-mark[^>]*data-rail-copilot/s', $html, "{$look}: the rail Ai mark is not the tile's full size");
        }

        foreach (['tiles', 'dynamic'] as $look) {
            $this->assertStringNotContainsString('data-rail-tile-bare', $this->pageAs($look), "{$look}: the bare tile leaked into a look that must stay original");
        }
    }

    public function test_the_component_has_no_background_its_own_gradient_a_golden_dot_and_a_thinking_state(): void
    {
        $one = Blade::render('<x-ui.ai-mark :size="40" />');
        $two = Blade::render('<x-ui.ai-mark :size="40" />');

        preg_match('/<linearGradient id="([^"]+)"/', $one, $a);
        preg_match('/<linearGradient id="([^"]+)"/', $two, $b);
        $this->assertNotEmpty($a[1] ?? null, 'the mark has no gradient');
        $this->assertNotSame($a[1], $b[1] ?? null, 'two marks share one gradient id');

        $this->assertStringContainsString('viewBox="0 0 48 48"', $one);
        $this->assertStringContainsString('animateTransform', $one, 'the gradient does not turn');
        $this->assertStringContainsString('ai-mark-dot', $one, 'the i has no dot');
        $this->assertStringContainsString('ai-mark-comet', $one, 'no light runs round the border');
        $this->assertSame(3, substr_count($one, 'ai-mark-star '), 'not three stars');
        $this->assertStringNotContainsString('<rect width="48"', $one, 'a tile behind the mark');
        $this->assertStringNotContainsString('<circle cx="24" cy="24"', $one, 'a disc behind the mark');
        $this->assertStringNotContainsString('data-ai-busy', $one);

        $this->assertStringContainsString('data-ai-busy', Blade::render('<x-ui.ai-mark :size="40" :busy="true" />'), 'no "thinking" state');

        $css = (string) file_get_contents(resource_path('css/ai-mark.css'));
        $this->assertStringContainsString('.ai-mark-dot { fill: #fbbf24; filter: drop-shadow(0 0 2px #fde68a); }', $css, 'the i-dot is not golden');
        $this->assertStringContainsString('prefers-reduced-motion', $css, 'the mark moves even when motion is reduced');
        $this->assertStringContainsString("[data-ui='rose']", $css, 'the top-bar sizing is not scoped to the ABOS looks');
    }

    private function pageAs(string $look): string
    {
        $this->owner->forceFill(['ui' => $look])->save();

        return (string) $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk()->getContent();
    }

    /** The top-bar Copilot button, from its opening tag to its closing tag. */
    private function copilotButton(string $html): string
    {
        return preg_match('/<button[^>]*data-copilot-button.*?<\/button>/s', $html, $m) ? $m[0] : '';
    }
}
