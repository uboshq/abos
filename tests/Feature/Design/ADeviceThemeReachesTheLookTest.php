<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Core\Support\LookRegistry;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ "ফোনের মতো" থিম অন্ধকার যন্ত্রে অন্ধকারই — ড্যাশবোর্ডের পরিকল্পনা §৭, সমস্যা ১ (৩০ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে: রূপের রং html-এর inline style-এ বসত, সার্ভারের জানা থিমের (system = আলো)। যন্ত্র অন্ধকারে গেলে theme.js
 * `data-theme="dark"` বসাত, কিন্তু inline-এর সাদা জমিন আর কালো লেখা `tokens.css`-এর অন্ধকার মানকে হারিয়ে দিত — পাতা প্রায় পুরো
 * সাদা, কেবল কয়েকটা চিপ গাঢ় (পরিকল্পনার ছবি ৪)। ⓘ লোকালে মাপা: আগে body #f5fafb অন্ধকার যন্ত্রেও; এখন #0a101c।
 *
 * দাবি:
 *   ক — html-এর inline style-এ রূপের রং নেই (কেবল অ্যাকসেন্ট), রূপ `<style data-look>`-এ;
 *   খ — সেই শিটে দুই ব্লক: আলোর (`:root:root:not([data-theme='dark'])`) রূপের আলোর মান নিয়ে, অন্ধকারের (`:root:root[data-theme='dark']`)
 *       রূপের অন্ধকার মান নিয়ে — যে থিমই বাছা থাকুক, তাই "ফোনের মতো" যন্ত্রের সাথে বদলায়।
 */
final class ADeviceThemeReachesTheLookTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_look_sits_in_a_sheet_with_a_light_and_a_dark_block_whatever_the_theme(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['ui' => 'navy', 'theme' => 'system'])->save();

        $html = (string) $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->getContent();
        $light = LookRegistry::tokens('navy', 'light');
        $dark = LookRegistry::tokens('navy', 'dark');
        $name = '--color-surface-app';
        $this->assertArrayHasKey($name, $dark, 'প্রস্তুতিটাই ভুল — navy-র অন্ধকার জমিন নেই।');
        $this->assertNotSame($light[$name] ?? null, $dark[$name], 'প্রস্তুতিটাই ভুল — আলো আর অন্ধকারের জমিন এক।');

        // ক
        preg_match('/<html[^>]*\sstyle="([^"]*)"/s', $html, $root);
        $this->assertStringNotContainsString($name, $root[1] ?? '', '⛔ অন্ধকার থিম: রূপের রং আবার inline-এ — অন্ধকার যন্ত্রেও পাতা সাদা থাকত।');

        // খ
        $this->assertSame(1, preg_match('/<style data-look[^>]*>(.*?)<\/style>/s', $html, $sheet), '⛔ অন্ধকার থিম: পাতায় রূপের শিট নেই।');
        $blocks = [];
        preg_match_all('/(:root:root(?::not\(\[data-theme=\'dark\'\]\)|\[data-theme=\'dark\'\]))\{([^}]*)\}/', $sheet[1], $m, PREG_SET_ORDER);
        foreach ($m as [, $selector, $body]) {
            $blocks[$selector] = $body;
        }
        $this->assertStringContainsString($name.':'.$light[$name].';', $blocks[":root:root:not([data-theme='dark'])"] ?? '',
            '⛔ অন্ধকার থিম: আলোর ব্লকে রূপের আলোর জমিন নেই।');
        $this->assertStringContainsString($name.':'.$dark[$name].';', $blocks[":root:root[data-theme='dark']"] ?? '',
            '⛔ অন্ধকার থিম: অন্ধকারের ব্লক নেই বা তাতে রূপের অন্ধকার জমিন নেই — "ফোনের মতো" অন্ধকার যন্ত্রে সাদা থাকত।');
    }
}
