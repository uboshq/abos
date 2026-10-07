<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * তারিখ এক ছাঁদে, আর ঋণাত্মক অঙ্ক চোখে আলাদা।
 *
 * ── দুইটা আলাদা বাগ, একই স্বভাব ──────────────────────────────────────
 * দুইটাই দেখতে ছোট, দুইটাই ভুল সিদ্ধান্তে নিয়ে যায়, আর দুইটাই এমন
 * জায়গায় ছিল যেখানে চোখে পড়ে না।
 *
 * **এক ·** ফর্মের ঘর ছিল সাধারণ `<input type="date">`। ব্রাউজার ওটার
 * ভেতরের লেখাটা **নিজের লোকেল ধরে** আঁকে, আর সেটা বদলানোর কোনো API নেই।
 * en-US-এ ১৯ আগস্ট দেখাত `08/19/2026`, অথচ অ্যাপের বাকি সব জায়গায়
 * `17-08-2026`।
 *
 * `08/19` ভুল পড়ার সুযোগ নেই — ১৯ কোনো মাস নয়। কিন্তু **`05/06` পড়া
 * যায় দুইভাবে**: ৫ জুন, নাকি ৬ মে? দুইটাই বৈধ তারিখ, তাই ভুল ঘরে বসা
 * এন্ট্রিটা খাতা থেকে ধরাই যায় না।
 *
 * **দুই ·** গ্রাহক তালিকায় `-50,000.00` আর `13,550.00` একই নীল রঙে
 * বসত। প্রথমটা মানে ডিলার অগ্রিম দিয়ে রেখেছেন, দ্বিতীয়টা মানে তিনি
 * টাকা পাওনা রেখেছেন — উল্টো দুইটা কথা, একই চেহারায়। মালিক তালিকা
 * স্ক্যান করেন; প্রতিটা সংখ্যার আগে বিয়োগ চিহ্ন আছে কি না তা পড়েন না।
 *
 * ── কেন পরীক্ষাটা দরকার ─────────────────────────────────────────────
 * দুইটাই সারানো সহজ ছিল। **ফিরে না আসা** কঠিন: পরের যে কেউ তাড়াহুড়োয়
 * একটা `<input type="date">` লিখে ফেলতে পারেন — দেখতে কাজ করে, আর কেউ
 * ধরার আগেই আরও দশটা পর্দায় ছড়ায়। এই পরীক্ষা ঠিক ওই মুহূর্তে ভাঙে।
 */
class DatesAndNegativesReadTheSameEverywhereTest extends TestCase
{
    /**
     * কাঁচা `<input ... type="date">` — যেকোনো `type="date"` নয়।
     *
     * `<x-ui.field type="date">` লেখা ঠিক আছে; ওটা ভেতরে `x-ui.date`
     * ডাকে। শুধু লেখাটা মিলিয়ে ধরলে ৪২টা নির্দোষ ফর্মকে অপরাধী বলা হত।
     */
    private const RAW_DATE_BOX = '/<input[^>]*type="date"/';

    /**
     * ব্লেডের মন্তব্য বাদ দিয়ে যা সত্যিই ব্রাউজারে যায়।
     *
     * মন্তব্যে `<input type="date">` নামটা লেখা থাকতেই পারে — সেটাই তো
     * ব্যাখ্যা, কেন ওটা ব্যবহার করা হয় না। মন্তব্য না ছাঁটলে এই পরীক্ষা
     * নিজের ব্যাখ্যাটাকেই অপরাধ বলে ধরত।
     */
    private function markupOf(string $path): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/su', '', File::get($path));
    }

    /** @return list<string> */
    private function blades(): array
    {
        $out = [];

        foreach ([base_path('app'), resource_path('views')] as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $out[] = $file->getPathname();
                }
            }
        }

        return $out;
    }

    /**
     * কেউ আর কাঁচা `<input type="date">` লেখে না।
     *
     * একটাই ছাড়: `x-ui.date` নিজে। ওটার ভেতরে ব্রাউজারের পিকারটা লুকানো
     * থাকে — পিকারটা বাদ দেওয়ার কারণ নেই, কেবল ওর **লেখাটা** দেখানোর
     * দরকার নেই।
     */
    public function test_no_screen_uses_the_browsers_own_date_box(): void
    {
        /*
         * ছাড়টা মেলে পথের শেষ টুকরো ধরে, realpath() ধরে নয়।
         *
         * ── এই পরীক্ষাটা একবার কিছুই পাহারা দিচ্ছিল না ─────────────────
         * প্রথমে লেখা ছিল `realpath($file) === realpath($allowed)`।
         * পরীক্ষার প্রসেসে `realpath()` **দুই দিকেই `false`** ফেরাচ্ছিল,
         * আর `false === false` সত্য — অর্থাৎ ২৪৪টা ফাইলের **প্রতিটাই**
         * ছাড় পেয়ে যাচ্ছিল। পরীক্ষা সবুজ, অথচ ইচ্ছা করে একটা কাঁচা
         * `<input type="date">` বসিয়েও ধরা পড়েনি।
         *
         * ঠিক এই কারণেই পাহারা বসানোর পর ওটাকে **ভেঙে দেখতে হয়** —
         * সবুজ হওয়া আর কাজ করা এক জিনিস নয়।
         */
        $allowed = 'views/components/ui/date.blade.php';
        $offenders = [];

        foreach ($this->blades() as $file) {
            if (str_ends_with(str_replace(DIRECTORY_SEPARATOR, '/', $file), $allowed)) {
                continue;
            }

            /*
             * খোঁজা হয় কাঁচা `<input ... type="date">`, যেকোনো
             * `type="date"` নয়।
             *
             * `<x-ui.field type="date">` লেখা ঠিক আছে — ওটা ভেতরে
             * `x-ui.date` ডাকে। শুধু লেখাটা মিলিয়ে ধরলে ৪২টা নির্দোষ
             * ফর্মকে অপরাধী বলা হত, আর তখন পরীক্ষাটা বন্ধ করে দিতে হত।
             */
            if (preg_match(self::RAW_DATE_BOX, $this->markupOf($file)) === 1) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }
        $this->assertSame([], $offenders, implode("\n", [
            'এই পর্দাগুলো ব্রাউজারের নিজের তারিখের ঘর ব্যবহার করছে।',
            'ব্রাউজার ওটার লেখাটা নিজের লোকেল ধরে আঁকে — en-US-এ 05/06 মানে',
            '৬ মে, বাংলাদেশে ৫ জুন। দুইটাই বৈধ, তাই ভুলটা খাতা থেকে ধরা যায় না।',
            'বদলে <x-ui.date name="..." :value="..." /> লিখুন।',
            implode("\n", $offenders),
        ]));
    }

    /** তারিখের ঘরটা সত্যিই দিন-মাস-বছর দেখায়, আর ISO জমা দেয়। */
    public function test_the_date_box_shows_day_month_year_and_submits_iso(): void
    {
        $box = File::get(resource_path('views/components/ui/date.blade.php'));

        $this->assertStringContainsString('type="hidden"', $box,
            'সার্ভারে ISO যাওয়ার লুকানো ঘরটা নেই — তাহলে ব্যাকএন্ড কী পাবে?');
        $this->assertStringContainsString('abosDate', $box,
            'রূপান্তরের অঙ্কটা date.js-এ, ইনলাইন নয় — নাহলে ওটার পরীক্ষা লেখা যেত না।');

        $js = File::get(resource_path('js/date.js'));

        // ISO → দেখানোর ছাঁদ, আর উল্টোটা — দুইটাই থাকতে হবে
        $this->assertStringContainsString('export function toDisplay', $js);
        $this->assertStringContainsString('export function toIso', $js);

        /*
         * ৩১-০২-২০২৬ ছাঁদে ঠিক, কিন্তু এমন কোনো দিন নেই। Date নিজে ওটাকে
         * ৩ মার্চে গড়িয়ে দেয় — নীরবে। তাই দিনটা সত্যিই আছে কি না, সেটাও
         * যাচাই হয়।
         */
        $this->assertStringContainsString('getUTCDate()', $js,
            'তারিখটা সত্যিই আছে কি না যাচাই হচ্ছে না — ৩১ ফেব্রুয়ারি নীরবে ৩ মার্চ হয়ে যাবে।');
    }

    /**
     * ঋণাত্মক অঙ্ক চোখে আলাদা।
     *
     * টাকার প্রতিটা সংখ্যা `x-ui.amount` দিয়ে যায়, তাই নিয়মটা ওখানেই
     * একবার — আর নতুন পর্দাও প্রথম দিন থেকে ঠিক থাকে।
     */
    public function test_a_negative_figure_does_not_look_like_a_positive_one(): void
    {
        $amount = File::get(resource_path('views/components/ui/amount.blade.php'));

        $this->assertStringContainsString('$negative', $amount,
            'ঋণাত্মক কি না, সেটাই দেখা হচ্ছে না।');
        $this->assertMatchesRegularExpression('/\$negative\s*=>\s*[\'"]text-\(--color-danger\)/', $amount,
            'ঋণাত্মক অঙ্ক বিপদের রঙে বসার কথা।');

        /*
         * লিংক হলে ব্র্যান্ড-নীলটা লালকে চাপা দিত — আর ঠিক ওই সংখ্যাগুলোই
         * ক্লিকযোগ্য, অর্থাৎ যেগুলো মানুষ সবচেয়ে বেশি পড়েন।
         */
        $this->assertStringContainsString('! $negative', $amount,
            'লিংকের নীল রংটা ঋণাত্মকের লালকে চাপা দিচ্ছে।');
    }
}
