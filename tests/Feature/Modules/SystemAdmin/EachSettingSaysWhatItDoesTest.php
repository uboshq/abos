<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ কন্ট্রোল প্যানেলের পরিকল্পনা (মালিকের অনুমোদিত, ২১ অক্টোবর ২০২৬), দফা ৬: প্রতিটা সেটিংয়ের পাশে সহজ ভাষায় ব্যাখ্যা, আর নিচের স্থির
 * বারে শেষ বদল কে কবে করেছেন। ⛔ আগে: সেটিংয়ের কেবল নাম, কী করে বোঝা যেত না; বার কেবল কিছু জমলে দেখা যেত।
 */
final class EachSettingSaysWhatItDoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_setting_with_a_plain_line_shows_it_and_the_bar_names_the_last_change(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $html = (string) $this->actingAs($owner)->get(route('system_admin.control-panel', ['tab' => 'system_admin']))->assertOk()->getContent();

        foreach (['date_format', 'auto_logout_minutes', 'edit_posted_papers'] as $key) {
            $hint = __('system_admin::settings.'.$key.'_hint');
            $this->assertStringNotContainsString('::', $hint, "প্রস্তুতিটাই ভুল — {$key}-এর ব্যাখ্যা লেখা নেই।");
            $this->assertMatchesRegularExpression('/data-setting-hint[^>]*>'.preg_quote(e($hint), '/').'</u', $html, "⛔ \"{$key}\"-এর নিচে সহজ ব্যাখ্যা নেই।");
        }
        $this->assertSame(0, preg_match('/data-setting-hint[^>]*>[^<]*_hint</u', $html), '⛔ ব্যাখ্যা নেই এমন সেটিংয়ে কাঁচা চাবি দেখাচ্ছে।');

        $this->assertMatchesRegularExpression('/data-save-bar(?![^>]*x-show)/', $html, '⛔ সংরক্ষণের বার কেবল বদল জমলে দেখা যায়।');
        $this->assertMatchesRegularExpression('/data-last-change[^>]*>\s*('.preg_quote(e(__('system_admin::control.last_change')), '/').'|'
            .preg_quote(e(__('system_admin::control.no_change_yet')), '/').')/u', $html, '⛔ বারে শেষ বদলের কথা নেই।');
    }
}
