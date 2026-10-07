<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\BranchSettings;
use App\Core\Services\SettingOptions;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Sales\Support\PaperDesigns;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * ⛔ বিক্রয় ট্যাব সংরক্ষণ করতেই প্রতিটা বাছাইয়ে `"0"` — লাইভ, ৩ অক্টোবর ২০২৬।
 *
 * ── ⚠️ কী ঘটেছিল ─────────────────────────────────────────────────────────
 * মালিক কন্ট্রোল প্যানেলের বিক্রয় ট্যাব (`?tab=sales`) দুইবার সংরক্ষণ করলেন — ১৩:৩২:৪৬-এ
 * কোম্পানি ৪, ১৪:০৮:৩১-এ কোম্পানি ৫। ⓘ কিছুই বদলাননি, তবু প্রতিবার ~১৮টা সারি বসল মান
 * `"0"` আর ধরন `choice` নিয়ে: `sales.print.paper.*` (বিল, গেট পাস, চালান, অর্ডার, রসিদ),
 * `sales.print.design.*` (A4/A5/থার্মাল), `sales.print.signature_count`, `sales.margin.action`।
 * ⛔ কোনোটার তালিকায় `0` নেই; অচেনা নকশা মানে পুরনো চলতি কাগজ — **মালিকের বাছা নকশাগুলো
 * নীরবে ছাপা বন্ধ হয়ে গেল**। সারিগুলো লাইভ থেকে হাতে মোছা হয়েছে।
 *
 * ── ⓘ কেন ঘটেছিল ──────────────────────────────────────────────────────────
 * ১. পাতার `<select>` তালিকাকে `মান => নমুনা` ধরত (`@foreach (… as $value => $sample)`), অথচ
 *    কাগজ-নকশার তালিকা খালি তালিকা (`['a4', 'a5', …]`) — তাই `value="0"`, `value="1"`…; আর
 *    `selected` কখনো মিলত না বলে ব্রাউজার প্রথমটা, অর্থাৎ `0`, পাঠাত।
 * ২. কন্ট্রোলারের লুপে `choice`-এর কোনো শাখা ছিল না — `default => $raw`, তাই `"0"` সোজা বসত।
 * ৩. `SettingsService::set()` কাউকে থামাত না।
 *
 * ⭐ তাই তিনটা দাবি, তিন স্তরে: পাতা যা আঁকে তা ফেরত পাঠালে **কিছুই** বদলায় না; পুরনো পাতা
 * থেকে ঠিক লাইভের `"0"` এলেও কিছু বসে না; আর সেবাটা নিজেই তালিকার বাইরের মান ফিরিয়ে দেয়।
 */
class TheSalesTabSavedZeroIntoEveryChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
    }

    /**
     * ১ · লাইভের ঘটনাটা হুবহু: বিক্রয় ট্যাব খোলা, কিছু না বদলে সংরক্ষণ — একটাও সারি নয়।
     *
     * ⚠️ পাঠানোটা হাতে বানানো নয়: পাতা যা আঁকে, ব্রাউজার যা পাঠাত ঠিক তা-ই ([[formFields()]])।
     * হাতে বানালে পরীক্ষা নিজেই সঠিক মান বসিয়ে দিত, আর পাতার ভুলটা কখনো দেখত না।
     */
    public function test_saving_the_sales_tab_unchanged_writes_no_setting_and_keeps_the_chosen_designs(): void
    {
        $settings = app(SettingsService::class);

        /* ⓘ মালিকের বাছা নকশা — লাইভে ঠিক এগুলোই হারিয়েছিল */
        $challan = PaperDesigns::key('challan', 'a4');
        $chosen = collect(SettingOptions::of($settings->definitions()[$challan]))
            ->first(fn (string $code) => $code !== 'standard' && $code !== $settings->get($challan));
        $this->assertNotNull($chosen, "{$challan}-এ বাছার মতো কোনো নকশাই নেই — দাবিটা কিছু দেখত না।");
        $settings->set($challan, $chosen);
        $settings->set('sales.print.paper.invoice', 'a5');
        $settings->set('sales.margin.action', 'approval');

        /* ⓘ মালিক যে ঠিকানায় ছিলেন — `?tab=sales` (এখন বিক্রয়ের ভাঁজ খোলা পাতায় যায়) */
        $html = (string) $this->followingRedirects()
            ->get(route('system_admin.control-panel', ['tab' => 'sales']))
            ->assertOk()->getContent();

        $form = $this->formFields($html, route('system_admin.control-panel.update'));

        /* ⚠️ দাবিটা ফাঁকা নয়: লাইভে নষ্ট হওয়া ঘরগুলো সত্যিই ফর্মে আছে */
        foreach (['sales.print.paper.invoice', 'sales.print.design.invoice', $challan,
            'sales.print.signature_count', 'sales.margin.action'] as $key) {
            $this->assertArrayHasKey($key, $form['settings'], "{$key} ফর্মে নেই — পরীক্ষাটা লাইভের পথ হাঁটছে না।");
            $this->assertContains($key, $form['scope']);
        }

        /* ⛔ পাতা নিজেই কোনো বাছাইয়ে তালিকার বাইরের মান পাঠায় না (লাইভে পাঠাত `0`) */
        foreach ($form['settings'] as $key => $value) {
            $definition = $settings->definitions()[$key] ?? null;

            if ($definition === null || SettingOptions::of($definition) === null) {
                continue;
            }

            $this->assertTrue(SettingOptions::allows($definition, $value),
                "পাতা {$key}-এর জন্য '{$value}' পাঠায় — তালিকায় নেই: ".implode(', ', SettingOptions::of($definition)));
            $this->assertSame((string) $settings->get($key), $value,
                "পাতা {$key}-এর বর্তমান মানটা বাছাই করে দেখায় না — ব্রাউজার অন্যটা পাঠাবে।");
        }

        $before = $this->rows();

        $this->put(route('system_admin.control-panel.update'), $form)
            ->assertRedirect()->assertSessionHasNoErrors();

        $settings->flush();

        $this->assertSame($before, $this->rows(), 'কিছু না বদলে সংরক্ষণ করতেই সেটিংসের সারি বদলেছে।');
        $this->assertSame($chosen, $settings->get($challan), 'মালিকের বাছা চালানের নকশা হারিয়েছে।');
        $this->assertSame('a5', $settings->get('sales.print.paper.invoice'));
        $this->assertSame('approval', $settings->get('sales.margin.action'));
        $this->assertNoChoiceOutsideItsOptions();
    }

    /**
     * ২ · পুরনো খোলা পাতা থেকে ঠিক লাইভের পাঠানোটা — প্রতিটা তালিকাওয়ালা ঘরে `"0"`।
     *
     * ⓘ লাইভের পাতা ডিপ্লয়ের আগে খোলা থাকলে নতুন সার্ভারেও এটাই আসবে। ⛔ "যা ছিল তাই থাক" —
     * একটাও সারি নয়, আর পাতা ৫০০-ও নয়।
     */
    public function test_an_old_page_posting_zero_into_every_choice_changes_nothing(): void
    {
        $keys = $this->keysWithOptions();

        $this->assertContains('sales.print.paper.invoice', $keys);
        $this->assertContains('sales.margin.action', $keys);
        $this->assertContains('sales.print.signature_count', $keys);

        $before = $this->rows();

        $this->put(route('system_admin.control-panel.update'), [
            'scope' => $keys,
            'settings' => array_fill_keys($keys, '0'),
        ])->assertRedirect();

        app(SettingsService::class)->flush();

        $this->assertSame($before, $this->rows(), 'অচেনা `0` তালিকাওয়ালা কোনো ঘরে বসে গেছে।');
        $this->assertNoChoiceOutsideItsOptions();
    }

    /**
     * ৩ · সাধারণ সেটিংসের পর্দাও — একই ভুলের দ্বিতীয় দরজা।
     */
    public function test_the_settings_screen_saved_unchanged_or_with_zeros_writes_nothing(): void
    {
        $html = (string) $this->get(route('system_admin.settings'))->assertOk()->getContent();
        $form = $this->formFields($html, route('system_admin.settings.update'));

        $this->assertArrayHasKey('sales.margin.action', $form['settings'], 'সেটিংসের পাতায় মার্জিনের ঘর নেই।');

        $before = $this->rows();

        $this->put(route('system_admin.settings.update'), $form)->assertRedirect()->assertSessionHasNoErrors();
        app(SettingsService::class)->flush();
        $this->assertSame($before, $this->rows(), 'সেটিংসের পাতা কিছু না বদলে সারি বসিয়েছে।');

        $zeros = $form;

        foreach ($this->keysWithOptions() as $key) {
            if (array_key_exists($key, $zeros['settings'])) {
                $zeros['settings'][$key] = '0';
            }
        }

        $this->put(route('system_admin.settings.update'), $zeros)->assertRedirect();
        app(SettingsService::class)->flush();
        $this->assertSame($before, $this->rows(), 'সেটিংসের পাতায় `0` তালিকাওয়ালা ঘরে বসে গেছে।');
    }

    /**
     * ৪ · শেষ পাহারা — সেবা নিজেই তালিকার বাইরের মান ফিরিয়ে দেয়, যে দরজা দিয়েই আসুক।
     */
    public function test_the_service_refuses_any_value_outside_the_declared_options(): void
    {
        $settings = app(SettingsService::class);
        $keys = $this->keysWithOptions();
        $refused = 0;

        foreach ($keys as $key) {
            $definition = $settings->definitions()[$key];

            if (in_array('0', SettingOptions::of($definition), true)) {
                continue;
            }

            try {
                $settings->set($key, '0');
                $this->fail("{$key}-এ '0' বসে গেল — তালিকা: ".implode(', ', SettingOptions::of($definition)));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
                $refused++;
            }
        }

        $this->assertGreaterThanOrEqual(18, $refused, 'লাইভের ১৮টা ঘরের চেয়ে কম যাচাই হলো।');
        $this->assertSame(0, Setting::query()->whereIn('key', $keys)->where('value', '0')->count());

        /* ⓘ বৈধ মান চলে — তিন আকারের তালিকাতেই */
        $settings->set('sales.print.paper.invoice', 'a5');                      // খালি তালিকা
        $settings->set('sales.print.signature_count', 4);                         // সংখ্যা = লেখা '4'
        $settings->set('company.date_format', 'Y-m-d');                           // ডাক → `মান => নমুনা`, চাবিটাই মান
        $settings->set('sales.price_policy', 'warn');                             // `মান => নাম`
        $settings->flush();
        $this->assertSame('a5', $settings->get('sales.print.paper.invoice'));
        $this->assertSame('4', (string) $settings->get('sales.print.signature_count'));
        $this->assertSame('Y-m-d', $settings->get('company.date_format'));

        /* ⛔ নমুনা মান নয়, `true` মান নয় */
        foreach ([['company.date_format', '2026-02-18'], ['sales.margin.action', true], ['sales.print.paper.invoice', null]] as [$key, $bad]) {
            try {
                $settings->set($key, $bad);
                $this->fail("{$key}-এ ".var_export($bad, true).' বসে গেল।');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        /* ⓘ তালিকাহীন ঘর আগের মতোই খোলা */
        $settings->set('accounts.backdate_days', 30);
        $settings->flush();
        $this->assertSame(30, $settings->get('accounts.backdate_days'));
    }

    /**
     * ৫ · শাখার স্তরেও একই পাহারা — নকশা আর কাগজ শাখা ধরেও বসে।
     */
    public function test_a_branch_override_outside_the_options_is_refused(): void
    {
        $branch = Branch::create(['company_id' => $this->company->id, 'code' => 'ZERO', 'name_en' => 'Zero Branch']);
        $key = PaperDesigns::key('challan', 'a4');

        $this->expectException(InvalidArgumentException::class);

        try {
            app(BranchSettings::class)->set($key, $branch->id, '0');
        } finally {
            $this->assertNull(app(BranchSettings::class)->own($key, $branch->id), 'শাখার সারিতে `0` বসে গেছে।');
        }
    }

    /** @return list<string> তালিকা ঘোষণা করা প্রতিটা সেটিং */
    private function keysWithOptions(): array
    {
        return array_keys(array_filter(
            app(SettingsService::class)->definitions(),
            fn (array $d) => ! ($d['menu'] ?? false) && SettingOptions::of($d) !== null,
        ));
    }

    /** ⛔ ডাটাবেসের কোনো সারিতে তালিকার বাইরের মান নেই — কোম্পানির বা গোটা ব্যবস্থার */
    private function assertNoChoiceOutsideItsOptions(): void
    {
        $definitions = app(SettingsService::class)->definitions();

        foreach (Setting::query()->get() as $row) {
            $definition = $definitions[$row->key] ?? null;

            if ($definition === null || SettingOptions::of($definition) === null) {
                continue;
            }

            $this->assertTrue(SettingOptions::allows($definition, (string) $row->value),
                "সারি {$row->key} = '{$row->value}' (কোম্পানি {$row->company_id}) — তালিকায় নেই।");
        }
    }

    /** @return array<string, string> কোম্পানি|চাবি => মান, প্রতিটা সেটিংসের সারি */
    private function rows(): array
    {
        return Setting::query()->orderBy('company_id')->orderBy('key')->get()
            ->mapWithKeys(fn (Setting $s) => [$s->company_id.'|'.$s->key => (string) $s->getRawOriginal('value')])
            ->all();
    }

    /**
     * ফর্মটা ব্রাউজার যা পাঠাত ঠিক তা-ই — বন্ধ চেকবক্স বাদ, `<select>`-এ বাছা (না থাকলে প্রথম) বিকল্প।
     *
     * ⚠️ "না থাকলে প্রথম" — লাইভের ভুলটা ঠিক ওখানেই ছিল: কোনো `selected` মিলত না, আর ব্রাউজার
     * প্রথম বিকল্পটা, `value="0"`, পাঠাত।
     *
     * @return array{_method: string, scope: list<string>, settings: array<string, string>}
     */
    private function formFields(string $html, string $action): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $forms = $xpath->query('//form[@action="'.$action.'"]');
        $this->assertSame(1, $forms->length, "{$action}-এর ফর্ম ঠিক একটা নয়।");

        $out = ['_method' => 'PUT', 'scope' => [], 'settings' => []];

        foreach ($xpath->query('.//input | .//select | .//textarea', $forms->item(0)) as $field) {
            /** @var \DOMElement $field */
            $name = $field->getAttribute('name');

            if ($name === '' || $field->hasAttribute('disabled')) {
                continue;
            }

            if ($field->tagName === 'select') {
                $chosen = $xpath->query('.//option[@selected]', $field)->item(0)
                    ?? $xpath->query('.//option', $field)->item(0);
                $value = $chosen instanceof \DOMElement ? $chosen->getAttribute('value') : '';
            } elseif ($field->tagName === 'textarea') {
                $value = $field->textContent;
            } elseif (in_array($field->getAttribute('type'), ['checkbox', 'radio'], true)) {
                if (! $field->hasAttribute('checked')) {
                    continue;
                }
                $value = $field->hasAttribute('value') ? $field->getAttribute('value') : 'on';
            } else {
                $value = $field->getAttribute('value');
            }

            if ($name === 'scope[]') {
                $out['scope'][] = $value;
            } elseif (preg_match('/^settings\[(.+)\]$/', $name, $m)) {
                $out['settings'][$m[1]] = $value;
            }
        }

        return $out;
    }
}
