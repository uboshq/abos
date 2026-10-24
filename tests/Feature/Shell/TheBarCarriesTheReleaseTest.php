<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Core\Support\Release;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ নিচের বারে সংস্করণ, তারিখ আর চলতি commit; চাপলে "নতুন কী এল" (মালিক, ২৪ অক্টোবর ২০২৬: "এখন থেকেই v. দেওয়া হোক")।
 *
 * ⓘ আগে বারে লেখা ছিল `v0.1.0` — config/app.php-তে `version` চাবিই ছিল না, তাই সবসময় ডিফল্টটা।
 * ⭐ এখন একটাই উৎস config/release.php; commit আসে deploy-এর লেখা storage/app/release-build.json থেকে, না থাকলে চুপ।
 */
final class TheBarCarriesTheReleaseTest extends TestCase
{
    use RefreshDatabase;

    private string $build;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->build = storage_path('app/release-build.json');
        @unlink($this->build);
    }

    protected function tearDown(): void
    {
        @unlink($this->build);
        parent::tearDown();
    }

    public function test_the_bar_shows_the_configured_version_and_date_and_opens_whats_new(): void
    {
        config(['release.version' => '1.4.2', 'release.date' => '2026-10-24']);

        $html = (string) $this->actingAs($this->owner())->get(route('release.notes'))->getContent();
        $bar = $this->bar($html);

        $this->assertStringContainsString('v1.4.2 · 24-10-2026', $bar, '⛔ নিচের বারে config-এর সংস্করণ আর তারিখ নেই।');
        $this->assertStringNotContainsString('v0.1.0', $bar, '⛔ নিচের বারে পুরনো v0.1.0 রয়ে গেছে।');
        $this->assertStringContainsString('href="'.route('release.notes').'"', $bar, '⛔ বারের সংস্করণে চাপলে "নতুন কী এল" খোলে না।');
    }

    public function test_the_running_commit_shows_when_deploy_wrote_it_and_nothing_breaks_without_it(): void
    {
        $page = $this->actingAs($this->owner())->get(route('release.notes'));
        $this->assertSame(200, $page->status(), '⛔ release-build.json না থাকলে পাতা ভাঙে।');
        $this->assertNull(Release::sha(), '⛔ ফাইল নেই, তবু commit দেখায়।');

        file_put_contents($this->build, json_encode(['sha' => '2C026A30385AC3988485135FCC6935341734554', 'deployed_at' => '2026-10-24T10:00:00+06:00']));
        $bar = $this->bar((string) $this->actingAs($this->owner())->get(route('release.notes'))->getContent());
        $this->assertStringContainsString(' · 2c026a3', $bar, '⛔ deploy-এর লেখা commit বারে নেই।');

        file_put_contents($this->build, '{not json');
        $this->assertSame(200, $this->actingAs($this->owner())->get(route('release.notes'))->status(), '⛔ ভাঙা release-build.json পাতা ভাঙে।');
        $this->assertNull(Release::sha(), '⛔ ভাঙা ফাইল থেকে commit দেখায়।');
    }

    public function test_whats_new_opens_for_anyone_signed_in_and_puts_the_latest_on_top(): void
    {
        $dir = sys_get_temp_dir().'/abos-notes-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/bn.md', "# নতুন কী এল\n\n## 1.0.0 — 24-10-2026\n\n- প্রথম\n\n## 1.2.0 — 20-11-2026\n\n- তৃতীয়\n\n## 1.1.0 — 05-11-2026\n\n- দ্বিতীয়\n");
        config(['release.notes_path' => $dir]);

        $plain = User::factory()->create(['is_active' => true, 'locale' => 'bn']);
        $plain->companies()->attach(CompanyContext::id(), ['is_active' => true]);

        $page = $this->actingAs($plain)->get(route('release.notes'));
        $this->assertSame(200, $page->status(), '⛔ চাবি ছাড়া মানুষ "নতুন কী এল" খুলতে পারেন না।');

        $html = (string) $page->getContent();
        $at = fn (string $v) => strpos($html, 'data-release-version="'.$v.'"');
        $this->assertNotFalse($at('1.2.0'), '⛔ দৃশ্যটাই ভুল — নোটের অংশ পাতায় নেই।');
        $this->assertTrue($at('1.2.0') < $at('1.1.0') && $at('1.1.0') < $at('1.0.0'), '⛔ সর্বশেষ সংস্করণ সবার উপরে নয়।');

        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    public function test_the_phone_api_carries_the_web_version_beside_the_app_version(): void
    {
        config(['release.version' => '1.4.2', 'release.date' => '2026-10-24']);

        $answer = $this->getJson('/api/v1/app/version');
        $this->assertSame('1.4.2', $answer->json('web_version.version'), '⛔ ফোনের API-তে ওয়েবের সংস্করণ নেই।');
        $this->assertSame('2026-10-24', $answer->json('web_version.date'), '⛔ ফোনের API-তে ওয়েবের সংস্করণের তারিখ নেই।');
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function bar(string $html): string
    {
        $start = strpos($html, '<footer data-footer');
        $this->assertNotFalse($start, '⛔ দৃশ্যটাই ভুল — পাতায় নিচের বার নেই।');

        return substr($html, (int) $start, (int) strpos($html, '</footer>', (int) $start) - (int) $start);
    }
}
