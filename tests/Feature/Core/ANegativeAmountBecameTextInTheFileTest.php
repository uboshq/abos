<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\ListExport;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * ঋণাত্মক টাকা ফাইলে সংখ্যাই থাকে, লেখা নয় (ec, রিপোর্ট লাইন-নিরীক্ষা ⚠️৫, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে সূত্র-গার্ড প্রতিটা "-" দিয়ে শুরু ঘরের সামনে উদ্ধৃতি বসাত — লোকসানের পণ্যের "-500.00" Excel-এ খুলত "'-500.00" লেখা হয়ে,
 * কলামের যোগফল ভুল, আর CSV-তে উদ্ধৃতিটা দেখা যেত।
 *
 * দাবি: খালি ঋণাত্মক সংখ্যা ছোঁয়া হয় না; "-" এর পরে সূত্রের মতো কিছু, আর = + @ TAB — আগের মতোই আটকায়।
 */
final class ANegativeAmountBecameTextInTheFileTest extends TestCase
{
    private function captured(): ListExport
    {
        $export = new ListExport;
        $export->capture(
            [['key' => 'name', 'label' => 'Name', 'render' => null], ['key' => 'amount', 'label' => 'Amount', 'render' => null]],
            [
                ['name' => 'Loss line', 'amount' => '-500.00'],
                ['name' => 'Big loss', 'amount' => '-1,23,456.00'],
                ['name' => '-2+3', 'amount' => '-12.5%'],
                ['name' => '=SUM(A1)', 'amount' => '@x'],
            ],
            fn (array $row, array $col): string => $row[$col['key']],
        );

        return $export;
    }

    public function test_a_negative_amount_stays_a_number_and_formulas_stay_guarded(): void
    {
        $csv = (string) $this->captured()->csv();

        $this->assertStringContainsString("Loss line,-500.00\r\n", $csv, '⛔ ঋণাত্মক টাকা CSV-তে লেখা হয়ে গেল।');
        $this->assertStringContainsString('"-1,23,456.00"', $csv);
        $this->assertStringNotContainsString("'-500.00", $csv);
        $this->assertStringNotContainsString("'-1,23,456.00", $csv);
        $this->assertStringNotContainsString("'-12.5%", $csv);
        $this->assertStringContainsString("'-2+3", $csv, '⛔ "-" এর পরে সূত্র আর আটকায় না।');
        $this->assertStringContainsString("'=SUM(A1)", $csv);
        $this->assertStringContainsString("'@x", $csv);

        $path = tempnam(sys_get_temp_dir(), 'ec_neg_');
        file_put_contents($path, (string) $this->captured()->xlsx());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        // ⓘ xlsx-এর XML-এ "'" লেখা হয় `&apos;` — খোঁজাও সেভাবে, নইলে দুইটা যাচাইই অন্ধ
        $this->assertStringNotContainsString('&apos;-500.00', $sheet, '⛔ ঋণাত্মক টাকা xlsx-এ লেখা হয়ে গেল।');
        $this->assertStringContainsString('&apos;-2+3', $sheet, '⛔ "-" এর পরে সূত্র xlsx-এ আর আটকায় না।');
    }
}
