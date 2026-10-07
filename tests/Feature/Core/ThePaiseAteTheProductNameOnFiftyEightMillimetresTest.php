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
        $body = (string) file_get_contents(
            resource_path('views/print/document-body.blade.php')
        );

        /*
         * ⛔ প্রথম খসড়ায় এই দাবিটা **ভুল ঘর গুনত** — ২২ সেপ্টেম্বর ২০২৬।
         *
         * পুরো ফাইল থেকে সব মাপ তুলে "সবচেয়ে বড় চারটা" নেওয়া হত। ⚠️ তাতে
         * মেটার লেবেল (১৭mm) আর মোটের ঘর (১৫mm) গুনে সারির কলাম বলে
         * চালানো হত — যোগফলটা ৬০mm আসত, অথচ সারির কলাম নেয় ৩৯mm।
         *
         * ⓘ এখন কেবল পণ্যের সারির `<thead>` পড়া হয়।
         */
        $head = $this->lineTableHead($body);

        // ⓘ থার্মালের মাপগুলো `$thermal ? 'Nmm' : ...` ছাঁচে লেখা
        preg_match_all("/\\\$thermal \\? '(\\d+)mm'/", $head, $hits);

        $widths = array_map('intval', $hits[1]);

        // ⚠️ কিছুই না পেলে নিচের যোগফল শূন্য, আর দাবিটা অর্থহীন সবুজ
        $this->assertGreaterThanOrEqual(4, count($widths), implode("\n", [
            'ছাপার টেমপ্লেটে থার্মালের কলাম-মাপ পাওয়া গেল না।',
            '',
            '⛔ ছাঁচটা বদলে থাকলে এই দাবিটা কিছুই না মেপে সবুজ থাকত।',
        ]));

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
            if (str_contains($head, 'core.print.qty')) {
                return $head;
            }
        }

        return '';
    }
}
