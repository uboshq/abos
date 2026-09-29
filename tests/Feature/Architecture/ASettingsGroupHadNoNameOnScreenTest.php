<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সেটিংস পাতায় দলের নামের জায়গায় কাঁচা চাবি ছাপা হত।
 *
 * ── ⛔ কী দেখা গিয়েছিল, ২৯ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * লাইভের মতো করে প্রতিটা মেনু পাতা হেঁটে দেখতে গিয়ে `/accounts/settings`
 * পাতায় অনূদিত নামের বদলে **চাবিটাই** ছাপা পাওয়া গেল:
 * `accounts::settings_group.module`, `.item`, `.group`।
 *
 * ── ⚠️ আর এই বাগটা এই রিপোতে আগে একবার সারানো হয়েছে ──────────────────
 * ⓘ `lang/bn/core.php`-এর `settings_group`-এর উপরেই লেখা আছে সে কেন
 * জন্মেছিল: *"পর্দায় কাঁচা চাবিটা দেখা যেত (`customer::settings_group.entry`)"*।
 *
 * ⛔ কিন্তু সারাইটা **ভাগাভাগি করা হয়নি**। সিস্টেমের সেটিংস পাতা
 * `core.settings_group.*` দেখে, কন্ট্রোল প্যানেল তিন ধাপের ফলব্যাক
 * রাখে — আর হিসাবের পাতা নিজের একটা কপি নিয়ে বসে ছিল, তাই বাগটা
 * ওখানেই টিকে গেল। ⚠️ এক নিয়মের তিনটা কপি মানে দুইটা কপি একদিন পিছিয়ে
 * পড়বেই।
 *
 * ── ⓘ দলগুলোর তিনটা উৎস, আর তিনটাই এখানে আসে ─────────────────────────
 * ⭐ মডিউলের নিজের সেটিংস (`module.php`-র `settings`), আর কোরের বসানো
 * তিনটা পর্দা-সুইচ ([[SettingsService]] — `module`, `group`, `item`)।
 * ⓘ তালিকাটা অনুমান করা হয় না, দুই উৎস থেকেই তোলা হয় — তাই নতুন একটা
 * দল যোগ হলে সে **নিজে থেকেই** এই পরীক্ষার আওতায় আসে।
 *
 * ── ⛔ কেন পুরনো পাহারাগুলো এটা ধরেনি ────────────────────────────────
 * ⓘ [[AKeyBuiltFromACodeHadNoWordsTest]] ঠিক এই ফাঁদের জন্যই বানানো, আর
 * সে তিনটা পরিবার চেনে: নিরীক্ষার কাজ, কারণ-কোডের প্রসঙ্গ, নিজস্ব ঘরের
 * উৎস। ⚠️ সেটিংসের দল **চতুর্থ পরিবার**, আর ঐ পাহারার নকশাই এমন যে
 * প্রতিটা পরিবার কাউকে হাতে যোগ করতে হয়।
 *
 * ⛔ [[BothLanguagesSayTheSameThingTest]]-ও ধরে না, আর কারণটা ঐ ফাইলেই
 * লেখা: দুই ভাষাতেই চাবিটা নেই, তাই তুলনা নিখুঁত মেলে। **যে ফাঁক দুই
 * পাশেই সমান, তুলনা সেটা দেখতে পায় না।**
 */
final class ASettingsGroupHadNoNameOnScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_settings_group_has_a_name_in_both_languages(): void
    {
        $looked = 0;
        $missing = [];

        foreach ($this->groupsByModule() as $module => $groups) {
            foreach ($groups as $group) {
                $looked++;

                foreach (['bn', 'en'] as $locale) {
                    if ($this->nameOf($module, $group, $locale) === null) {
                        $missing[] = $module.'::'.$group.' ('.$locale.')';
                    }
                }
            }
        }

        /*
         * ⛔ কয়টা দল সত্যিই দেখা হলো, আগে সেটা প্রমাণ করা হয়। ⚠️ নাহলে
         * উৎস দুইটার একটা ভেঙে গেলে পাহারাটা **শূন্য** দল দেখে সবুজ
         * থাকত — আর এই প্রকল্পে ঐ ধরনের পাহারা আগে ধরা পড়েছে।
         */
        $this->assertGreaterThanOrEqual(10, $looked,
            'মাত্র '.$looked.'টা দল চোখে পড়ল — উৎস দুইটা ঠিক আছে কি না দেখুন।');

        $this->assertSame([], $missing,
            "সেটিংসের এই দলগুলোর কোনো নাম নেই, তাই পর্দায় কাঁচা চাবি ছাপা হবে:\n  "
            .implode("\n  ", $missing));
    }

    public function test_the_settings_page_prints_a_name_and_never_a_key(): void
    {
        /*
         * ⛔ শব্দগুলো থাকা আর পর্দায় আসা এক কথা নয় — এই প্রকল্পের সবচেয়ে
         * চেনা ফাঁদ। ⚠️ উপরের দাবিটা সবুজ রেখেও কেউ পাতাটার ফলব্যাকটা তুলে
         * দিতে পারত, আর কাঁচা চাবি আবার ফিরত।
         *
         * ⓘ তাই পাতাটা সত্যিই আঁকিয়ে দেখা হয়।
         */
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $html = $this->actingAs($owner)->get('/accounts/settings')->assertOk()->getContent();

        CompanyContext::clear();

        $this->assertSame(0, preg_match('/[a-z_]+::settings_group\./', $html),
            'সেটিংস পাতায় কাঁচা চাবি ছাপা হচ্ছে।');

        /*
         * ⛔ আর শিরোনামগুলো সত্যিই আছে — নাহলে উপরের দাবিটা একটা **খালি**
         * পাতাতেও সবুজ থাকত।
         */
        preg_match_all('#<h2[^>]*>(.*?)</h2>#s', $html, $found);

        $names = array_values(array_filter(array_map(
            fn (string $h) => trim(strip_tags($h)),
            $found[1],
        )));

        $this->assertGreaterThanOrEqual(2, count($names),
            'পাতায় দলের শিরোনামই পাওয়া গেল না — দাবিটা তখন কিছুই মাপছে না।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * কোন মডিউলে কোন দলগুলো আছে — দুইটা উৎস মিলিয়ে।
     *
     * @return array<string, list<string>>
     */
    private function groupsByModule(): array
    {
        $out = [];

        /*
         * ⓘ [[SettingsService::definitions()]] মডিউলের নিজের সেটিংস আর
         * কোরের বসানো পর্দা-সুইচ — দুইটাই ফেরায়। ⭐ তাই এখান থেকে নিলে
         * কোনো পরিবার বাদ পড়ে না।
         */
        foreach (app(SettingsService::class)->definitions() as $definition) {
            $module = $definition['module'] ?? null;

            if ($module === null) {
                continue;
            }

            $group = $definition['group'] ?? 'general';

            $out[$module][$group] = true;
        }

        /* ⓘ রেজিস্ট্রিতে নেই এমন কোড থাকলে সেটা আলাদা সমস্যা, এখানে নয় */
        $known = array_map(fn ($m) => $m->code, app(ModuleRegistry::class)->all());

        return array_map(
            fn (array $groups) => array_values(array_keys($groups)),
            array_filter($out, fn ($_, $code) => in_array($code, $known, true), ARRAY_FILTER_USE_BOTH),
        );
    }

    /**
     * পর্দায় যে নামটা আসবে — মডিউলের নিজেরটা, নাহলে কোরের সাধারণটা।
     *
     * ⓘ ঠিক এই দুই ধাপই পর্দাগুলো করে; এখানে তৃতীয় ধাপটা (`ucfirst`)
     * ইচ্ছাকৃতভাবে ধরা হয় না — ⚠️ ওটা একটা ইংরেজি শব্দ, আর মালিক বাংলা
     * পড়েন, তাই "Entry" দেখানো কাঁচা চাবির চেয়ে সামান্যই ভালো।
     */
    private function nameOf(string $module, string $group, string $locale): ?string
    {
        foreach ([[$module, 'settings_group'], [null, 'core']] as [$namespace, $file]) {
            $words = $this->wordsIn($locale, $file, $namespace);

            $found = $namespace === null
                ? ($words['settings_group'][$group] ?? null)
                : ($words[$group] ?? null);

            if (is_string($found) && $found !== '') {
                return $found;
            }
        }

        return null;
    }

    /**
     * ভাষা-ফাইলটা নিজে — অনুবাদকের মধ্য দিয়ে নয়।
     *
     * ── ⛔ কেন, আর এটা মেপে পাওয়া ───────────────────────────────────────
     * আগে এখানে `__($key, [], $locale)` ছিল। ⚠️ একটা মিউট্যান্ট বাংলা
     * নামটা মুছে দিলেও পাহারাটা সবুজ থেকে গেল — কারণ অনুবাদক তখন
     * **ফলব্যাক ভাষা** থেকে ইংরেজিটা ফেরায়, আর সেটা চাবির সমান নয়।
     *
     * ⛔ অথচ মালিক কেবল বাংলা পড়েন: তাঁর পর্দায় ইংরেজি শব্দ মানে ঠিক
     * সেই বাগটাই, যেটা এই ফাইল আটকাতে বসেছে।
     *
     * ⓘ [[BothLanguagesSayTheSameThingTest]] এই কারণেই ফাইল মেলায়,
     * অনুবাদ নয় — একই পথ।
     *
     * @return array<string, mixed>
     */
    private function wordsIn(string $locale, string $file, ?string $namespace): array
    {
        $path = $namespace === null
            ? base_path('lang/'.$locale.'/'.$file.'.php')
            : base_path('app/Modules/'.$this->folderOf($namespace)
                .'/Resources/lang/'.$locale.'/'.$file.'.php');

        if (! is_file($path)) {
            return [];
        }

        $words = require $path;

        return is_array($words) ? $words : [];
    }

    /** `master_data` → `MasterData`; মডিউলের কোড থেকে ফোল্ডারের নাম। */
    private function folderOf(string $code): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $code)));
    }
}
