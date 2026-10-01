<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * তালিকার টুলবারে কম-ব্যবহৃত বোতাম "…"-এর ভেতরে — নকশার পর্যালোচনা, মালিক, ১ অক্টোবর ২০২৬ (ধাপ ৭ · ৪)।
 *
 *   বাইরে   খোঁজা, রপ্তানি, ছাপা
 *   ভেতরে   ঘনত্ব, কলাম, শেয়ার, নতুন করে আনো
 * ⓘ "…" নিজে একটা বোতাম (`aria-haspopup`, `aria-expanded`) — কিবোর্ডে পৌঁছানো যায়; ভেতরের প্রতিটা বোতাম নিজের লেবেল
 * রাখে, আর প্যানেল HTML-এ থাকে (কেবল লুকানো) — Tab আর স্ক্রিন-রিডার দুটোই পায়।
 */
final class TheRarelyUsedToolButtonsLiveBehindMoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_four_rare_buttons_sit_only_behind_more_and_the_daily_ones_stay_out(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $html = (string) $this->get(route('customer.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-toolbar-more', $html, '"…" মেনুটাই নেই।');
        $more = $this->block($html);
        $outside = str_replace($more, '', $html);

        $this->assertMatchesRegularExpression('/aria-haspopup="true"/', $more, '"…" বোতাম মেনু বলে ঘোষণা করে না।');
        $this->assertMatchesRegularExpression('/aria-label="'.preg_quote(e(__('core.toolbar.more')), '/').'"/', $more);

        foreach (['core.toolbar.density', 'core.toolbar.columns', 'core.toolbar.share_copy', 'core.toolbar.refresh'] as $key) {
            $label = 'aria-label="'.e(__($key)).'"';
            $this->assertStringContainsString($label, $more, "\"{$key}\" ".'…'.'-এর ভেতরে নেই।');
            $this->assertStringNotContainsString($label, $outside, "⛔ \"{$key}\" এখনো টুলবারে বাইরে।");
        }

        foreach (['core.toolbar.export', 'core.action.print'] as $key) {
            $this->assertStringContainsString('aria-label="'.e(__($key)).'"', $outside, "⛔ \"{$key}\" বাইরে থাকার কথা।");
        }
        $this->assertStringContainsString('data-quick-find', $outside, 'খোঁজার ঘর বাইরে থাকার কথা।');
    }

    /** "…"-এর পুরো ধারক — নিজের `<div>` থেকে মিলিয়ে বন্ধ হওয়া পর্যন্ত */
    private function block(string $html): string
    {
        $start = strpos($html, 'data-toolbar-more');
        $open = strrpos(substr($html, 0, $start), '<div');
        $depth = 0;
        $offset = $open;

        while (preg_match('/<(\/?)div\b/i', $html, $tag, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $depth += $tag[1][0] === '/' ? -1 : 1;
            $offset = $tag[0][1] + 4;

            if ($depth === 0) {
                return substr($html, $open, strpos($html, '>', $tag[0][1]) + 1 - $open);
            }
        }

        $this->fail('"…" ধারক বন্ধ হয়নি।');
    }
}
