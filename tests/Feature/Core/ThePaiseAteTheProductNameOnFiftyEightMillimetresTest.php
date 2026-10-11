<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Print\PaperSize;
use Tests\TestCase;

/**
 * পয়সা দুইটা অক্ষর খেত, আর সেটা পণ্যের নামের ঘর থেকেই।
 *
 * ── ⛔ মালিকের সিদ্ধান্ত, ২২ সেপ্টেম্বর ২০২৬ ────────────────────────
 * *"ok bad daw rounding kore nilei holo"* — থার্মাল রসিদে পয়সা বাদ।
 *
 * ── ⓘ কেন এটা সাজসজ্জা নয়, জায়গার হিসাব ────────────────────────────
 * ৫৮mm কাগজ, দুই পাশে ২mm মার্জিন → ব্যবহারযোগ্য **৫৪mm**।
 * ⛔ কলামগুলো নিত: # ৬ + পরিমাণ ১৩ + দর ১৭ + অঙ্ক ২১ = **৫৭mm**।
 *
 * ⚠️ অর্থাৎ যোগফলটাই কাগজের চেয়ে বড় ছিল, আর পণ্যের নামের জন্য
 * **ঋণাত্মক জায়গা** পড়ে থাকত। ⓘ মালিকের পুরনো অভিযোগ *"নাম কেটে
 * যায়"* ঠিক এই সংখ্যাটার ফল — রুচির ব্যাপার নয়।
 *
 * ── ⭐ কী হারায়, আর সেটা জেনেই বসানো ───────────────────────────────
 * রাউন্ড করা সারিগুলো যোগ করলে রাউন্ড করা মোটের সাথে এক-দুই টাকার
 * ফারাক হতে পারে। ⓘ খাতায় অঙ্কটা পয়সাসহ অক্ষত — এটা কেবল **ছাপার**
 * রূপ। ⚠️ A4-তে কিছুই বদলায় না, কারণ ওখানে জায়গার সমস্যা নেই।
 */
final class ThePaiseAteTheProductNameOnFiftyEightMillimetresTest extends TestCase
{
    /**
     * ⭐ থার্মালে পয়সা নেই, A4-তে আছে।
     */
    public function test_thermal_drops_the_paise_and_a4_keeps_them(): void
    {
        $a4 = PaperSize::of(PaperSize::A4);

        $this->assertSame(2, $a4->decimals(), 'A4-তে পয়সা থাকার কথা।');
        $this->assertSame('1,234.56', $a4->money('1,234.56'),
            'A4-র অঙ্ক বদলানো হচ্ছে — ওখানে জায়গার সমস্যা নেই।');

        foreach ([PaperSize::THERMAL_58, PaperSize::THERMAL_80] as $name) {
            $paper = PaperSize::of($name);

            $this->assertSame(0, $paper->decimals(), $name.'-এ পয়সা থাকার কথা নয়।');

            $this->assertSame('1,235', $paper->money('1,234.56'), implode("\n", [
                $name.'-এ পয়সা রয়ে গেছে।',
                '',
                'ⓘ মালিকের কথা: *"ok bad daw rounding kore nilei holo"*।',
            ]));

            $this->assertSame('1,234', $paper->money('1,234.49'),
                $name.'-এ রাউন্ডিং নিচের দিকে হচ্ছে না।');
        }
    }

    /**
     * ⛔ সংখ্যা নয় এমন ঘর ছোঁয়া হয় না।
     *
     * ── ⚠️ কেন আলাদা দাবি ───────────────────────────────────────────
     * ছাপার ঘরে "—" বা খালি লেখা বসে যখন কিছু দেখানোর নেই। ⓘ ওগুলো
     * সংখ্যা ভেবে রূপান্তর করলে রসিদে **"0"** ছাপা হত — আর শূন্য আর
     * "কিছু নেই" এক জিনিস নয়।
     */
    public function test_a_dash_is_not_turned_into_a_zero(): void
    {
        $paper = PaperSize::of(PaperSize::THERMAL_58);

        foreach (['—', '', 'N/A', '১,২৩৪'] as $notANumber) {
            $this->assertSame($notANumber, $paper->money($notANumber),
                '"'.$notANumber.'" সংখ্যা নয়, তবু বদলে দেওয়া হয়েছে।');
        }
    }

    /**
     * ⭐ কলামগুলো সত্যিই কাগজে ধরে — মেপে দেখা, চোখে নয়।
     *
     * ── ⛔ কেন এই দাবিটাই আসল ───────────────────────────────────────
     * পয়সা বাদ দেওয়াটা উদ্দেশ্য নয়, **উপায়**। ⓘ উদ্দেশ্য হলো পণ্যের
     * নামের ঘরে জায়গা ফেরানো। ⚠️ কেউ যদি পয়সা বাদ দিয়েও কলামের মাপ
     * পুরনো রেখে দেয়, মালিকের সমস্যাটা যেমন ছিল তেমনই থাকবে — আর
     * উপরের দাবিগুলো তবু সবুজ থাকত।
     *
     * ⓘ মাপগুলো পড়া হয় ছাপার টেমপ্লেট থেকেই, হাতে লেখা হয় না — নাহলে
     * টেমপ্লেট বদলালে এই সংখ্যাগুলো নীরবে পুরনো হয়ে যেত।
     */
    public function test_the_columns_actually_fit_on_the_paper(): void
    {
        /*
         * ⭐ মাপ পড়া হয় কাগজ যেখান থেকে নেয় সেখান থেকেই — [[PrintProfile::columnTable()]] আর
         * [[PrintProfile::columnsFor()]] (১০ অক্টোবর ২০২৬)।
         *
         * ⛔ আগে মাপগুলো টেমপ্লেটে `$thermal ? 'Nmm'` হয়ে লেখা ছিল, আর এই দাবি টেমপ্লেটের লেখা পড়ত। মাপগুলো এখন
         * ছকে (mPDF-এ মেপে বসানো), টেমপ্লেট কেবল `$col['thermal']` বসায় — পুরনো ছাঁচ আর মিলত না, দাবিটা লাল হল
         * ("কলাম-মাপ পাওয়া গেল না")। দাবি একই: সবচেয়ে ভরা ৫৮মিমি কাগজে (টাকা আর ফ্রি-সহ) নামের বাইরের কলামগুলো
         * কত নেয় — আর নামের জন্য অন্তত ১০মিমি থাকে কি না।
         */
        $table = \App\Core\Engines\Print\PrintProfile::columnTable();
        $cols = \App\Core\Engines\Print\PrintProfile::everything()
            ->columnsFor(PaperSize::of(PaperSize::THERMAL_58), true, true);

        $widths = [];
        foreach ($cols as $name) {
            if ($name !== 'name' && $table[$name]['thermal'] !== null) {
                $widths[] = (int) $table[$name]['thermal'];
            }
        }

        /*
         * ⚠️ কিছুই না পেলে নিচের যোগফল শূন্য, আর দাবিটা অর্থহীন সবুজ। ⓘ ৫৮মিমি-তে কলাম তিনটা ([[PaperSize::maxColumns()]],
         * `keep` ধরে: নাম, পরিমাণ, মোট) — তাই নামের বাইরের প্রতিটা কলামের নিজের থার্মাল মাপ থাকতেই হবে, আর অন্তত একটা।
         * ⓘ আগের "অন্তত চারটা" লেখা হয়েছিল যখন ৫৮মিমি-তে চারটা কলাম ছিল।
         */
        $this->assertNotEmpty($widths, implode("\n", [
            'ছাপার ছকে থার্মালের কলাম-মাপ পাওয়া গেল না।',
            '',
            '⛔ ছকটা বদলে থাকলে এই দাবিটা কিছুই না মেপে সবুজ থাকত।',
        ]));
        $this->assertCount(count($cols) - 1, $widths, '⛔ ৫৮মিমি-র কোনো কলামের নিজের মাপ নেই — সে কতটা নেবে কেউ জানে না।');
        $this->assertContains('name', $cols, '⛔ ৫৮মিমি কাগজে পণ্যের নামের কলামই নেই।');

        // ⓘ আর কাগজটা সত্যিই এই ছক থেকে মাপ নেয় — নইলে ছক মাপলে কাগজ মাপা হত না
        $head = $this->lineTableHead((string) file_get_contents(resource_path('views/print/document-body.blade.php')));
        $this->assertStringContainsString("width: {{ \$col[\$thermal ? 'thermal' : 'a4'] }}", $head,
            '⛔ পণ্যের সারির মাথা ছকের মাপ নেয় না — এই দাবি তখন কাগজ মাপে না।');

        $paper = PaperSize::of(PaperSize::THERMAL_58);

        $row = array_sum($widths);

        $usable = 58 - (2 * $paper->margin);

        /*
         * ⭐ নামের জন্য অন্তত ১০mm — একটা পণ্যের নাম চেনার জন্য
         * এটুকুই সর্বনিম্ন। ⓘ তার কমে "বার-বি-কিউ মিনি চানাচুর"
         * এক অক্ষরও পড়া যায় না।
         */
        $this->assertLessThanOrEqual($usable - 10, $row, implode("\n", [
            'থার্মালের কলামগুলো কাগজে ধরছে না।',
            '',
            'ⓘ ব্যবহারযোগ্য চওড়া: '.$usable.'mm',
            'ⓘ চারটা সংখ্যার কলাম নিচ্ছে: '.$row.'mm',
            'ⓘ পণ্যের নামের জন্য থাকছে: '.($usable - $row).'mm',
            '',
            '⛔ নামের ঘরে অন্তত ১০mm না থাকলে নাম কেটে যায় —',
            '   আর সেটাই মালিকের পুরনো অভিযোগ।',
        ]));
    }

    /**
     * পণ্যের সারির ছকের মাথাটুকুই।
     *
     * ⓘ ফাইলে একাধিক `<thead>` আছে — যেটায় `core.print.qty`
     * লেখা, সেটাই পণ্যের সারির।
     */
    private function lineTableHead(string $body): string
    {
        preg_match_all('/<thead.*?<\/thead>/s', $body, $heads);

        foreach ($heads[0] as $head) {
            // ⓘ কলামের নাম এখন `core.print.column.` থেকে ([[PrintProfile::columnsFor()]]); আগে `core.print.qty` হাতে লেখা ছিল
            if (str_contains($head, 'core.print.column.')) {
                return $head;
            }
        }

        return '';
    }
}
