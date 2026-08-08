<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use Tests\TestCase;

/**
 * প্রতিটা __() চাবির সত্যিই একটা অনুবাদ আছে।
 *
 * ── কেন এই পাহারাটা লেখা হল ──────────────────────────────────────────
 * গুদামের ফর্মে লেখা ছিল `__('core.message.code_auto')`, অথচ চাবিটা
 * আছে `core.create.code_auto`-এ। `core.message` বলে কোনো গ্রুপই নেই।
 *
 * Laravel এই ভুলে কিছু বলে না — চাবিটাই ছাপিয়ে দেয়। তাই ব্যবহারকারী
 * পর্দায় "core.message.code_auto" পড়েন, আর ডেভেলপার কিছুই জানেন না।
 * ধরা পড়েছে সত্যিকারের ব্রাউজারে পর্দাটা খুলে।
 *
 * নীরব ভুলগুলো ঠিক এভাবেই বেঁচে থাকে: কোথাও ব্যতিক্রম নেই, লগে কিছু
 * নেই, স্ট্যাটাস কোড ২০০।
 */
class EveryTranslationKeyExistsTest extends TestCase
{
    public function test_no_view_asks_for_a_translation_that_does_not_exist(): void
    {
        $missing = [];

        foreach ($this->bladeFiles() as $file) {
            $source = file_get_contents($file) ?: '';

            /*
             * শুধু ধ্রুবক চাবিগুলো — `__('a.b.c')`।
             *
             * পরিবর্তনশীল চাবি (`__($spec['title'])`, `__('x.'.$k)`)
             * বাদ: ওগুলোর মান চলার সময় ঠিক হয়, তাই এখান থেকে যাচাই
             * করা যায় না — আর ভুল করে "নেই" বলা পাহারাটাকে অকেজো করে
             * দিত, কারণ তখন সবাই এটাকে উপেক্ষা করতে শিখত।
             */
            preg_match_all("/__\(\s*'([a-z0-9_:.\-]+)'\s*[,)]/i", $source, $matches);

            foreach ($matches[1] as $key) {
                // মডিউলের চাবি (inventory::field.x) নিজেদের ফাইলে;
                // ওগুলোও __() দিয়েই দেখা হয়, তাই একই নিয়ম খাটে
                if (! app('translator')->has($key)) {
                    $missing[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).'  →  '.$key;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)),
            "এই চাবিগুলোর কোনো অনুবাদ নেই। Laravel তখন চাবিটাই পর্দায়\n"
            ."ছাপিয়ে দেয় — কোনো ব্যতিক্রম নেই, লগে কিছু নেই, আর\n"
            .'ব্যবহারকারী "core.message.code_auto" জাতীয় লেখা পড়েন।');
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $files = [];

        foreach ([resource_path('views'), app_path('Modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
