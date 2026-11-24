<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Modules\SystemAdmin\Support\ControlPanelTabs;
use App\Modules\SystemAdmin\Support\ControlSubjects;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ কন্ট্রোল প্যানেল বিষয় ধরে (মালিক, ২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4)।
 *
 * দাবি: প্রতিটা সেটিং ঠিক এক বিষয়ে, ঠিক একবার · ছাঁকা পাতার সংরক্ষণ বাকি সেটিং ছোঁয় না · না বদলে সংরক্ষণ কিছু লেখে না · পুরনো ঠিকানা খোলে ·
 *   খোঁজা ঠিক বিষয়ে নেয় · `screens` দলের সুইচ মডিউলের পর্দায়
 */
final class EverySettingStandsInOneSubjectTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    /** @return list<string> */
    private function drawn(array $query): array
    {
        $html = (string) $this->get(route('system_admin.control-panel', $query))->assertOk()->getContent();
        preg_match_all('/name="settings\[([^\]]+)\]"/', $html, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_every_setting_appears_exactly_once_across_the_subjects(): void
    {
        $seen = [];
        foreach (ControlSubjects::ALL as $subject) {
            foreach ($this->drawn(['tab' => 'subject-'.$subject]) as $key) {
                $this->assertArrayNotHasKey($key, $seen, "⛔ {$key} দুই বিষয়ে: ".($seen[$key] ?? '').", {$subject}");
                $seen[$key] = $subject;
            }
        }

        $expected = array_keys(array_filter(app(SettingsService::class)->definitions(), fn (array $d) => ! ($d['menu'] ?? false) && ! isset($d['owned_by'])
            && ! isset($d['tab']) && ($d['type'] ?? null) !== 'json' && ($d['group'] ?? '') !== 'screens'));
        $this->assertEqualsCanonicalizing($expected, array_keys($seen), '⛔ কোনো সেটিং বিষয়ে নেই, বা বাড়তি আছে।');
        $this->assertSame(count($expected), array_sum(app(ControlPanelTabs::class)->counts()), '⛔ বাঁয়ের গোনা মেলে না।');
    }

    public function test_the_screens_switches_stand_on_the_modules_screens_page(): void
    {
        foreach (app(SettingsService::class)->definitions() as $key => $d) {
            if (($d['group'] ?? '') !== 'screens' || ($d['menu'] ?? false) || isset($d['owned_by']) || isset($d['tab'])) {
                continue;
            }
            $html = (string) $this->get(route('system_admin.module.screens', ['module' => $d['module']]))->assertOk()->getContent();
            $this->assertStringContainsString('name="settings['.$key.']"', $html, "⛔ {$key} কোনো পাতায় নেই।");
        }
    }

    public function test_a_filtered_page_carries_only_what_it_drew_and_saving_it_unchanged_writes_nothing(): void
    {
        $html = (string) $this->get(route('system_admin.control-panel', ['module' => 'sales', 'tab' => 'subject-general']))->assertOk()->getContent();
        preg_match_all('/name="scope\[\]" value="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m[1]);

        $definitions = app(SettingsService::class)->definitions();
        foreach ($m[1] as $key) {
            $this->assertSame('sales', $definitions[$key]['module'], "⛔ ছাঁকা পাতা {$key} বহন করে।");
        }

        $before = Setting::query()->orderBy('id')->get(['key', 'value'])->toArray();
        $values = [];
        foreach ($m[1] as $key) {
            $value = app(SettingsService::class)->get($key);
            if ($value !== false && is_scalar($value)) {
                $values[$key] = $value === true ? '1' : (string) $value;
            }
        }
        $this->put(route('system_admin.control-panel.update'), ['scope' => $m[1], 'settings' => $values])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, Setting::query()->orderBy('id')->get(['key', 'value'])->toArray(), '⛔ কিছু না বদলে সংরক্ষণে সেটিং বদলেছে।');
    }

    public function test_the_old_addresses_still_open(): void
    {
        foreach (['mobile', 'accounts', 'sales', 'system_admin', 'no-such-tab'] as $tab) {
            $this->get(route('system_admin.control-panel', ['tab' => $tab]))->assertOk();
        }
    }

    public function test_finding_a_setting_points_at_its_subject_and_search_reaches_every_subject(): void
    {
        $label = __('system_admin::settings.date_format');
        $hit = collect(app(ControlPanelTabs::class)->find($label))->firstWhere('label', $label);
        $this->assertSame(route('system_admin.control-panel', ['tab' => 'subject-general']), $hit['url'] ?? null);

        $this->assertNotEmpty($this->drawn(['tab' => 'subject-sales', 'q' => 'system.']), '⛔ খোঁজা কেবল খোলা বিষয়েই আটকে আছে।');
    }
}
