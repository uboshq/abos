<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Dashboard\Series;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মালিক, ৪ অক্টোবর ২০২৬: *"ei chart gulo ekline dilei hoy, r value diye dio"* · *"sob chart ei velue dio"*।
 *
 * ⓘ দাবি: প্রতিটা চার্ট নিজের ধরনে আঁকা (রেখা, ভরা রেখা, স্তম্ভ, ডোনাট, ফানেল…), প্রতিটা অশূন্য দণ্ডের মাথায় একটা মান, আর ছবিগুলো এক সারির ছকে; মডিউলের নতুন ড্যাশবোর্ডেও প্রতিটা
 * দণ্ডে মান; হোমের চারটা সাজানো ভাগ একই বাবা-মায়ের পাশাপাশি সন্তান (একটা ভাগের বন্ধনী আরেকটার ভেতরে বন্ধ হলে
 * সাজ ভাঙে — ৪ অক্টোবরে ছবির ভাগে ঠিক তাই হয়েছিল)।
 */
final class EveryChartBarShowsItsValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_bar_on_the_home_and_the_module_pages_carries_a_value(): void
    {
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $home = $this->xpath($this->get(route('dashboard'))->assertOk()->getContent());
        $this->assertSame(4, $home->query('//div[@data-home-units]/div[@data-home-unit]')->length, '⛔ হোমের সাজানো ভাগ চারটা পাশাপাশি নয় — কোনো বন্ধনী ভুল জায়গায়।');
        $this->assertSame(1, $home->query('//div[@data-home-unit="pictures"]//section[@data-business-pictures]')->length, '⛔ ছবির অংশ নিজের ভাগের বাইরে।');
        $this->assertStringContainsString('auto-fit', (string) $home->query('//div[@data-pictures-row]')->item(0)?->getAttribute('style'), '⛔ ছবি এক সারির ছকে নয়।');

        $this->assertBarsHaveValues($home, 'হোম');

        // ⭐ এক রকম নয় — হোমে অন্তত চার ধরনের চার্ট (মালিক: "vino rokomer int standred graph")
        $kinds = array_unique(array_map(fn ($n) => $n->getAttribute('data-chart'), iterator_to_array($home->query('//*[@data-chart]'))));
        $this->assertGreaterThanOrEqual(4, count($kinds), '⛔ হোমের চার্ট প্রায় সব এক রকম: '.implode(', ', $kinds));
        $this->assertContains('funnel', $kinds, '⛔ বিক্রয়ের ফানেল ফানেল হয়ে আঁকা হয়নি।');
        $this->assertContains('donut', $kinds);

        foreach (['sales', 'inventory', 'accounts'] as $module) {
            $page = $this->get(route('module.dashboard', ['module' => $module]));
            if ($page->status() === 200) {
                // ⓘ মডিউলের পাতায় চার্ট রেখাও হতে পারে — দণ্ড থাকলে তবেই মাপা
                $this->assertBarsHaveValues($this->xpath($page->getContent()), $module, false);
            }
        }

        $this->assertSame('12.3 '.__('core.dashboard.short_thousand'), Series::short('12,345.00'));
        $this->assertSame('1.2 '.__('core.dashboard.short_crore'), Series::short(12345678));
        $this->assertSame('950', Series::short(950));
    }

    private function assertBarsHaveValues(DOMXPath $page, string $where, bool $mustHaveBars = true): void
    {
        $bars = $page->query('//div[contains(@class, "rounded-t") and @title]');
        if ($mustHaveBars) {
            $this->assertGreaterThan(0, $bars->length, "{$where}: কোনো দণ্ডই নেই — দাবিটা কিছু দেখবে না।");
        }

        foreach ($bars as $bar) {
            $value = $page->query('preceding-sibling::span[@data-bar-value]', $bar);
            $this->assertSame(1, $value->length, "⛔ {$where}: একটা দণ্ডের মাথায় মান নেই ({$bar->getAttribute('title')})।");
            // ⓘ শূন্যের মাথায় লেখা থাকে না (দণ্ডটাই মাটিতে) — বাকি সবগুলোয় মান
            $raw = (string) preg_replace('/^.*:\s*/u', '', $bar->getAttribute('title'));
            if ((float) str_replace(',', '', $raw) != 0.0) {
                $this->assertNotSame('', trim($value->item(0)->textContent), "⛔ {$where}: দণ্ডের মান খালি ({$bar->getAttribute('title')})।");
            }
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }
}
