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
 * ⭐ হোম খোলে চার্ট দিয়ে — মালিক, ১৮ নভেম্বর ২০২৬ (নীল প্যানেলের নমুনা, artifact BTey3zZ3nnwspGzPMDVfMd v5):
 * *"সব গ্রাফ একদম উপরে, শুরুর পর্দাতেই"*।
 *
 * ⓘ দাবি:
 *   ১ · ক্রম — মাথা → ৮টা চার্ট → সূচক আর টাকার বাক্স → আজ যা করতে হবে → নজরে রাখুন / সদ্য যা হয়েছে; কিছু বাদ নেই
 *   ২ · আজ যা করতে হবে-র প্রতিটা ঘরে অবস্থার শব্দ, তাড়া ঘরের নিজের কথা থেকে ([[WorkLevel]])
 *   ৩ · Copilot-এর এক লাইন পাতায় নেই (মালিকের পরের সিদ্ধান্ত, একই দিন — পপআপে যাবে)
 * ⓘ কথা আর তাড়ার নিয়ম নিজে: [[TheHomeWordsComeFromItsOwnFactsTest]]
 */
final class TheHomeOpensOnItsChartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_charts_come_first_then_the_figures_and_money_then_todays_work_then_the_watch_list(): void
    {
        $owner = $this->owner();
        $html = (string) $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

        $marks = ['data-command-head', 'data-business-pictures', 'data-money-position', 'data-kpis',
            'data-home-today-work', 'data-exception-center'];
        $at = [];
        foreach ($marks as $mark) {
            $at[$mark] = strpos($html, $mark);
            $this->assertNotFalse($at[$mark], "⛔ হোমে \"{$mark}\" নেই — কিছু বাদ পড়েছে, বা দাবি কিছু দেখছে না।");
        }

        $sorted = $at;
        asort($sorted);
        $this->assertSame($marks, array_keys($sorted), '⛔ হোমের ক্রম মালিকের নয় (চার্ট আগে, তারপর সূচক ও টাকা, তারপর কাজ)।');

        // ⓘ সাজানো ভাগের ডিফল্ট ক্রমও একই — যিনি কিছু সাজাননি তাঁর পাতায় CSS `order` চার্ট ১, সূচক ২, কাজ ৩
        foreach (['pictures' => 1, 'kpis' => 2, 'work' => 3] as $unit => $order) {
            $this->assertStringContainsString('data-home-unit="'.$unit.'" style="order: '.$order.'"', $html,
                "⛔ \"{$unit}\" ভাগ ডিফল্ট ক্রমে {$order} নম্বরে নয়।");
        }

        // ⓘ আজ যা করতে হবে — প্রতিটা ঘরে অবস্থার শব্দ, রং একা নয়
        preg_match_all('/data-today-work data-level="([a-z]+)"/', $html, $levels);
        $this->assertNotEmpty($levels[1], 'আজ যা করতে হবে-তে একটাও ঘর নেই — দাবির ভিত নেই।');
        $work = substr($html, $at['data-home-today-work'], $at['data-exception-center'] - $at['data-home-today-work']);
        foreach (array_unique($levels[1]) as $level) {
            $this->assertMatchesRegularExpression('/data-status-chip="'.$level.'"[^>]*>'.preg_quote(e(__('home.level_'.$level)), '/').'</u', $work,
                "⛔ \"{$level}\" রঙের ঘরে অবস্থার শব্দ নেই — রং একা কথা বলছে।");
        }
    }

    /** ⓘ মালিক, ১৮ নভেম্বর ২০২৬: নোটিশের ফিতা আর Copilot-লাইন সারাদিন জায়গা খায় — হোমে লাইনটা নেই, প্রশ্নের চিপও নেই */
    public function test_the_home_carries_no_copilot_line(): void
    {
        $html = (string) $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-business-pictures', $html, 'হোমই আঁকা হয়নি — দাবি কিছু দেখছে না।');
        $this->assertStringNotContainsString('data-home-copilot', $html, '⛔ হোমে আবার Copilot-এর লাইন — মালিক এটা পাতা থেকে সরাতে বলেছেন।');
        $this->assertStringNotContainsString('data-copilot-ask', $html, '⛔ হোমে আবার Copilot-এর প্রশ্নের চিপ।');
    }

    private function owner(): User
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }
}
