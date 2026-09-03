<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\ListExport;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * রপ্তানির তিন ফরম্যাট — এক ধরা-টেবিল থেকে CSV · xlsx · JSON।
 *
 * ── কেন xlsx-এর আসল প্রমাণ "ফাইলটা খোলে" ────────────────────────────
 * হাতে লেখা Office-XML-এ একটা অমীমাংসিত `&` বা নিয়ন্ত্রণ-অক্ষর থাকলে
 * কোড ঠিকই চলে, কিন্তু Excel বলে "ফাইল নষ্ট"। তাই এখানে নিজের লেখা
 * escape নিজের পার্সার দিয়ে যাচাই করা হয় না — শীটটা **DOMDocument**-এ
 * পার্স করা হয় (স্বাধীন পার্সার), আর একটা `&`-ওয়ালা নাম ফেরত পড়া হয়।
 *
 * DB লাগে না — ধরা-টেবিল থেকে সরাসরি বাইট তৈরি হয়।
 */
class ListExportFormatsTest extends TestCase
{
    private function captured(): ListExport
    {
        $export = new ListExport;

        $columns = [
            ['key' => 'name', 'label' => 'Name', 'render' => null],
            ['key' => 'amount', 'label' => 'Amount', 'render' => null],
        ];

        $rows = [
            ['name' => 'R&D "5" & Co', 'amount' => '1,234.56'],   // & আর " — escape না হলে ফাইল ভাঙে
            ['name' => '=HYPERLINK("x")', 'amount' => '+7'],       // সূত্র-ইনজেকশন, দুই ঘরেই
            ['name' => "old\x03row", 'amount' => 'নেসলে'],         // অবৈধ নিয়ন্ত্রণ-অক্ষর + unicode
        ];

        $export->capture($columns, $rows, fn (array $row, array $col): string => $row[$col['key']]);

        return $export;
    }

    /** @return list<string> */
    private function sheetTexts(string $xlsx): array
    {
        $path = tempnam(sys_get_temp_dir(), 'test_xlsx_');
        file_put_contents($path, $xlsx);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'xlsx একটা বৈধ zip নয়।');
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertNotFalse($sheet, 'শীটের অংশটাই zip-এ নেই।');

        // ★ স্বাধীন পার্সার — নিজের escape নিজের পার্সারে নয়
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $ok = $dom->loadXML($sheet);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertTrue($ok, 'শীটের XML DOMDocument-এ পার্স হয়নি — ফাইলটা খুলবে না।');
        $this->assertSame([], $errors, 'শীটের XML-এ পার্স-ত্রুটি আছে।');

        $texts = [];
        foreach ($dom->getElementsByTagName('t') as $node) {
            $texts[] = $node->nodeValue;
        }

        return $texts;
    }

    public function test_xlsx_opens_and_an_ampersand_name_round_trips(): void
    {
        $texts = $this->sheetTexts((string) $this->captured()->xlsx());

        // & আর " হুবহু ফিরে আসে — escape সঠিক
        $this->assertContains('R&D "5" & Co', $texts);
        $this->assertContains('নেসলে', $texts, 'unicode হারিয়েছে।');
    }

    public function test_a_formula_cell_is_neutralised_in_xlsx(): void
    {
        $texts = $this->sheetTexts((string) $this->captured()->xlsx());

        $this->assertContains("'=HYPERLINK(\"x\")", $texts, 'xlsx-এ সূত্র-ইনজেকশন আটকানো হয়নি।');
        $this->assertContains("'+7", $texts);
    }

    public function test_a_formula_cell_is_neutralised_in_csv_too(): void
    {
        $csv = (string) $this->captured()->csv();

        $this->assertStringContainsString("'=HYPERLINK", $csv, 'CSV-এ সূত্র-ইনজেকশন আটকানো হয়নি।');
    }

    public function test_control_characters_are_stripped_so_the_file_opens(): void
    {
        $texts = $this->sheetTexts((string) $this->captured()->xlsx());

        // \x03 বাদ পড়ে "oldrow" হয় — থাকলে DOM পার্সই ভাঙত (উপরে ধরা পড়ত)
        $this->assertContains('oldrow', $texts);
        foreach ($texts as $t) {
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x08]/', $t);
        }
    }

    public function test_json_carries_columns_and_keyed_rows(): void
    {
        $json = json_decode((string) $this->captured()->json(), true);

        $this->assertSame(['name', 'amount'], array_column($json['columns'], 'key'));
        $this->assertCount(3, $json['rows']);
        $this->assertSame('R&D "5" & Co', $json['rows'][0]['name']);
        $this->assertSame(3, $json['total']);
    }

    public function test_csv_still_carries_the_header_and_rows(): void
    {
        $csv = (string) $this->captured()->csv();

        $this->assertStringContainsString('Name', $csv);
        $this->assertStringContainsString('নেসলে', $csv);
    }
}
