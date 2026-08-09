<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use Tests\TestCase;

/**
 * Alpine-এর হ্যান্ডলার কেবল x-data-র ভেতরেই চলে।
 *
 * ── যে ভুলটা এই পাহারা ধরে ───────────────────────────────────────────
 * ফেরতের দুইটা পর্দায় ইনভয়েস/বিলের ড্রপডাউনে `@change` লেখা ছিল, আর
 * তার কাজ ছিল ওই কাগজের লাইনগুলো নিয়ে পাতাটা আবার খোলা। কিন্তু Alpine
 * কোনো অ্যাট্রিবিউট পড়ে না যতক্ষণ না তার উপরে কোথাও `x-data` থাকে —
 * আর ওই দুইটা ফর্মে সেটা ছিল না।
 *
 * ফল: বাছাই করলে কিচ্ছু হত না। কনসোলে এরর নেই, পর্দা দেখতে নিখুঁত,
 * শুধু দর হাতে টাইপ করতে হত — অথচ পর্দার নিজের লেখা বলত "দর বিলের দর
 * থেকেই আসে"। পরীক্ষক দুইবার এটা ধরেছেন, দুইবারই "অটো-ফিল হয় না" বলে।
 *
 * ── কেন এটা টেস্টে ধরা কঠিন ──────────────────────────────────────────
 * সার্ভার ঠিকই কাজ করে: ঠিকানায় `?sales_invoice_id=` দিলে লাইনগুলো
 * ভরে আসে। ভাঙাটা কেবল ব্রাউজারে, আর কেবল ওই একটা ক্লিকে। তাই
 * সোর্স থেকেই ধরতে হয়।
 */
class AlpineHandlersHaveAScopeTest extends TestCase
{
    /**
     * Alpine যেসব উপসর্গ চেনে — সবগুলোরই x-data লাগে।
     *
     * @var list<string>
     */
    private const ALPINE_MARKERS = [
        'x-on:', 'x-model', 'x-show', 'x-text', 'x-html', 'x-if', 'x-for',
        'x-bind:', 'x-transition', 'x-ref', 'x-init', 'x-cloak', 'x-effect',
    ];

    public function test_no_blade_file_uses_an_alpine_handler_without_x_data(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = file_get_contents($file);

            if ($source === false || str_contains($source, 'x-data')) {
                continue;
            }

            /*
             * `@click` জাতীয় সংক্ষিপ্ত রূপ আর `x-` উপসর্গ — দুইটাই।
             *
             * `@change`-এর মতো লেখা Blade-এর নির্দেশ নয় বলে হুবহু
             * HTML-এ চলে যায়, আর Alpine ছাড়া কেউ ওটা পড়ে না।
             */
            $found = [];

            if (preg_match('/\s@(click|change|input|submit|keydown|keyup|blur|focus)[.=]/', $source, $m)) {
                $found[] = '@'.$m[1];
            }

            foreach (self::ALPINE_MARKERS as $marker) {
                if (str_contains($source, ' '.$marker)) {
                    $found[] = $marker;
                }
            }

            if ($found !== []) {
                $offenders[] = $this->relative($file).' → '.implode(', ', array_unique($found));
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এই ফাইলগুলোয় Alpine-এর হ্যান্ডলার আছে কিন্তু x-data নেই।',
            'Alpine ওগুলো পড়বেই না — কোনো এরর ছাড়াই কিছুই ঘটবে না।',
            'হয় একটা x-data মোড়ক দিন, নয় সাধারণ onclick/onchange লিখুন।',
        ]));
    }

    /**
     * `$el` দিয়ে অন্য ঘর খোঁজা — তিনবার একই ভুল।
     *
     * ── কেন এটা প্রতিবারই নীরবে মারে ────────────────────────────────
     * Alpine-এ `$el` মানে **যে এলিমেন্ট থেকে অভিব্যক্তিটা চলছে**। একটা
     * `@input="recount()"` হ্যান্ডলারের ভেতরে সেটা ওই input, আর
     * `@click`-এর ভেতরে ওই বোতাম। কেউই নিজের ভেতরে অন্য ঘর রাখে না,
     * তাই querySelectorAll সবসময় খালি ফেরে — যোগফল ০ থাকে, বোতাম
     * নিষ্ক্রিয় থাকে, আর কনসোলে একটা অক্ষরও ওঠে না।
     *
     * এভাবেই তিনটা জায়গা মরেছিল: নগদ গণনা (সেভই হত না), জাবেদা
     * ভাউচার (সেভই হত না), আর সরাসরি বিক্রয়ের focusField। কম্পোনেন্টের
     * গোড়া চাইলে `$root`।
     */
    public function test_no_component_hunts_for_other_fields_through_el(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                continue;
            }

            // মন্তব্যে লেখা থাকতে পারে (ঠিক কী ভুল হয়েছিল তার ব্যাখ্যায়),
            // তাই কেবল সত্যিকারের ডাকগুলোই দেখা হয়: this.$el.querySelector
            if (preg_match('/(?<![\w.])this->\$el|this\.\$el\.querySelector/', $source)) {
                foreach (explode("\n", $source) as $no => $line) {
                    if (str_contains($line, 'this.$el.querySelector')
                        && ! str_starts_with(ltrim($line), '*')
                        && ! str_contains($line, '//')) {
                        $offenders[] = $this->relative($file).':'.($no + 1);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এখানে this.$el দিয়ে অন্য ঘর খোঁজা হচ্ছে।',
            'হ্যান্ডলারের ভেতরে $el হলো যে ঘরে ঘটনাটা ঘটেছে সেটাই —',
            'তার ভেতরে অন্য ঘর থাকে না, তাই তালিকা সবসময় খালি আসবে।',
            'কম্পোনেন্টের গোড়া চাইলে $root ব্যবহার করুন।',
        ]));
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $files = [];

        foreach ([resource_path('views'), app_path('Modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $walker = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($walker as $entry) {
                if ($entry->isFile() && str_ends_with($entry->getFilename(), '.blade.php')) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        $this->assertNotEmpty($files, 'No Blade files were checked — the walk is wrong.');

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
