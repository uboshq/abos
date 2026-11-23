<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ⭐ ছাপা, প্রিন্ট ভিউ আর PDF সবসময় নতুন ট্যাবে — কাজের পাতার জায়গা কখনো নেয় না (মালিক, ২৩ নভেম্বর ২০২৬: কাউন্টারে বিল নিশ্চিতের পরে
 * ছাপা একই ট্যাবে খুলত, আর *"সব জায়গায় প্রতিটা প্রিন্ট, প্রিন্ট ভিউ বা PDF আলাদা নতুন ট্যাবে"*)।
 *
 * ⓘ প্রতিটা ব্লেডের প্রতিটা `<a>` আর `<x-ui.button>` যার ঠিকানা ছাপার দরজা (`*.print*` রুট, `export=print|pdf`, `pdf` ঠিকানা) —
 *   তাতে `target="_blank"` থাকতেই হবে। ⛔ ছাড়: ছাপার নিয়ন্ত্রণ/সারি/চেকের সেটআপের পাতা (কাজের পাতা, কাগজ নয়), আর কাউন্টারের
 *   "আগের বিল" খোঁজা (বিলের তালিকা)।
 * ⓘ কাউন্টার আর POS: সার্ভার কাজের পাতায় ফেরে (`print_url`), "নিশ্চিত করুন"-এ `data-opens-print` ([[print-tab.js]])।
 */
final class EveryPrintLinkOpensANewTabTest extends TestCase
{
    /** ছাপার দরজা: `*.print*` রুট, `export=print|pdf`, `pdf=1`, ছাপার ঠিকানার চলক */
    private const PAPER = <<<'RE'
        /\.print[a-z_.]*'|export' *=> *'(print|pdf)'|'pdf' *=> *1|exportUrl\('(print|pdf)'\)|\$printHref|\$pdfUrl|print_url|ReportPaper::href/
        RE;

    private const NOT_A_PAPER = '/print_control|print_queue|print\.setup|print\.log|cheque\.print|paper\.history|reprintUrl|month_end|wa\.me/';

    public function test_every_link_to_a_print_or_pdf_opens_a_new_tab(): void
    {
        $same = [];

        $files = Finder::create()->files()->name('*.blade.php')->in([base_path('resources/views'), base_path('app/Modules')]);
        foreach ($files as $file) {
            $source = $file->getContents();
            if (preg_match_all('/<(a|x-ui\.button)\b(?:->|=>|[^>])*?>/s', $source, $tags, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($tags[0] as [$tag, $at]) {
                if (preg_match('/:?href="([^"]*)"/s', $tag, $href) !== 1) {
                    continue;
                }
                $isPaper = preg_match(self::PAPER, $href[1]) === 1;
                if (! $isPaper || preg_match(self::NOT_A_PAPER, $href[1]) === 1) {
                    continue;
                }
                if (! str_contains($tag, 'target="_blank"')) {
                    $same[] = $file->getRelativePathname().':'.(substr_count(substr($source, 0, $at), "\n") + 1);
                }
            }
        }

        $this->assertSame([], $same, "⛔ এই ছাপার লিংক একই ট্যাবে খোলে — কাজের পাতা হারায়:\n".implode("\n", $same));
    }

    public function test_the_counters_hand_the_print_to_a_new_tab_and_stay(): void
    {
        $totals = (string) file_get_contents(base_path('app/Modules/Sales/Resources/views/direct/partials/totals.blade.php'));
        $this->assertMatchesRegularExpression('/x-ref="confirm"[^>]*data-opens-print/s', $totals, '⛔ কাউন্টারের "নিশ্চিত করুন" ছাপার ট্যাব খোলে না');
        $this->assertStringContainsString("<x-ui.print-ready :url=\"session('print_url')\"", $totals);

        $pos = (string) file_get_contents(base_path('app/Modules/Sales/Resources/views/pos/index.blade.php'));
        $this->assertStringContainsString('data-opens-print', $pos, '⛔ POS-এর "সম্পূর্ণ" ছাপার ট্যাব খোলে না');
        $this->assertStringContainsString("<x-ui.print-ready :url=\"session('print_url')\"", $pos);

        foreach (['DirectSaleController', 'PosController'] as $controller) {
            $code = (string) file_get_contents(base_path("app/Modules/Sales/Http/Controllers/{$controller}.php"));
            $this->assertDoesNotMatchRegularExpression("/redirect\(\)\s*->route\('sales\.print\./", $code, "⛔ {$controller}: বিক্রির পরে সোজা ছাপায় যায় — একই ট্যাবে");
        }

        $ready = view('components.ui.print-ready', ['url' => 'https://abos.test/sales/print/invoice/7?paper=80mm', 'attributes' => new \Illuminate\View\ComponentAttributeBag()])->render();
        $this->assertStringContainsString('target="_blank"', $ready);
        $this->assertStringContainsString('data-print-tab-url="https://abos.test/sales/print/invoice/7?paper=80mm"', $ready);
    }
}
