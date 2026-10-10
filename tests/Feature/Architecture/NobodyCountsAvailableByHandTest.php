<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * ⛔ "বিক্রয়যোগ্য" হাতে গোনা যায় না — একটাই সূত্র, [[StockService::availableSql()]] (পুরো-ERP অডিট, মজুদ M27; fe, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ সূত্রটা (তাকে − ধরা − আটকানো) ১৪ জায়গায় হাতে লেখা ছিল, তাই মেয়াদ পেরোনো লট বাদ দিতে চৌদ্দবার বদলাতে হল, আর একটা বাদ
 * পড়লে পর্দা আর কাউন্টার দুই সংখ্যা বলত। এই পাহারা পনেরোতম হাতে-লেখা দেখলেই লাল হয়।
 *
 * ⓘ ছাড় কেবল StockService নিজে — সূত্রের ঘর, আর দুই তালা-গোনা যা "তাকের বেশি বেরোল কি না" দেখে, বেচার যোগ্যতা নয়।
 */
final class NobodyCountsAvailableByHandTest extends TestCase
{
    /** হাতে লেখা "বিক্রয়যোগ্য"-এর চেনা চেহারা — SQL-এ সারি ধরে, SQL-এ যোগফল ধরে, আর PHP-তে যোগফল থেকে */
    private const SHAPES = [
        '/floor_change\s*-\s*(\w+\.)?reserved_change/',
        '/SUM\(\s*(\w+\.)?floor_change\s*\)\s*-\s*SUM\(\s*(\w+\.)?reserved_change/',
        '/floor_total\s*-\s*(\w+\.)?reserved_total/',
        '/floor_total\s*,\s*\(string\)\s*\$\w+->reserved_total/',
        '/bcsub\(\s*\$floor\s*,\s*\$reserved/',
    ];

    private const ALLOWED = [
        'app/Modules/Inventory/Services/StockService.php',
    ];

    public function test_the_guard_knows_every_old_shape(): void
    {
        // ⛔ বিপদের নমুনা খাওয়ানো — ১৪ জায়গার পুরনো লেখাগুলো থেকে প্রতিটা চেহারা একটা করে
        $old = [
            "selectRaw('SUM(m.floor_change - m.reserved_change - m.hold_change) as available')",
            "DB::raw('SUM(m.floor_change) - SUM(m.reserved_change) - SUM(m.hold_change) as available')",
            "'available' => 't.floor_total - t.reserved_total - t.hold_total'",
            "bcsub(bcsub((string) \$row->floor_total, (string) \$row->reserved_total, 4), (string) \$row->hold_total, 4)",
            "'available' => bcsub(bcsub(\$floor, \$reserved, 4), \$hold, 4)",
        ];

        foreach ($old as $i => $line) {
            $this->assertTrue($this->handCounted($line), "⛔ পাহারা পুরনো চেহারা চিনল না: {$line}");
        }

        // ⓘ ফ্রি মালের হিসাব আলাদা ভাণ্ডার — ওটা ধরা চলবে না
        $this->assertFalse($this->handCounted("'free_available' => 't.free_total - t.free_reserved_total'"));
    }

    public function test_no_file_counts_available_by_hand(): void
    {
        $root = str_replace('\\', '/', base_path()).'/';
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $path = substr(str_replace('\\', '/', $file->getPathname()), strlen($root));

            if (! in_array($path, self::ALLOWED, true) && $this->handCounted((string) file_get_contents($file->getPathname()))) {
                $found[] = $path;
            }
        }

        $this->assertSame([], $found, '⛔ এই ফাইলগুলো "বিক্রয়যোগ্য" হাতে গোনে — StockService::availableSql() ডাকুন, '
            .'নাহলে মেয়াদ পেরোনো লট আবার বেচার মাল হয়ে দেখাবে।');
    }

    private function handCounted(string $text): bool
    {
        foreach (self::SHAPES as $shape) {
            if (preg_match($shape, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
