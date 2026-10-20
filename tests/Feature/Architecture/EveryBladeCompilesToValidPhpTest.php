<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use ParseError;
use Tests\TestCase;

/**
 * ⛔ প্রতিটা Blade ফাইল কম্পাইল হয়ে বৈধ PHP হয় — ২০ অক্টোবর ২০২৬।
 *
 * ── কী ঘটেছিল ───────────────────────────────────────────────────────────
 * টুলবারে এক-লাইনের `@php(...)` বসানো হয়েছিল, আর একই ফাইলে নিচে একটা পুরো `@php … @endphp` ব্লক ছিল। Blade আগে সব
 * "php … endphp" জোড়া তুলে রাখে — তার খোঁজা শুরু হল এক-লাইনেরটা থেকে, আর শেষ হল পরের endphp-তে: মাঝের সব `@if` গিলে
 * ফেলল। ফল: কম্পাইল করা ফাইলে endif ছাড়া endforeach, আর টুলবারওয়ালা **প্রতিটা** পাতা ৫০০ (রিপোর্ট, তালিকা, খতিয়ান)।
 * ⓘ ধরা পড়েছিল কেবল ভাগ্যে — যে টেস্টগুলো ঐ পাতা খোলে সেগুলো লাল হওয়ায় (cbws2-এর Mac দৌড়ে ২৭টা F)।
 *
 * ⭐ দাবি: `resources/views` আর `app/Modules/*\/Resources/views`-এর প্রতিটা `.blade.php` কম্পাইল করে
 * `token_get_all(..., TOKEN_PARSE)` — বাক্য-গঠনের ভুল থাকলে ParseError। ⓘ পাতা খোলে না, ডেটাবেস ছোঁয় না।
 */
final class EveryBladeCompilesToValidPhpTest extends TestCase
{
    public function test_every_blade_file_compiles_to_php_that_parses(): void
    {
        $files = array_merge(
            File::allFiles(resource_path('views')),
            ...array_map(fn (string $dir) => File::allFiles($dir), File::glob(app_path('Modules/*/Resources/views'))),
        );

        $broken = [];
        $seen = 0;

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $seen++;

            try {
                token_get_all(Blade::compileString(File::get($file->getPathname())), TOKEN_PARSE);
            } catch (ParseError $error) {
                $broken[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' — '.$error->getMessage();
            }
        }

        $this->assertGreaterThan(300, $seen, 'প্রস্তুতিটাই ভুল — খুব কম Blade ফাইল পড়া হয়েছে, পাহারাটা অন্ধ।');
        $this->assertSame([], $broken, "⛔ এই Blade ফাইলগুলো কম্পাইল হয়ে বৈধ PHP হয় না — পাতা খুললেই ৫০০:\n".implode("\n", $broken));
    }

    /** ⓘ পাহারাটা নিজে দেখে কি না — ঠিক সেই ভুলটা সামনে দিলে ধরা পড়ে */
    public function test_the_sweep_catches_the_inline_php_that_swallows_the_rest(): void
    {
        $bad = "@foreach (\$rows as \$r)\n@if (\$r)\n@php(\$x = f())\n@endif\n@endforeach\n@php\n\$y = 1;\n@endphp\n";

        $this->expectException(ParseError::class);
        token_get_all(Blade::compileString($bad), TOKEN_PARSE);
    }
}
