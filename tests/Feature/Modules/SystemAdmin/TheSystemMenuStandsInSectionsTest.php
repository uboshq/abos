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

    // ⭐ মালিকের নতুন ক্রম (২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4, "All ok") — আলাদা "নিরীক্ষা" ভাগ নেই, অতিথির "নিয়ন্ত্রণ ও নিরীক্ষা"-ই একমাত্র
    private const ORDER = ['home', 'people', 'org', 'modules', 'control', 'comms', 'data'];

    // ⓘ on the panel: the host's seven, then the guests of the menu merge in menu order — Governance, Backup (owner, 23 Nov 2026; 2a2239ea9)
    private const DRAWN = [...self::ORDER, 'governance', 'backup'];

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
        // ⓘ শিরোনাম `<p>`, আর ভাঁজ-করা মেনুর রূপে (ABOS-এর রূপগুলো, fe/hotfix-msg ২৩ নভেম্বর ২০২৬) ভাঁজের `<button>` — দুটোই
        //   একই `data-panel-section` বহন করে; সারিগুলো ভাঁজের ভিতরেও DOM-এ থাকে
        $nav = (new DOMXPath($dom))->query('//nav[.//*[@data-panel-section]]')->item(0);
        $this->assertNotNull($nav, '⛔ বাঁয়ের মেনুতে কোনো ভাগের শিরোনাম নেই।');

        $headings = [];
        $under = [];
        $current = null;
        foreach ((new DOMXPath($dom))->query('.//*[@data-panel-section] | .//a', $nav) as $node) {
            if ($node instanceof \DOMElement && $node->hasAttribute('data-panel-section')) {
                $current = $node->getAttribute('data-panel-section');
                $headings[] = $current;
                $this->assertSame(__('system_admin::menu.section_'.$current), trim($node->textContent), "⛔ \"{$current}\" ভাগের নাম লেখা নেই।");

                continue;
            }
            $under[trim($node->textContent)] = $current;
        }

        $this->assertSame(self::DRAWN, $headings, '⛔ ভাগের শিরোনাম পরিকল্পনার ক্রমে নয়, বা একই ভাগ দুইবার।');
        foreach ([
            'system_admin::menu.companies' => 'org',
            'system_admin::menu.users' => 'people',
            'system_admin::menu.control_panel' => 'control',
            'master_data::menu.number_series' => 'control',
            'core.custom_field.title' => 'control',
            'core.look.title' => 'org',
            'system_admin::branch_module.title' => 'modules',
            'system_admin::notice.title' => 'comms',
            'core.import.title' => 'data',
            'core.notice.report_register' => 'comms',
            'core.notice.report_signatures' => 'comms',
        ] as $label => $section) {
            $this->assertSame($section, $under[__($label)] ?? null, '⛔ "'.__($label)."\" নিজের ভাগ \"{$section}\"-এর নিচে নেই।");
        }
    }

    /** ⛔ সাজানো বদলালেও কিছু হারায়নি — আগের ৩৬টা সারি (আশ্রয়দাতা আর দুই অতিথি) সব আছে, মেনুতে আর ঠিকানায় */
    public function test_none_of_the_thirty_six_rows_is_lost(): void
    {
        $before = [
            'system_admin' => ['system_admin::dashboard.title', 'system_admin::menu.companies', 'system_admin::menu.branches', 'system_admin::menu.users',
                'system_admin::menu.roles', 'system_admin::menu.login_security', 'system_admin::menu.ownership', 'core.notice.report_register',
                'core.notice.report_signatures', 'system_admin::menu.report_schedules', 'core.import.title', 'system_admin::menu.control_panel',
                'system_admin::branch_module.title', 'system_admin::notice.title', 'core.notice.analytics_title', 'core.notice.categories_title',
                'core.notice.templates_title', 'master_data::menu.number_series', 'core.custom_field.title', 'core.look.title'],
            'governance' => ['governance::dashboard.title', 'governance::menu.audit_trail', 'governance::audit_report.changes_title',
                'governance::audit_report.backdated_title', 'governance::audit_report.periods_title', 'governance::menu.export_log',
                'governance::menu.retention', 'governance::menu.login_history', 'governance::menu.error_log'],
            'backup' => ['backup::dashboard.title', 'backup::menu.backups', 'backup::menu.destinations', 'backup::menu.policies',
                'backup::menu.restore', 'backup::menu.verifications', 'backup::menu.disaster_recovery'],
        ];
        $this->assertSame(36, array_sum(array_map('count', $before)));

        foreach ($before as $code => $labels) {
            $now = [];
            foreach (app(ModuleRegistry::class)->get($code)->menu as $items) {
                foreach ($items as $item) {
                    $now[] = $item['label'];
                    $this->assertTrue(\Illuminate\Support\Facades\Route::has($item['route']), "⛔ {$item['label']}: ঠিকানা নেই");
                }
            }
            $this->assertSame([], array_values(array_diff($labels, $now)), "⛔ {$code}: এই সারিগুলো মেনু থেকে হারিয়েছে");
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
