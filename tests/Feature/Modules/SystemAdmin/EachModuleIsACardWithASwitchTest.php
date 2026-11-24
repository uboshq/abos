<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ কন্ট্রোল প্যানেলে প্রতিটা মডিউল একটা কার্ড — সুইচ, এক লাইনে কী করে, বন্ধ করলে কী হবে, আর "সেটিংস ›" (কন্ট্রোল প্যানেলের
 * পরিকল্পনা, মালিকের অনুমোদিত, ২১ অক্টোবর ২০২৬, দফা ৫)। ভিত্তির মডিউল কার্ডে নয়, তালাবদ্ধ।
 *
 * ⛔ আগে: টিক-বাক্সের টেবিল, "ভাগ" আর "পর্দা" কলামের মানে বোঝা যেত না, আর ১৯ বার একই লিংক-লেখা।
 */
final class EachModuleIsACardWithASwitchTest extends TestCase
{
    use RefreshDatabase;

    /** ⭐ পুরনো কার্ডের পাতা এখন "কোম্পানির মডিউল" (মালিক, ২৪ নভেম্বর ২০২৬): ঠিকানা সেখানে নেয়, প্রতিটা মডিউলের সুইচ আছে, তালাবন্ধগুলো বন্ধ */
    public function test_the_old_cards_address_opens_the_company_modules_with_a_switch_for_each(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->actingAs($owner)->get(route('system_admin.control-panel', ['tab' => 'switches']))->assertRedirect(route('system_admin.module.companies'));
        $html = (string) $this->get(route('system_admin.module.companies'))->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $x = new DOMXPath($dom);
        $board = app(\App\Modules\SystemAdmin\Support\ModuleBoard::class);

        foreach (array_keys(app(\App\Core\Module\ModuleRegistry::class)->all()) as $code) {
            $input = $x->query('//*[@data-module-row="'.$code.'"]//input[@role="switch"][@name="modules['.$code.']"]');
            $this->assertSame(1, $input->length, "⛔ {$code}: সুইচ নেই।");
            $this->assertSame($board->locked($code), $input->item(0)->hasAttribute('disabled'), "⛔ {$code}: তালা ভুল।");
        }

        // ⛔ হাতে বানানো অনুরোধেও হিসাব বন্ধ হয় না
        $this->put(route('system_admin.control-panel.update'), ['scope' => ['accounts.enabled'], 'settings' => []])->assertRedirect();
        app(\App\Core\Services\SettingsService::class)->flush();
        $this->assertTrue((bool) app(\App\Core\Services\SettingsService::class)->get('accounts.enabled', true));
    }
}
