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
 * ⭐ উপরের বারের খোঁজা — লেখা চলে বারের ঘরেই, ফল তার ঠিক নিচে (Fiori shell bar search; লাইভ-হটফিক্স, ২১ অক্টোবর ২০২৬)।
 *
 * ⛔ কী হয়েছিল: বারে ছিল একটা বোতাম, যা পুরো পাতার উপর আলাদা প্যালেট খুলত — নিজের দ্বিতীয় "যেকোনো কিছু খুঁজুন" ঘরসহ, আর
 * ১৬৮৭px-এ সেটা বাঁয়ে রেল আর সাইডবারের উপর বসত (মালিকের স্ক্রিনশট)।
 *
 * দাবি (আসল পাতা, তিন জায়গায়): পাতায় খোঁজার ঘর একটাই, সেটা বারের ভেতরে; ফলের প্যানেল একই খোঁজার মোড়কে, মোড়ক `relative`,
 * প্যানেল ঘরের ঠিক নিচে বাঁ কিনারা মিলিয়ে (`absolute; top: 100% + 4px; left: 0`), প্রস্থ ঘরের সমান অন্তত ৪৮০px; পুরো পাতার
 * আলাদা খোঁজার ডায়ালগ আর নেই। ⓘ পিক্সেলের মাপ ব্রাউজারে মাপা (searchcheck.py, ১৬৮৭/১৩৬৬/৩৭৫) — এটা তার স্থায়ী পাহারা।
 */
final class TheSearchHangsUnderItsOwnFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_field_in_the_bar_and_the_results_hang_right_under_it(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        foreach ([route('dashboard'), route('sales.invoice.index'), route('module.dashboard', ['module' => 'accounts'])] as $url) {
            $html = (string) $this->actingAs($owner)->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'aria-controls="command-hits"'), "⛔ {$url}: খোঁজার ঘর একটা নয় — দ্বিতীয় ঘর ফিরে এসেছে।");
            $this->assertSame(1, substr_count($html, 'role="combobox"'), "⛔ {$url}: পাতায় একাধিক খোঁজার ঘর।");

            $bar = $this->between($html, '<header data-topbar', '</header>');
            $this->assertStringContainsString('aria-controls="command-hits"', $bar, "⛔ {$url}: খোঁজার ঘর বারের ভেতরে নেই।");

            $wrap = $this->between($bar, 'data-shell-search', 'data-shell-actions');
            $this->assertMatchesRegularExpression('/data-shell-search[^>]*class="relative /', $bar, "⛔ {$url}: খোঁজার মোড়ক relative নয় — প্যানেল ঘরের সাথে বাঁধা থাকবে না।");
            $this->assertStringContainsString('data-search-panel', $wrap, "⛔ {$url}: ফলের প্যানেল খোঁজার মোড়কের বাইরে।");

            $panel = $this->between($wrap, 'data-search-panel', '>');
            $style = preg_replace('/\s+/', '', $panel) ?? $panel;
            $this->assertStringContainsString('position:absolute;top:calc(100%+4px);left:0', $style, "⛔ {$url}: প্যানেল ঘরের ঠিক নিচে, বাঁ কিনারা মিলিয়ে নয়।");
            $this->assertStringContainsString('width:max(100%,480px)', $style, "⛔ {$url}: প্যানেলের প্রস্থ ঘরের সমান (অন্তত ৪৮০px) নয়।");

            $this->assertDoesNotMatchRegularExpression('/role="dialog"[^>]*>\s*(?:<[^>]+>\s*)*?[^<]*aria-controls="command-hits"/s', $html,
                "⛔ {$url}: পুরো পাতার আলাদা খোঁজার ডায়ালগ ফিরে এসেছে।");
        }
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "প্রস্তুতিটাই ভুল — {$from} নেই।");
        $end = strpos($html, $to, $start + strlen($from));

        return substr($html, $start, $end === false ? null : $end - $start);
    }
}
