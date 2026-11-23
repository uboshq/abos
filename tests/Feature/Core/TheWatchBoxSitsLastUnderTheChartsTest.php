<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মালিক, ২০ নভেম্বর ২০২৬: প্রতিটা মডিউলের ড্যাশবোর্ড খোলে তার ছয় গ্রাফে (দুই সারি, 1920x1080), বাকি সব নিচে, আর
 * "নজরে রাখুন" (সমস্যা কেন্দ্র) একদম শেষে — অর্থ, মজুদ আর বিক্রয় আগেই এভাবে (4e65be794, board-arrange.js)।
 *
 * দাবি:
 *   ক — গ্রাফের বোর্ডের পাতায় (মানবসম্পদ, ক্রয়) "নজরে রাখুন" HTML-এ গ্রাফের বোর্ড, শেষ তালিকা, শেষ চার্ট আর শেষ সূচকের পরে;
 *       জোড়া-সারি নেই, আর আগে যে প্রথম চার্ট জোড়া-সারিতে ছিল সেটা হারায়নি (চার্টের সংখ্যা SAP রূপের সমান);
 *   খ — SAP আর Dynamic রূপে পাতা আগের মতো: "নজরে রাখুন" জোড়া-সারিতে, সূচক আর তালিকার আগে; শেষের ধারক নেই।
 */
final class TheWatchBoxSitsLastUnderTheChartsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['abos.dashboards_v2' => true]);
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_k_on_a_chart_board_the_watch_box_comes_last(): void
    {
        foreach (['hr', 'purchase'] as $code) {
            $html = $this->page($code, 'rose');

            $watch = strpos($html, 'data-dashboard-exceptions');
            $this->assertNotFalse($watch, "⛔ {$code}: \"নজরে রাখুন\" পাতায় নেই।");
            $this->assertSame(1, substr_count($html, 'data-dashboard-exceptions'), "⛔ {$code}: \"নজরে রাখুন\" দুইবার আঁকা।");
            $this->assertStringContainsString('data-watch-last', $html, "⛔ {$code}: শেষের ধারক নেই।");
            $this->assertStringNotContainsString('data-dashboard-pair', $html, "⛔ {$code}: জোড়া-সারি এখনো মাথায়।");

            $board = strpos($html, 'data-viz-board');
            $this->assertNotFalse($board, "⛔ {$code}: গ্রাফের বোর্ড নেই — দাবি অন্ধ।");
            $this->assertGreaterThan($board, $watch, "⛔ {$code}: \"নজরে রাখুন\" গ্রাফের বোর্ডের আগে।");

            // ⓘ কেবল পাতার ভেতরটা (<main>) — খোলসের নিচের অংশে একই নামের চিহ্ন থাকলে দাবি ভুল বলত
            $main = substr($html, 0, (int) strrpos($html, '</main>'));
            foreach (['data-listing', 'data-panel', 'data-stat', 'data-top-sections', 'data-viz-card', 'data-tile'] as $mark) {
                $last = strrpos($main, $mark);
                if ($last !== false) {
                    $this->assertGreaterThan($last, $watch, "⛔ {$code}: \"নজরে রাখুন\" শেষ [{$mark}]-এর আগে — সবার শেষে থাকার কথা।");
                }
            }

            // ⛔ জোড়া-সারির প্রথম চার্ট হারায়নি — SAP রূপে (আগের সাজ) যত চার্ট, এখানেও তত
            $this->assertSame(
                preg_match_all('/<section[^>]*data-panel[ >=]/', $this->page($code, 'tiles')),
                preg_match_all('/<section[^>]*data-panel[ >=]/', $html),
                "⛔ {$code}: \"নজরে রাখুন\" সরাতে গিয়ে একটা চার্ট হারিয়েছে।",
            );
        }
    }

    public function test_kh_sap_and_dynamic_keep_todays_order(): void
    {
        foreach (['tiles', 'dynamic'] as $look) {
            foreach (['hr', 'purchase'] as $code) {
                $html = $this->page($code, $look);

                $this->assertStringNotContainsString('data-watch-last', $html, "⛔ {$look}/{$code}: এই রূপে পাতা বদলানোর কথা নয়।");
                $this->assertMatchesRegularExpression('/data-dashboard-pair.*?data-dashboard-exceptions/s', $html,
                    "⛔ {$look}/{$code}: \"নজরে রাখুন\" আগের মতো জোড়া-সারিতে নেই।");

                $watch = (int) strpos($html, 'data-dashboard-exceptions');
                foreach (['data-stat-grid', 'data-listing'] as $mark) {
                    $first = strpos($html, $mark);
                    if ($first !== false) {
                        $this->assertLessThan($first, $watch, "⛔ {$look}/{$code}: \"নজরে রাখুন\" [{$mark}]-এর পরে সরে গেছে।");
                    }
                }
            }
        }
    }

    private function page(string $code, string $look): string
    {
        $this->owner->forceFill(['ui' => $look])->save();

        return (string) $this->actingAs($this->owner->refresh())->get(route('module.dashboard', $code))->assertOk()->getContent();
    }
}
