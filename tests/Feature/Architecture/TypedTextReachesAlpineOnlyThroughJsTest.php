<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ব্যবহারকারীর লেখা Alpine-এ যায় কেবল `@js(...)` দিয়ে — অডিট ২৭ সেপ্টেম্বর, ক §৮।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ছয়টা ফর্ম আগের জমার লেখা (`old()`) Alpine-এর `x-data`-য় বসাত হাতে লেখা উদ্ধৃতির ভেতরে:
 * `x-data="{ method: '{{ old('method') }}' }"`। ⓘ `{{ }}` HTML বাঁচায়, JavaScript নয় — লেখায় একটা `'`
 * থাকলেই এক্সপ্রেশনটা ভাঙত, আর পর্দার ঐ অংশ আর চলত না (ডিলার পোর্টালের দাবির ফর্মও একটা)।
 *
 * ⭐ `@js(...)` নিজেই উদ্ধৃতি বসায় আর ভেতরের `'` / `"` পালায় (এই অ্যাপে JSON + HTML-পালানো, [[AppServiceProvider]])। ⚠️ `{{ Js::from() }}` নয় — ওটা
 * `JSON.parse(...)` লেখে, যা CSP-Alpine পায় না।
 */
final class TypedTextReachesAlpineOnlyThroughJsTest extends TestCase
{
    use RefreshDatabase;

    /** হাতে লেখা JS উদ্ধৃতির ভেতরে `{{ old(` — ঠিক এই আকারটাই ভাঙে */
    private const HAND_QUOTED_OLD = '/\'\{\{\s*old\(/';

    public function test_the_pattern_sees_the_dangerous_shape_and_nothing_else(): void
    {
        // ⓘ পাহারাকে বিপজ্জনক লেখা খাওয়ানো — নাহলে সবুজ মানে "কখনো দেখেইনি" হতে পারত
        $this->assertSame(1, preg_match(self::HAND_QUOTED_OLD, "x-data=\"{ method: '{{ old('method', 'bank') }}' }\""));
        $this->assertSame(0, preg_match(self::HAND_QUOTED_OLD, "x-data=\"{ method: @js(old('method', 'bank')) }\""));
        $this->assertSame(0, preg_match(self::HAND_QUOTED_OLD, '<input name="code" value="{{ old(\'code\') }}">'));
    }

    public function test_no_blade_hand_quotes_old_input_for_javascript(): void
    {
        $found = [];

        foreach (Finder::create()->files()->name('*.blade.php')->in([base_path('app'), base_path('resources/views')]) as $file) {
            foreach (preg_split('/\R/', $file->getContents()) as $i => $line) {
                if (preg_match(self::HAND_QUOTED_OLD, $line) === 1) {
                    $found[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $found, "এই জায়গাগুলো আগের জমার লেখা হাতে লেখা উদ্ধৃতিতে Alpine-এ দেয় — `@js(old(...))` লিখুন:\n".implode("\n", $found));
    }

    /** ⭐ আসল পাতায়, আসল বিপজ্জনক লেখা নিয়ে — উদ্ধৃতি পালানো, এক্সপ্রেশন অক্ষত */
    public function test_a_quote_in_the_old_input_does_not_break_the_form(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $html = (string) $this->withSession(['_old_input' => ['type' => "a'b"]])
            ->get(route('system_admin.custom_field.index'))
            ->assertOk()
            ->getContent();

        // ⓘ `{{ }}` `'`-কে `&#039;` করে, আর ব্রাউজার অ্যাট্রিবিউট পড়ার সময় আবার `'` বানায় — তাই দুই রূপই ভাঙা
        foreach (["type: 'a'b'", "type: 'a&#039;b'"] as $broken) {
            $this->assertStringNotContainsString($broken, $html, '⛔ উদ্ধৃতিটা কাঁচা বসেছে — Alpine-এর এক্সপ্রেশন ভাঙা।');
        }
        // ⓘ এই অ্যাপের @js ([[AppServiceProvider]]) JSON লেখে আর HTML-এ পালায় — ব্রাউজার পড়ে `{ type: "a'b" }`
        $this->assertStringContainsString('type: &quot;a&#039;b&quot;', $html, '@js-এর পালানো রূপ পাতায় নেই।');
    }
}
