<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * ⛔ বিক্রয় বিলের বাকির সূত্র সাত জায়গায় হাতে লেখা ছিল — নোট যোগ হলে যেটা বাদ পড়ত, সেই পর্দা বিল আর খাতার সাথে দুই কথা বলত
 * (fe, ২৫ অক্টোবর ২০২৬; মিল-তালিকা দফা ৩৫)।
 *
 * ⭐ এখন একটাই সূত্র — [[SalesInvoice::dueSql()]] (আর একক বিলে [[SalesInvoice::dueAmount()]])। এই পাহারা `app/`-এর কোনো ফাইলে
 * withCollected()-এর বিয়োগের ঘর (দাবি আর নোটের যোগফল) হাতে লেখা দেখলেই লাল — সূত্রের মালিক ফাইলটা ছাড়া।
 * ⓘ কেবল ঐ দুই ঘর ধরা হয়: আদায়ের ঘর "কত শোধ" দেখাতেও লাগে (অর্ডার বইয়ের "আদায়" কলাম), তাই ওটা নিষেধ নয়।
 */
final class ABillOwesByOneFormulaTest extends TestCase
{
    /** সূত্রের একমাত্র মালিক */
    private const OWNER = 'app/Modules/Sales/Models/SalesInvoice.php';

    public function test_no_file_writes_a_bills_outstanding_by_hand(): void
    {
        // ⓘ ঘরের নাম জোড়া লাগিয়ে — এই ফাইল নিজে কখনো পাহারার চোখে না পড়ে
        $columns = ['claimed'.'_total', 'noted'.'_total'];
        $pattern = '/\b[a-z_]+\.('.implode('|', $columns).')\b/';
        $found = [];

        foreach ($this->phpFiles(base_path('app')) as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));

            if ($relative === self::OWNER) {
                continue;
            }

            foreach (file($path) ?: [] as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $found[] = $relative.':'.($number + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame([], $found, "\n⛔ বিলের বাকি হাতে লেখা — SalesInvoice::dueSql() ডাকুন, নইলে নোট বা দাবি বাদ পড়লে এই পর্দা বিল আর খাতার সাথে দুই কথা বলবে:\n  "
            .implode("\n  ", $found));
    }

    public function test_the_sweep_reads_the_owner_and_the_readers(): void
    {
        $owner = (string) file_get_contents(base_path(self::OWNER));
        $this->assertStringContainsString('public static function dueSql(', $owner, 'প্রস্তুতিটাই ভুল — সূত্রের মালিক নেই।');

        $readers = 0;
        foreach ($this->phpFiles(base_path('app')) as $path) {
            $readers += substr_count((string) file_get_contents($path), 'SalesInvoice::dueSql(');
        }

        // ⓘ ড্যাশবোর্ড, আদায়-বাকি, অর্ডার বই, ঋণের ঝুঁকি, গ্রাহকের ছাঁকনি ×২, টাকার পূর্বাভাস — অন্তত এতগুলো
        $this->assertGreaterThanOrEqual(7, $readers, 'প্রস্তুতিটাই ভুল — পাহারা পাঠকদের পড়ছে না, তাহলে খালি তালিকা কিছুই প্রমাণ করে না।');
    }

    /** @return iterable<string> */
    private function phpFiles(string $root): iterable
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                yield $file->getPathname();
            }
        }
    }
}
