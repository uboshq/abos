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

    public function test_every_business_module_is_a_card_with_its_switch_its_line_and_its_settings_door(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $response = $this->actingAs($owner)->get(route('system_admin.control-panel'))->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
        $x = new DOMXPath($dom);

        $tree = collect($response->viewData('tree'));
        $business = $tree->reject(fn ($m) => $m['essential'] ?? false);
        $this->assertNotEmpty($business, 'প্রস্তুতিটাই ভুল — চালু/বন্ধ করার মতো মডিউল নেই।');
        $this->assertSame(0, $x->query('//table[.//input[starts-with(@name, "settings[") and @data-module-label]]')->length,
            '⛔ মডিউলের সুইচ আবার টেবিলে।');

        foreach ($business as $module) {
            $card = $x->query('//*[@data-module-card="'.$module['code'].'"]')->item(0);
            $this->assertNotNull($card, "⛔ {$module['code']}: মডিউলের কার্ড নেই।");
            $switch = $x->query('.//input[@type="checkbox"][@role="switch"][@name="settings['.$module['key'].']"]', $card);
            $this->assertSame(1, $switch->length, "⛔ {$module['code']}: কার্ডে সুইচ নেই।");
            $about = trim($x->query('.//*[@data-module-about]', $card)->item(0)?->textContent ?? '');
            $this->assertSame(__('system_admin::control.about.'.$module['code']), $about, "⛔ {$module['code']}: কার্ডে এক লাইনে কী করে লেখা নেই।");
            $this->assertStringNotContainsString('::', $about, "⛔ {$module['code']}: কী করে-র লেখা অনুবাদই হয়নি।");
            $this->assertSame(route('system_admin.control-panel', ['tab' => $module['code']]),
                $x->query('.//a[@data-module-settings]', $card)->item(0)?->getAttribute('href'), "⛔ {$module['code']}: \"সেটিংস ›\" ঠিক জায়গায় যায় না।");
            $this->assertSame(1, $x->query('.//*[@data-module-impact]', $card)->length, "⛔ {$module['code']}: বন্ধ করলে কী হবে, লেখা নেই।");
        }

        foreach ($tree->filter(fn ($m) => $m['essential'] ?? false) as $module) {
            $this->assertSame(0, $x->query('//*[@data-module-card="'.$module['code'].'"]')->length, "⛔ ভিত্তির {$module['code']} বন্ধ করার কার্ডে বসেছে।");
        }
    }
}
