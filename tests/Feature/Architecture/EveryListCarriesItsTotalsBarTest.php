<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * প্রতিটা তালিকার নিচে যোগফলের পট্টি — মালিক, ৫ অক্টোবর ২০২৬: বিক্রয় বিলের তালিকার নিচের
 * *"৩,০৯০টি সারি · এই পাতার মোট ৳… · আদায় ৳… · বাকি ৳…"* প্রতিটা তালিকায় চাই।
 *
 * ── কী দেখা হয় ─────────────────────────────────────────────────────────
 * পাতা ভাগ করা প্রতিটা পর্দা (`<x-ui.pager` বা `->links(`) [[x-ui.list-totals]] আঁকে, আর তাতে অন্তত সারির সংখ্যা
 * থাকে (`:rows=` বা `core.list.rows`) — নয়তো নিচের EXEMPT-এ কারণসহ নাম থাকে।
 *
 * ⚠️ মন্তব্যের ভেতরের ট্যাগ পট্টি নয় — Blade মন্তব্য কেটে তবেই খোঁজা।
 * ⚠️ EXEMPT কেবল সত্যি থাকে: নামটা আর তালিকা না হলে, বা পট্টি পেয়ে গেলে — লাল।
 * ⓘ সংখ্যাগুলো ঠিক কি না (গোটা ছাঁকনির, DB-র সরাসরি যোগের সমান) — মডিউলের নিজের পরীক্ষায়
 * ([[Tests\Feature\Modules\ListTotals]])।
 */
final class EveryListCarriesItsTotalsBarTest extends TestCase
{
    /** পাতা ভাগ আছে, কিন্তু পট্টি ইচ্ছাকৃতভাবে নেই — কারণসহ */
    private const EXEMPT = [
        // ⓘ গ্রাহকের পোর্টাল নিজের লেআউটে (`x-sales::portal.layout`), ABOS-এর খোলসে নয় — পট্টি আঁকে কেবল খোলসের
        // `listfoot` ([[chrome/navy]]); এখানে ঘোষণা করলে কোথাও আঁকা হত না, অর্থাৎ পাহারাটা মিথ্যা সবুজ হত
        'Sales/Resources/views/portal/do-index.blade.php' => 'গ্রাহকের পোর্টাল — নিজের লেআউট, পট্টি আঁকার খোলস নেই; প্রতিটা DO কার্ডে নিজের অঙ্ক',
        'Sales/Resources/views/portal/order-index.blade.php' => 'গ্রাহকের পোর্টাল — নিজের লেআউট, পট্টি আঁকার খোলস নেই; প্রতিটা আদেশ কার্ডে নিজের অঙ্ক',
    ];

    /**
     * পাশের সেশনের এখনো-কমিট-না-হওয়া নতুন তালিকা — পট্টি ওই সেশনই বসাবে (অন্যের ফাইলে হাত নয়)।
     *
     * ⚠️ কেবল ছোট হয়: পট্টি বসলে লাল হয়ে নাম কাটতে বলে। ⓘ ফাইলটা না থাকলে (পরিষ্কার চেকআউট) চুপ — ঠিক
     * [[EveryMoneyListShowsAGrandTotalTest]]-এর PENDING-এর মতো।
     */
    private const PENDING = [
        'Sales/Resources/views/price_book/show.blade.php' => 'দামের বইয়ের পাতা — pricing-এর চলতি কাজ (৫ অক্টোবর ২০২৬), কমিটের আগে `<x-ui.list-totals :rows="$items" />`',
    ];

    public function test_every_paginated_list_renders_the_totals_bar_or_is_exempt_with_a_reason(): void
    {
        $lists = $this->lists();

        $this->assertGreaterThanOrEqual(90, count($lists), '⛔ পাতা ভাগ করা তালিকাই প্রায় পাওয়া যায়নি — খোঁজটা অন্ধ।');

        $missing = [];

        foreach ($lists as $rel => $src) {
            $exempt = array_key_exists($rel, self::EXEMPT) || array_key_exists($rel, self::PENDING);
            $has = $this->hasBar($src);

            if (! $has && ! $exempt) {
                $missing[] = $rel;
            }

            $this->assertFalse($has && $exempt, "{$rel}-এ পট্টি বসেছে — EXEMPT/PENDING থেকে নামটা কাটুন।");
        }

        $this->assertSame([], $missing,
            "⛔ এই তালিকাগুলোর নিচে যোগফলের পট্টি নেই — `<x-ui.list-totals :rows=\"\$rows\" … />` দিন, "
            .'বা EXEMPT-এ কারণ লিখুন:'."\n".implode("\n", $missing));

        foreach (self::EXEMPT as $rel => $reason) {
            $this->assertNotSame('', trim($reason), "{$rel}: কারণ ছাড়া ছাড় নেই।");
            $this->assertArrayHasKey($rel, $lists, "EXEMPT-এ লেখা {$rel} আর পাতা ভাগ করা তালিকা নয় — নামটা কাটুন।");
        }

        foreach (self::PENDING as $rel => $reason) {
            if (is_file(app_path('Modules/'.$rel))) {
                $this->assertArrayHasKey($rel, $lists, "PENDING-এ লেখা {$rel} আর পাতা ভাগ করা তালিকা নয় — নামটা কাটুন।");
            }
        }
    }

    /** ⭐ বিপজ্জনক ইনপুট: আসল একটা তালিকা থেকে পট্টিটা তুলে নিলে জালটা ধরে কি না */
    public function test_the_net_catches_a_real_list_that_lost_its_bar(): void
    {
        $src = (string) file_get_contents(app_path('Modules/Sales/Resources/views/invoice/index.blade.php'));

        $this->assertTrue($this->looksLikeAList($src), '⛔ বিক্রয় বিলের তালিকা তালিকা বলে চেনা গেল না — জালটা অন্ধ।');
        $this->assertTrue($this->hasBar($src));

        $gone = (string) preg_replace('/<x-ui\.list-totals\b.*?\/>/s', '', $src);
        $this->assertFalse($this->hasBar($gone), '⛔ পট্টি তুলে নেওয়ার পরও জাল বলছে আছে।');

        // ⚠️ মন্তব্যে রাখা পট্টি পট্টি নয়
        $this->assertFalse($this->hasBar('{{-- <x-ui.list-totals :rows="$rows" /> --}}<x-ui.pager :rows="$rows" />'));
        // ⚠️ সারির সংখ্যা ছাড়া পট্টি অর্ধেক — মালিকের প্রথম ঘরটাই "১২৪টি সারি"
        $this->assertFalse($this->hasBar('<x-ui.list-totals :totals="[]" />'));
        $this->assertTrue($this->hasBar('<x-ui.list-totals :rows="$rows" />'));
        $this->assertTrue($this->looksLikeAList('<x-ui.pager :rows="$rows" />'));
        $this->assertTrue($this->looksLikeAList('{{ $rows->links() }}'));
    }

    /** @return array<string, string> পথ => উৎস */
    private function lists(): array
    {
        $found = [];

        foreach ([app_path('Modules') => '', resource_path('views') => ''] as $dir => $_) {
            $root = str_replace('\\', '/', $dir).'/';
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($files as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                // ⓘ কম্পোনেন্ট পর্দা নয় — পাতা ভাগের নিজের কম্পোনেন্টে `->links(` থাকে, অথচ সে কোনো তালিকা নয়
                if (str_contains(str_replace('\\', '/', $file->getPathname()), '/views/components/')) {
                    continue;
                }

                $src = (string) file_get_contents($file->getPathname());

                if ($this->looksLikeAList($src)) {
                    $found[substr(str_replace('\\', '/', $file->getPathname()), strlen($root))] = $src;
                }
            }
        }

        return $found;
    }

    private function looksLikeAList(string $src): bool
    {
        $code = $this->code($src);

        return str_contains($code, '<x-ui.pager') || str_contains($code, '->links(');
    }

    private function hasBar(string $src): bool
    {
        preg_match_all('/<x-ui\.list-totals\b(.*?)\/>/s', $this->code($src), $tags);

        foreach ($tags[1] as $attrs) {
            if (str_contains($attrs, ':rows=') || str_contains($attrs, 'core.list.rows')) {
                return true;
            }
        }

        return false;
    }

    private function code(string $src): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    }
}
