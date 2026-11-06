<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ সিস্টেম প্রশাসনের বাঁয়ের মেনু ভাগে ভাগে — কন্ট্রোল প্যানেলের পরিকল্পনা (মালিকের অনুমোদিত, ২১ অক্টোবর ২০২৬), দফা ১:
 * "বঁায়ের মেনু ২০টা এলোমেলো সারি থেকে ৭টা ভাগে … পুরনো সব ঠিকানা কাজ করবে"; Microsoft 365 Admin Center-এর মতো।
 *
 * ⛔ আগে: কোম্পানি, ব্যবহারকারী, নোটিশ, রিপোর্ট আর সেটিংস এক তালিকায় মেশানো — কোনটা কোথায়, খুঁজতে হত।
 */
final class TheSystemMenuStandsInSectionsTest extends TestCase
{
    use RefreshDatabase;

    // ⓘ পরিকল্পনার ৭ ভাগ — সপ্তমটা "নিরীক্ষা" (বদলের ইতিহাস, নোটিশের রিপোর্ট), দফা ১ সম্পূর্ণ হলো ৭ নভেম্বর ২০২৬
    private const ORDER = ['home', 'org', 'people', 'modules', 'comms', 'data', 'audit'];

    public function test_every_row_names_a_section_and_the_sections_are_the_plans(): void
    {
        $module = app(ModuleRegistry::class)->get('system_admin');
        $this->assertSame(self::ORDER, $module->nav['panel_sections'] ?? null, '⛔ সিস্টেম প্রশাসনের মেনুর ভাগ পরিকল্পনার ক্রমে নয়।');

        $loose = [];
        foreach ($module->menu as $items) {
            foreach ($items as $item) {
                if (! in_array($item['section'] ?? null, self::ORDER, true)) {
                    $loose[] = $item['label'];
                }
            }
        }
        $this->assertSame([], $loose, '⛔ এই সারিগুলোর ভাগ নেই — মেনুর শেষে একা পড়ে থাকবে: '.implode(', ', $loose));
    }

    public function test_the_panel_draws_the_headings_in_order_with_each_row_under_its_own(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $html = (string) $this->actingAs($owner)->get(route('system_admin.control-panel'))->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $nav = (new DOMXPath($dom))->query('//nav[.//p[@data-panel-section]]')->item(0);
        $this->assertNotNull($nav, '⛔ বাঁয়ের মেনুতে কোনো ভাগের শিরোনাম নেই।');

        $headings = [];
        $under = [];
        $current = null;
        foreach ((new DOMXPath($dom))->query('.//p[@data-panel-section] | .//a', $nav) as $node) {
            if ($node->nodeName === 'p') {
                $current = $node->getAttribute('data-panel-section');
                $headings[] = $current;
                $this->assertSame(__('system_admin::menu.section_'.$current), trim($node->textContent), "⛔ \"{$current}\" ভাগের নাম লেখা নেই।");

                continue;
            }
            $under[trim($node->textContent)] = $current;
        }

        $this->assertSame(self::ORDER, $headings, '⛔ ভাগের শিরোনাম পরিকল্পনার ক্রমে নয়, বা একই ভাগ দুইবার।');
        foreach ([
            'system_admin::menu.companies' => 'org',
            'system_admin::menu.users' => 'people',
            'system_admin::menu.control_panel' => 'modules',
            'system_admin::notice.title' => 'comms',
            'core.import.title' => 'data',
            'system_admin::menu.change_history' => 'audit',
        ] as $label => $section) {
            $this->assertSame($section, $under[__($label)] ?? null, '⛔ "'.__($label)."\" নিজের ভাগ \"{$section}\"-এর নিচে নেই।");
        }
    }

    public function test_a_broken_section_list_is_refused_when_the_module_loads(): void
    {
        $method = new \ReflectionMethod(\App\Core\Module\ModuleDefinition::class, 'validateNav');
        $method->setAccessible(true);
        foreach ([['a', 'a'], [], ['a', '']] as $bad) {
            try {
                $method->invoke(null, ['section' => 'system', 'order' => 1, 'panel_sections' => $bad], 'x/module.php', 'x');
                $this->fail('⛔ ভুল ভাগের তালিকা ('.json_encode($bad).') মেনে নেওয়া হলো।');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
