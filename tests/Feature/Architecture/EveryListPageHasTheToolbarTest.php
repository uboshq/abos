<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * প্রতিটা তালিকার পাতায় একই টুলবার — মালিক, ৩ অক্টোবর ২০২৬: *"sob jaygay toolbar dibe"*।
 *
 * ── কী দেখা হয় ─────────────────────────────────────────────────────────
 * মডিউলের প্রতিটা `index.blade.php` যা [[x-ui.table]] আঁকে, তাতে [[x-ui.toolbar]] থাকবে — খোঁজা, সাজানো, ছাঁকনি,
 * রপ্তানি, ছাপা, দৃশ্য এক জায়গায়, সব তালিকায় একই রকম। নয়তো নিচের দুই তালিকার একটায়:
 *   PENDING      এখনো বসেনি — কেবল ছোট হয়; টুলবার বসলে নাম কাটতে হয় (নইলে লাল), নতুন পাতা এখানে ঢোকে না
 *   NOT_A_LIST   পাতাটা তালিকা নয় — কারণসহ (পড়ে দেখা, অনুমান নয়)
 */
final class EveryListPageHasTheToolbarTest extends TestCase
{
    /** বসানো বাকি (৩ অক্টোবর ২০২৬) — বসলে নাম কাটা */
    private const PENDING = [
        'Accounts/Resources/views/custody/index.blade.php',
        'Accounts/Resources/views/inter-company/index.blade.php',
        'Accounts/Resources/views/note/index.blade.php',
        'Accounts/Resources/views/period/index.blade.php',
        'Approval/Resources/views/limit/index.blade.php',
        'Finance/Resources/views/account-analysis/index.blade.php',
        'Finance/Resources/views/bank-charge/index.blade.php',
        'Finance/Resources/views/budget/index.blade.php',
        'Finance/Resources/views/carrier-labour/index.blade.php',
        'Finance/Resources/views/institution/index.blade.php',
        'Finance/Resources/views/insurance/index.blade.php',
        'Finance/Resources/views/profit/index.blade.php',
        'Purchase/Resources/views/payment-schedule/index.blade.php',
        'Sales/Resources/views/price_list/index.blade.php',
        'Sales/Resources/views/route/index.blade.php',
        'Sales/Resources/views/shift/index.blade.php',
        'Sales/Resources/views/target/index.blade.php',
    ];

    /** তালিকা নয় — কারণসহ */
    private const NOT_A_LIST = [
        'Sales/Resources/views/overview/index.blade.php' => 'বিক্রয়ের ড্যাশবোর্ড — কার্ড, ধারা আর ভাগ; খোঁজার বা সাজানোর মতো সারি নেই',
        'Accounts/Resources/views/year-end/index.blade.php' => 'বছর সমাপনী — বছরে একবারের একটা কাজের পাতা, তালিকা নয়',
        'Accounts/Resources/views/integrity/index.blade.php' => 'খাতা মেলানোর যাচাই চালানোর পর্দা — যাচাইয়ের ফল, তালিকা নয়',
        'SystemAdmin/Resources/views/import/index.blade.php' => 'পুরনো খাতা থেকে আনা — দুই ধাপের আমদানি, তালিকা নয়',
        'Restaurant/Resources/views/kitchen/index.blade.php' => 'রান্নাঘরের সরাসরি বোর্ড — এখন কী বানানো যাবে, তালিকা নয়',
    ];

    public function test_every_list_page_has_the_toolbar_or_is_named_with_a_reason(): void
    {
        $pages = $this->tablePages();

        $this->assertGreaterThanOrEqual(40, count($pages), '⛔ টেবিলের পাতাই প্রায় পাওয়া যায়নি — খোঁজটা অন্ধ।');

        foreach ($pages as $rel => $src) {
            $excused = in_array($rel, self::PENDING, true) || array_key_exists($rel, self::NOT_A_LIST);
            $has = $this->hasToolbar($src);

            $this->assertFalse(! $has && ! $excused, "⛔ {$rel} তালিকা আঁকে, অথচ টুলবার নেই — <x-ui.toolbar> বসান।");
            $this->assertFalse($has && in_array($rel, self::PENDING, true), "{$rel}-এ টুলবার বসেছে — PENDING থেকে নামটা কাটুন।");
        }

        foreach ([...self::PENDING, ...array_keys(self::NOT_A_LIST)] as $rel) {
            if (is_file(app_path('Modules/'.$rel))) {
                $this->assertArrayHasKey($rel, $pages, "তালিকায় লেখা {$rel} আর টেবিল আঁকে না — নামটা কাটুন।");
            }
        }
    }

    public function test_the_net_sees_a_planted_page(): void
    {
        $bare = '<x-ui.table :rows="$rows" :columns="$columns" />';

        $this->assertFalse($this->hasToolbar($bare));
        $this->assertTrue($this->hasToolbar("<x-ui.toolbar :title=\"\$t\" />\n".$bare));
        // ⚠️ মন্তব্যে লেখা নাম টুলবার নয়
        $this->assertFalse($this->hasToolbar('{{-- <x-ui.toolbar> পরে --}}'.$bare));
        $this->assertFalse($this->hasToolbar('<x-ui.toolbar-lite />'.$bare), '⛔ অন্য নামের কম্পোনেন্টকে টুলবার ধরা হলো।');
    }

    /** @return array<string, string> মডিউলের পথ => উৎস */
    private function tablePages(): array
    {
        $found = [];
        $root = str_replace('\\', '/', app_path('Modules')).'/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules')));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getFilename() !== 'index.blade.php') {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            if (str_contains($this->code($src), '<x-ui.table')) {
                $found[substr(str_replace('\\', '/', $file->getPathname()), strlen($root))] = $src;
            }
        }

        return $found;
    }

    private function hasToolbar(string $src): bool
    {
        // ⓘ ঠিক এই কম্পোনেন্ট — নামের পরে ফাঁকা, `>` বা `/`; `<x-ui.toolbar-কিছু>` টুলবার নয়
        return preg_match('/<x-ui\.toolbar[\s>\/]/', $this->code($src)) === 1;
    }

    private function code(string $src): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    }
}
