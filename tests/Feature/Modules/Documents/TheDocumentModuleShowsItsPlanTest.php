<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Documents\Support\DocumentPlan;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ডকুমেন্ট ম্যানেজমেন্ট (DOC) — কাঠামোটা সত্যিই দাঁড়িয়েছে, আর কিছুই ভান করে না।
 *
 * ⭐ মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬: *"ei plan soho live e ekta module
 * baniye rakho but code pore korbo"*; বাছাই: *"২১টা মেনুই দেখাবে"*।
 *
 * ── ⓘ চারটা দাবি ─────────────────────────────────────────────────────
 *   ১. মেনুর প্রতিটা পাতা চাবি ছাড়া ৪০৩, চাবিসহ ২০০ — **একই মানুষ**, কেবল
 *      চাবিটা বদলায়। ⚠️ দুইজন আলাদা মানুষ হলে একটা সুইচ বা সদস্যপদও
 *      ৪০৩-টা বানাতে পারত, আর দাবিটা কিছুই প্রমাণ করত না।
 *   ২. সাইডবারে ২১টা সারিই, প্রতিটা খোলা যায়।
 *   ৩. কোনো পাতায় ফর্ম বা জমা-দেওয়ার বোতাম নেই — ⛔ কাজ-না-করা বোতাম নিষেধ।
 *   ৪. বাংলা আর ইংরেজি ফাইলে একই চাবি; আর পাতায় যে ক্লাসের নাম ছাপা হয়,
 *      সেটা রিপোতে সত্যিই আছে।
 *
 * ⚠️ ফর্মের খোঁজ কেবল পাতার নিজের অংশে (`data-doc-page` থেকে
 * `data-doc-page-end`) — শেলের লগআউট বা কোম্পানি বদলের ফর্ম এই পাতার নয়।
 */
final class TheDocumentModuleShowsItsPlanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⭐ যে সারিগুলো এখন আসল পর্দা খোলে, পরিকল্পনার পাতা নয় — প্রথম ধাপ (৮ অক্টোবর ২০২৬)।
     * ⓘ এদের কাজ নিজের টেস্টে ([[ADocumentKeepsEveryVersionTest]], [[ADocumentStaysBehindItsWallsTest]])।
     */
    private const REAL_SCREENS = [
        'documents.index', 'documents.create', 'documents.mine', 'documents.recent',
        // ⓘ ড্যাশবোর্ডের সারি ৬ অক্টোবর থেকেই ড্যাশবোর্ড ইঞ্জিনের আসল পাতা ([[DocumentsDashboard]]), পরিকল্পনা নয়
        'module.dashboard',
        // ⭐ দ্বিতীয় ধাপ (৯ অক্টোবর ২০২৬) — নিজের টেস্টে ([[TheBinTheSearchAndTheAdminWorkTest]])
        'documents.archived', 'documents.bin', 'documents.search', 'documents.admin',
        // ⭐ তৃতীয় ধাপ — নিজের টেস্টে ([[ADocumentGoesThroughItsSignaturesTest]])
        'documents.approval', 'documents.expiry',
        // ⭐ চতুর্থ ধাপ — নিজের টেস্টে ([[ASignatureBelongsToTheBytesItSignedTest]])
        'documents.shared', 'documents.signatures',
        // ⭐ পঞ্চম ধাপ — নিজের টেস্টে ([[AScannedPageIsReadOnOurOwnServerTest]])
        'documents.scan',
        // ⭐ ষষ্ঠ ধাপ — নিজের টেস্টে ([[TheIntelligenceIsRulesOnOurOwnServerTest]])
        'documents.intelligence',
        // ⭐ সপ্তম ধাপ — নিজের টেস্টে ([[TheShelfReportsAndRemembersTest]])
        'documents.templates', 'documents.templates.create', 'documents.reports', 'documents.audit',
    ];

    public function test_every_menu_page_is_shut_without_the_key_and_opens_with_it_for_the_same_person(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        Permission::findOrCreate('documents.view', 'web');

        $pages = $this->menuPages();

        // ⓘ ১৯ — "ইনবক্স" আর "প্রিয়" (এখনো "আসছে") মেনু থেকে সরানো, আসল না হওয়া পর্যন্ত (fe, ১১ অক্টোবর ২০২৬)
        $this->assertCount(19, $pages, 'DOC-এর মেনুতে ১৯টা পাতা থাকার কথা — পাওয়া গেল '.count($pages).'টা।');

        $person = User::factory()->create(['current_company_id' => $company->id]);
        $person->companies()->attach($company->id);

        /* ── চাবি ছাড়া: প্রতিটা দরজা বন্ধ ── */
        $open = [];

        foreach ($pages as [$name, $params, $url]) {
            $status = $this->actingAs($person)->get($url)->getStatusCode();

            if ($status !== 403) {
                $open[] = $name.json_encode($params).' → '.$status;
            }
        }

        $this->assertSame([], $open, implode("\n", [
            'চাবি ছাড়াই এই পাতাগুলো ৪০৩ দিল না:', ...$open,
        ]));

        /* ── একই মানুষ, এবার চাবিসহ ── */
        // ⓘ আপলোডের সারি নিজের চাবি চায় (§১৩) — সেটাও, যাতে সাইডবারে ১৯টা সারিই আসে
        Permission::findOrCreate('documents.upload', 'web');
        Permission::findOrCreate('documents.admin', 'web');
        foreach (['documents.templates', 'documents.report', 'documents.audit'] as $key) {
            Permission::findOrCreate($key, 'web');
        }
        $person->givePermissionTo(['documents.view', 'documents.upload', 'documents.admin',
            'documents.templates', 'documents.report', 'documents.audit']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $person = $person->fresh();

        $shut = [];
        $forms = [];
        $badgeless = [];
        $sidebarMisses = [];

        foreach ($pages as [$name, $params, $url]) {
            $response = $this->actingAs($person)->get($url);

            if ($response->getStatusCode() !== 200) {
                $shut[] = $name.json_encode($params).' → '.$response->getStatusCode();

                continue;
            }

            $html = (string) $response->getContent();

            /* ⭐ প্রথম ধাপের আসল পর্দা (৮ অক্টোবর ২০২৬) — ফর্ম আছে, "আসছে" ব্যাজ নেই; কেবল খোলা আর সাইডবার */
            if (in_array($name, self::REAL_SCREENS, true)) {
                $this->sidebarHasEveryRow($html, $pages, $sidebarMisses);

                continue;
            }

            $own = $this->ownPart($html);

            if ($own === null) {
                $forms[] = $name.json_encode($params).' — পাতার নিজের অংশটাই খুঁজে পাওয়া গেল না';

                continue;
            }

            if (stripos($own, '<form') !== false) {
                $forms[] = $name.json_encode($params).' — <form> আছে';
            }

            if (preg_match('/<button\b[^>]*\btype\s*=\s*["\']?submit/i', $own) === 1) {
                $forms[] = $name.json_encode($params).' — <button type=submit> আছে';
            }

            if (! str_contains($own, e(__('documents::page.coming_soon')))) {
                $badgeless[] = $name.json_encode($params);
            }

            $this->sidebarHasEveryRow($html, $pages, $sidebarMisses);
        }

        $this->assertSame([], $shut, implode("\n", [
            'চাবি হাতে থাকা সত্ত্বেও এই পাতাগুলো খুলল না:', ...$shut,
        ]));

        $this->assertSame([], $forms, implode("\n", [
            '⛔ কাঠামোর পাতায় কাজ-না-করা ফর্ম বা বোতাম:', ...$forms,
        ]));

        $this->assertSame([], $badgeless, implode("\n", [
            'এই পাতাগুলোয় "আসছে" ব্যাজ নেই:', ...$badgeless,
        ]));

        $this->assertSame([], array_values($sidebarMisses), implode("\n", [
            'সাইডবারে DOC-এর এই সারিগুলো নেই:', ...array_values($sidebarMisses),
        ]));

        /* মেনু বানানোর দিক থেকেও — ২১টা সারি, প্রতিটার ঠিকানা আছে */
        $doc = collect(app(MenuBuilder::class)->forUser($person))->firstWhere('code', 'documents');

        $this->assertNotNull($doc, 'চাবিসহ মানুষের মেনুতে DOC-ই নেই।');

        $rows = collect($doc['groups'])->flatten(1);

        $this->assertCount(19, $rows, 'মেনুতে DOC-এর সারি ১৯টা নয়।');

        // ⛔ "আসছে" পাতা মেনুতে নেই — আসল না হওয়া পর্যন্ত
        foreach (['inbox', 'favourite'] as $planned) {
            $this->assertFalse($rows->contains(fn ($row) => str_contains((string) ($row['url'] ?? ''), '/'.$planned)),
                "⛔ পরিকল্পনার পাতা «{$planned}» এখনো মেনুতে।");
        }
        $this->assertSame(0, $rows->where('url', null)->count(), 'DOC-এর কোনো সারি নিভে আছে (ঠিকানা নেই)।');
    }

    public function test_both_languages_carry_the_same_keys_and_no_empty_words(): void
    {
        $keys = [];

        foreach (['bn', 'en'] as $locale) {
            $flat = [];

            foreach (glob(app_path('Modules/Documents/Resources/lang/'.$locale.'/*.php')) ?: [] as $file) {
                foreach ($this->flatten(require $file, basename($file, '.php')) as $key => $value) {
                    $this->assertNotSame('', trim((string) $value), "{$locale}: {$key} খালি।");
                    $flat[] = $key;
                }
            }

            sort($flat);
            $keys[$locale] = $flat;
        }

        $this->assertGreaterThan(100, count($keys['bn']), 'অনুবাদের ফাইলগুলোই পড়া হয়নি — দাবিটা তখন কিছুই দেখছে না।');

        $this->assertSame([], array_values(array_diff($keys['bn'], $keys['en'])), 'বাংলায় আছে, ইংরেজিতে নেই।');
        $this->assertSame([], array_values(array_diff($keys['en'], $keys['bn'])), 'ইংরেজিতে আছে, বাংলায় নেই।');
    }

    public function test_every_system_the_pages_name_is_real_and_every_screen_is_on_the_menu(): void
    {
        foreach (DocumentPlan::SYSTEMS as $key => $class) {
            $this->assertTrue(
                class_exists($class) || trait_exists($class),
                "পাতায় '{$key}' হিসেবে ছাপা {$class} রিপোতে নেই — বানানো নাম ভিত বলে চলতে পারে না।"
            );
        }

        $this->assertSame(range(1, 25), array_keys(DocumentPlan::SECTIONS), 'পরিকল্পনার ২৫টা অংশই চাই, ক্রমে।');

        foreach ([...array_values(DocumentPlan::SCREENS), ...array_values(DocumentPlan::SECTIONS)] as $entry) {
            foreach ($entry['systems'] as $key) {
                $this->assertArrayHasKey($key, DocumentPlan::SYSTEMS, "অচেনা ব্যবস্থার চাবি: {$key}");
            }
        }

        $onMenu = [];

        foreach (app(ModuleRegistry::class)->get('documents')->menu as $items) {
            foreach ($items as $item) {
                if (isset($item['route_params']['screen'])) {
                    $onMenu[] = $item['route_params']['screen'];
                }
            }
        }

        sort($onMenu);
        $planned = DocumentPlan::screenSlugs();
        sort($planned);

        $this->assertSame($planned, $onMenu, 'মেনুর সারি আর পরিকল্পনার পর্দা এক তালিকা নয়।');
    }

    /**
     * সাইডবারে DOC-এর ২১টা সারিই, প্রতিটা নিজের ঠিকানাসহ।
     *
     * @param  list<array{0: string, 1: array<string, mixed>, 2: string}>  $pages
     * @param  array<string, string>  $misses
     */
    private function sidebarHasEveryRow(string $html, array $pages, array &$misses): void
    {
        $aside = $this->sidebar($html);

        foreach ($pages as [, , $rowUrl]) {
            if ($aside === null || ! str_contains($aside, 'href="'.e($rowUrl).'"')) {
                $misses[$rowUrl] = $rowUrl;
            }
        }
    }

    /**
     * DOC-এর মেনুর প্রতিটা পাতা — রুট, প্যারামিটার, ঠিকানা।
     *
     * @return list<array{0: string, 1: array<string, mixed>, 2: string}>
     */
    private function menuPages(): array
    {
        $out = [];

        foreach (app(ModuleRegistry::class)->get('documents')->menu as $items) {
            foreach ($items as $item) {
                $this->assertTrue(Route::has($item['route']), "রুট নেই: {$item['route']}");

                $params = $item['route_params'] ?? [];
                $out[] = [$item['route'], $params, route($item['route'], $params)];
            }
        }

        return $out;
    }

    /** পাতার নিজের অংশ — শেল বাদে। */
    private function ownPart(string $html): ?string
    {
        $start = strpos($html, 'data-doc-page=');
        $end = strpos($html, 'data-doc-page-end');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($html, $start, $end - $start);
    }

    /** সাইডবারটুকু। */
    private function sidebar(string $html): ?string
    {
        $start = strpos($html, '<aside x-data="sidebarFilter"');

        if ($start === false) {
            return null;
        }

        $end = strpos($html, '</aside>', $start);

        return $end === false ? null : substr($html, $start, $end - $start);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private function flatten(array $values, string $prefix): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
