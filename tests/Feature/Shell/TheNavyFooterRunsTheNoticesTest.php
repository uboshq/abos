<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Services\NoticeLifecycle;
use App\Core\Services\StatusNotices;
use App\Core\Support\CompanyContext;
use App\Core\Support\NoticePriority;
use App\Core\Support\Ui;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ⭐ ABOS Navy-র নিচের বারে নোটিশ — মালিক, ২৩ নভেম্বর ২০২৬: "অ্যাবস নেভিতে ফুটারের চলমান নোটিশগুলো দেখাচ্ছে না"।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * কেবল "ABOS" (`rose`) `'ticker' => 'tag'` পেত; Navy পড়ত `plain`-এ — ঘোষণা না থাকলে বারটা ফাঁকা, আর "Notice" চিহ্নই নেই।
 * এখন Navy-ও "ABOS"-এর মতো: চিহ্ন, ঘোষণা না থাকলে স্থির "আজ কোনো জরুরি নোটিশ নেই", ঘোষণা থাকলে চলা সারি।
 * ⛔ ABOS Blue, SAP (tiles), Dynamic অটুট — তাদের বারে চিহ্ন বসে না।
 */
class TheNavyFooterRunsTheNoticesTest extends TestCase
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

    private function wear(string $look): void
    {
        $this->owner->forceFill(['ui' => $look])->save();
        $this->owner = $this->owner->fresh();
    }

    private function footer(): string
    {
        $html = (string) $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();
        $start = strpos($html, '<footer data-footer');
        $this->assertNotFalse($start, 'নিচের বারটাই পাতায় নেই।');

        return substr($html, $start, strpos($html, '</footer>', $start) - $start);
    }

    private function noAnnouncements(): void
    {
        $company = (int) $this->owner->current_company_id;

        foreach ([null, ...Branch::query()->withoutGlobalScopes()->where('company_id', $company)->pluck('id')->all()] as $branch) {
            CompanyContext::set($company, $branch === null ? null : (int) $branch);
            Cache::put(StatusNotices::cacheKey($company, (int) $this->owner->id), [], 60);
        }
    }

    private function announce(string $text): void
    {
        $this->actingAs($this->owner);
        $life = app(NoticeLifecycle::class);
        $notice = $life->draft(['title' => $text, 'body' => $text, 'priority' => NoticePriority::IMPORTANT->value]);
        $life->publish($life->approve($life->submit($notice)));
        Cache::flush();
    }

    public function test_navy_wears_the_ticker_tag_like_abos_and_only_these_two(): void
    {
        $this->assertTrue(Ui::tickerTag('navy'));
        $this->assertTrue(Ui::tickerTag('rose'));

        foreach (['blue', 'tiles', 'dynamic'] as $look) {
            $this->assertFalse(Ui::tickerTag($look), "⛔ {$look}-এর নিচের বার বদলেছে।");
        }
    }

    public function test_navy_shows_the_tag_and_the_quiet_line_when_nothing_is_announced(): void
    {
        $this->wear('navy');
        $this->noAnnouncements();

        $footer = $this->footer();

        $this->assertStringContainsString('data-notice-tag', $footer, '⛔ Navy-র বারে "Notice" চিহ্ন নেই।');
        $this->assertStringContainsString('data-ticker-quiet', $footer, '⛔ ঘোষণা নেই, আর Navy-র বারটা ফাঁকা।');
        $this->assertStringContainsString(e(__('core.notice.ticker_quiet')), $footer);
    }

    public function test_navy_runs_a_real_announcement_behind_the_tag(): void
    {
        $this->wear('navy');
        $this->announce('রবিবার থেকে দাম বাড়বে');

        $footer = $this->footer();

        $this->assertStringContainsString('data-notice-tag', $footer);
        $this->assertMatchesRegularExpression('/<div data-ticker(?![-\w])/', $footer, '⛔ ঘোষণা আছে, তবু Navy-তে চলা সারি নেই।');
        $this->assertStringContainsString('রবিবার থেকে দাম বাড়বে', $footer);
        $this->assertStringContainsString('abos-ticker', $footer, '⛔ সারিটা চলে না।');
        $this->assertStringNotContainsString('data-ticker-quiet', $footer);
    }

    public function test_blue_sap_and_dynamic_footers_carry_no_tag(): void
    {
        $this->noAnnouncements();

        foreach (['blue', 'tiles', 'dynamic'] as $look) {
            $this->wear($look);
            $footer = $this->footer();

            $this->assertStringNotContainsString('data-notice-tag', $footer, "⛔ {$look}: নোটিশের চিহ্ন বসেছে।");
            $this->assertStringNotContainsString('data-ticker-quiet', $footer, "⛔ {$look}: চুপ লাইন বসেছে।");
        }
    }

    /** চিহ্নের রং কেবল Navy-তে — আর চলা (keyframes) সব রূপে এক, তাই Navy-তেও চলে। */
    public function test_the_tag_style_is_scoped_to_navy_and_the_motion_is_shared(): void
    {
        $tokens = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(resource_path('css/tokens.css')));
        preg_match_all('~([^{}@]+)\{~', $tokens, $m);

        $tagRules = array_filter($m[1], fn (string $s): bool => str_contains($s, 'abos-notice-tag'));
        $this->assertNotEmpty($tagRules, '⛔ Navy-র চিহ্নের নিয়ম নেই।');

        foreach ($tagRules as $selector) {
            $this->assertStringContainsString("[data-ui='navy']", $selector);
        }

        $app = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/@keyframes abos-ticker\b/', $app);
    }
}
