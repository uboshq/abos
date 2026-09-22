<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * কাগজটা প্রতিটা ফোনের চেয়েও সরু — আর সেটাই ছাপা ভাঙত।
 *
 * ── ⛔ যা কাগজে ছাপা হচ্ছিল, ২২ সেপ্টেম্বর ২০২৬ ──────────────────────
 * হেডলেস Chrome-এ সরবরাহকারীর খতিয়ান সত্যিই ছেপে, PDF-টা ছবি বানিয়ে
 * চোখে দেখে ধরা পড়েছে:
 *
 *   ⛔ পাঁচ কলামের ছকটা **সারি-প্রতি একটা খাড়া কার্ড** হয়ে ছাপত —
 *     প্রতিটা সারিতে "তারিখ · ডকুমেন্ট · বিবরণ · ডেবিট · ক্রেডিট ·
 *     ব্যালেন্স" লেবেলগুলো আবার লেখা, আর টাকার সংখ্যাগুলো আর এক
 *     খাড়া রেখায় নেই। ⓘ কাগজ লাগত তিনগুণ।
 *   ⛔ মোবাইলের নিচের নেভিগেশন বারটা **নিরেট নীল রঙে** ছাপা হত।
 *
 * ── ⓘ একটাই কারণ, আর সেটা মাপের ─────────────────────────────────────
 * ছক দুই চেহারায় আঁকা হয়: সরু পর্দায় card, আর `min-width: 768px`-এ
 * আসল টেবিল। ⚠️ কিন্তু ছাপার বাক্সটা Letter-এ ~৭৪০px, A4-তে আরো সরু —
 * **৭৬৮ পেরোয় না**। ⓘ অর্থাৎ ব্রাউজার কাগজটাকে একটা ছোট ফোন ভাবে।
 *
 * ⭐ তাই ছাপায় চেহারাটা প্রস্থ ধরে নয়, **ঠিক করে দেওয়া**।
 *
 * ── ⚠️ কেন কোনো পরীক্ষা এটা ধরত না ─────────────────────────────────
 * পর্দায় কিছুই ভাঙে না, HTML একই, প্রতিটা দাবি সবুজ। ⛔ জিনিসটা জানা
 * যায় **কেবল কাগজটা হাতে এলে** — আর মালিকের *"print e vul ase"*
 * অভিযোগটা মাসের পর মাস এই কারণেই অস্পষ্ট ছিল।
 */
final class ThePaperIsNarrowerThanEveryPhoneTest extends TestCase
{
    /**
     * ছাপার নিয়মগুলোয় যা থাকতেই হবে — টুকরো, আর কেন।
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function rules(): array
    {
        return [
            'নিচের নেভিগেশন বার' => [
                '.bottom-nav',
                'মোবাইলের নিচের বারটা `<nav class="bottom-nav fixed …">` — `footer.fixed` নিয়মটা ওটা ধরে না, তাই নিরেট নীল রঙে ছাপা হত',
            ],
            'সারি আবার সারি' => [
                'display: table-row !important',
                'নাহলে প্রতিটা সারি একটা খাড়া card হয়ে ছাপে, আর টাকার কলাম এক রেখায় থাকে না',
            ],
            'ঘর আবার ঘর' => [
                'display: table-cell !important',
                'কার্ডে ঘরগুলো flex, তাই ছকের কলাম বলে কিছু থাকত না',
            ],
            'লেবেল দুইবার নয়' => [
                'content: none !important',
                'কার্ডের লেবেলটা `td::before`-এ; ছকে হেডার আছে, তাই ওটা থাকলে প্রতিটা ঘরে নামটা দুইবার পড়ত',
            ],
        ];
    }

    #[DataProvider('rules')]
    public function test_the_print_rules_survive(string $needle, string $why): void
    {
        $this->assertStringContainsString($needle, $this->printBlock(), implode("\n", [
            "\n⛔ ছাপার নিয়ম থেকে «{$needle}» সরে গেছে।",
            '',
            $why,
            '',
            '⚠️ এটা পর্দায় কোথাও ভাঙবে না — জানা যাবে কেবল কেউ Ctrl+P',
            'চেপে কাগজটা হাতে নিলে।',
        ]));
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ── ⚠️ উপরের দাবিগুলো কীভাবে অন্ধ হতে পারত ─────────────────────
     * ⛔ `printBlock()` খালি ফিরলে প্রতিটা `assertStringContainsString`
     * লাল হত — সেদিক থেকে নিরাপদ। ⓘ কিন্তু উল্টোটা বিপজ্জনক: ব্লকটা
     * খোঁজার নিয়ম ভেঙে **গোটা ফাইলটা** ফিরলে সব দাবি সবুজ থাকত, আর
     * নিয়মগুলো ছাপার বাইরে চলে গেলেও কেউ টের পেত না।
     *
     * ⭐ তাই মাপা হয় ব্লকটা **সত্যিই ছোট** — গোটা স্টাইলশিটের এক
     * অংশমাত্র।
     */
    public function test_the_block_is_really_the_print_block(): void
    {
        $block = $this->printBlock();
        $whole = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString('aside', $block,
            'ছাপার ব্লকে পুরনো `aside` নিয়মটাই নেই — ব্লক খোঁজার নিয়মটা ভেঙেছে।');

        $this->assertLessThan(strlen($whole) / 2, strlen($block), implode("\n", [
            '⛔ "ছাপার ব্লক" বলে যা পাওয়া গেল সেটা গোটা স্টাইলশিটের অর্ধেকের বেশি।',
            '',
            '⚠️ ব্রেস মেলানোর নিয়মটা ভেঙেছে, আর তখন উপরের দাবিগুলো',
            'ফাইলের **যেকোনো** জায়গা থেকে টুকরোগুলো পেয়ে সবুজ থাকত —',
            'নিয়মগুলো ছাপার বাইরে সরে গেলেও।',
        ]));
    }

    /**
     * `@media print { … }`-এর ভিতরটা, ব্রেস গুনে।
     *
     * ⓘ regex দিয়ে নয়: ভিতরে নিজের ব্রেসওয়ালা নিয়ম আছে, আর `.*?`
     * প্রথম `}`-তেই থেমে যেত।
     */
    private function printBlock(): string
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $start = strpos($css, '@media print');

        $this->assertNotFalse($start, 'স্টাইলশিটে `@media print` ব্লকটাই নেই।');

        $open = strpos($css, '{', $start);
        $depth = 0;

        for ($i = $open; $i < strlen($css); $i++) {
            $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);

            if ($depth === 0) {
                return substr($css, $open, $i - $open + 1);
            }
        }

        return '';
    }
}
