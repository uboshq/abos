<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Services\FormBranchPin;
use App\Http\Middleware\KeepsAFormInItsOwnBranch;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ⛔ নতুন কাগজের প্রতিটা ফর্ম খোলার শাখার ছাপ বয়ে আনে — RCV-0143 / মালিক: কোনোভাবেই অন্য শাখায় নয় (ec, ১৯ অক্টোবর ২০২৬)।
 *
 * ── কী ভেঙেছিল ───────────────────────────────────────────────────────
 * ফর্ম মনে রাখত না কোন শাখায় খোলা; জমার সময় উপরের শাখা যা বলত সেবা তা-ই নিত। অন্য ট্যাবে শাখা বদলালে কাগজ অন্য শাখায়।
 *
 * ── ⭐ এই ফাইলটা কেন ──────────────────────────────────────────────────
 * সারাইটা কেন্দ্রীয় — `@csrf` ছাপটা বসায় ([[FormBranchPin]]), আর পাহারাটা ওয়েবের দলে বসে ([[KeepsAFormInItsOwnBranch]])।
 * ⚠️ কেন্দ্রীয় সারাইয়ের দাম নীরবতা: override সরলে, পাহারার লাইন মুছলে বা বোতামের জায়গা খোলস থেকে হারালে কোনো পর্দা
 * ভাঙে না — কেবল কাগজ আবার অন্য শাখায় যায়। ⓘ তাই চারটা টুকরো, আর **সংখ্যাটা গোনা হয়** যাতে শূন্য পেয়ে সবুজ না থাকে
 * ([[EveryFormCarriesItsOwnTokenTest]]-এর একই শিক্ষা)।
 */
final class EveryNewPaperFormCarriesItsBranchTest extends TestCase
{
    /** ⭐ ১৯ অক্টোবর মেপে পাওয়া ৯০টা ফর্ম (`action="{{ route('….store'`)। সীমা ৮০ — কয়েকটা পর্দা মুছে যেতে পারে। */
    private const FEWEST_FORMS = 80;

    public function test_the_csrf_directive_stamps_the_branch(): void
    {
        $this->assertStringContainsString(FormBranchPin::class, Blade::compileString('@csrf'),
            '⛔ `@csrf` আর শাখার ছাপ বসাচ্ছে না — override-টা `AppServiceProvider::boot()`-এ।');

        $html = app(FormBranchPin::class)->field();
        $this->assertStringContainsString('name="'.FormBranchPin::FIELD.'"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
    }

    public function test_the_guard_stands_in_the_web_group(): void
    {
        $this->assertContains(KeepsAFormInItsOwnBranch::class, app('router')->getMiddlewareGroups()['web'] ?? [],
            '⛔ পাহারার ক্লাস আছে, কিন্তু ওয়েবের দলে বসানো নেই — ছাপ মেলানোই হয় না।');
        $this->assertNotContains(KeepsAFormInItsOwnBranch::class, app('router')->getMiddlewareGroups()['api'] ?? [],
            '⛔ ফোনের দরজায় ছাপ নেই — সেখানে পাহারা বসলে প্রতিটা লেখা থামত।');
    }

    public function test_the_button_has_its_place_in_the_shell(): void
    {
        $layout = (string) file_get_contents(resource_path('views/components/layouts/app.blade.php'));

        // ⓘ দুইবার — পুরো পাতা আর পপআপ (পিক), দুই শাখাতেই ফর্ম ফেরত আসে
        $this->assertSame(2, substr_count($layout, '<x-shell.branch-pin />'), '⛔ থামার কারণ আর "X-এই জমা দিন" বোতাম খোলসে নেই।');
    }

    public function test_every_form_that_creates_a_paper_carries_the_csrf_anchor(): void
    {
        $naked = [];
        $counted = 0;

        foreach ($this->bladeFiles() as $path => $source) {
            foreach ($this->storeForms($source) as $form) {
                $counted++;

                if (! str_contains($form, '@csrf')) {
                    $naked[] = $path;
                }
            }
        }

        $this->assertGreaterThanOrEqual(self::FEWEST_FORMS, $counted,
            "⛔ নতুন কাগজের ফর্ম পাওয়া গেল মাত্র {$counted}টা — খোঁজার ছাঁচ ভেঙেছে, পর্দা কমেনি।");

        $naked = array_values(array_unique($naked));
        sort($naked);

        $this->assertSame([], $naked, implode("\n", array_merge(
            ['⛔ এই পর্দাগুলোর নতুন-কাগজের ফর্মে `@csrf` নেই — তাই শাখার ছাপও নেই:', ''],
            $naked,
        )));
    }

    /**
     * একটা ফাইলের যে POST ফর্মগুলো `*.store`-এ যায় (খোলা ট্যাগের `action="{{ route('….store'`)।
     *
     * ⚠️ `->` ট্যাগের ভিতরে থাকতে পারে (`$model->id`), তাই খোলা ট্যাগ `>` দেখে কাটা হয় না।
     *
     * @return list<string>
     */
    private function storeForms(string $source): array
    {
        $forms = [];
        $offset = 0;

        while (($open = strpos($source, '<form', $offset)) !== false) {
            $close = strpos($source, '</form>', $open);
            $offset = $open + 5;

            if ($close === false) {
                continue;
            }

            $form = substr($source, $open, $close - $open);

            if (stripos($form, 'method="POST"') === false) {
                continue;
            }

            if (preg_match('/^<form\b(?:[^>]|->)*?\baction\s*=\s*"\{\{\s*route\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]/s', $form, $m) === 1
                && str_ends_with($m[1], '.store')) {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    /** @return array<string, string> */
    private function bladeFiles(): array
    {
        $files = [];

        foreach ([resource_path('views'), app_path('Modules')] as $root) {
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($walk as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        ksort($files);

        return $files;
    }
}
