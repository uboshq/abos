<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ভবিষ্যতের তারিখে কোনো দলিল নয়।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * ১ সেপ্টেম্বর ২০২৬-এর নিরীক্ষায় `trx_date`-এর প্রতিটা ভ্যালিডেশন নিয়ম
 * গোনা হলো — ২৯টা জায়গা, আর সবগুলোই ঠিক এইটুকু:
 *
 * ```
 * 'trx_date' => ['required', 'date'],
 * 'trx_date' => ['nullable', 'date'],
 * ```
 *
 * **`before_or_equal` ছিল শূন্য জায়গায়।** অর্থাৎ আগামী মাসের তারিখে
 * একটা বিল, একটা ভাউচার বা একটা মাল-চলাচল ঢুকিয়ে দেওয়া যেত।
 *
 * ── দামটা কেন বড় ─────────────────────────────────────────────────────
 * তালা পেছনের দিকটা পাহারা দিত ([[OpenPeriod]]), সামনের দিকটা কেউ নয়।
 * আর ভবিষ্যতের একটা সারি বসলে **"আজকের স্টক" আর "আজকের ব্যালেন্স"
 * দুইটাই ভুল হয়ে যায়** — সারিটা খাতায় আছে, অথচ ঘটনাটা এখনো ঘটেনি।
 * পেছনের ভুলটা রিপোর্ট বদলায়; সামনের ভুলটা **আজকের সংখ্যাই** বদলায়।
 *
 * ── কেন এই পাহারাটা ফাইল পড়ে ─────────────────────────────────────────
 * ২৯টা জায়গা ঠিক করা এক ঘণ্টার কাজ। কিন্তু আগামী সপ্তাহে লেখা
 * ত্রিশতম কন্ট্রোলারটা আবার `['required', 'date']` লিখবে, কারণ সেটাই
 * চারপাশে দেখা যায়। **নিয়মটা মনে রাখার উপর ছেড়ে দিলে ফিরে আসবে।**
 *
 * ── যা ইচ্ছে করে বাদ ──────────────────────────────────────────────────
 * `cheque_date` — ব্যাংকের চেক ভবিষ্যতের তারিখে লেখাই স্বাভাবিক, ওটার
 * পুরো ব্যবসাটাই তা-ই ([[Cheque]])। একইভাবে `due_on`, `expected_on`,
 * `deliver_on` — সেগুলো প্রতিশ্রুতি, ঘটনা নয়। পাহারাটা তাই কেবল
 * `trx_date` দেখে: যে ঘরটা বলে **কাজটা কবে ঘটেছে**।
 */
class NoDocumentIsDatedInTheFutureTest extends TestCase
{
    /**
     * প্রতিটা `trx_date` নিয়মে ভবিষ্যতের দরজাটা বন্ধ।
     */
    public function test_every_transaction_date_rule_closes_the_future(): void
    {
        $open = [];

        foreach ($this->sources() as $file) {
            $src = (string) file_get_contents($file);

            if (! preg_match_all("/'trx_date'\s*=>\s*\[([^\]]*)\]/", $src, $m)) {
                continue;
            }

            foreach ($m[1] as $rules) {
                /*
                 * `date` নিয়মটা নেই মানে ঘরটা তারিখ হিসেবে যাচাই হচ্ছে
                 * না — সেটা অন্য আকারের ব্যবহার (যেমন ইমপোর্টারের
                 * কলাম-ঘোষণা), আর ওখানে ভবিষ্যতের প্রশ্নই ওঠে না।
                 */
                if (! str_contains($rules, "'date'")) {
                    continue;
                }

                if (! str_contains($rules, 'before_or_equal')) {
                    $open[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($open)), implode("\n", [
            'এই জায়গাগুলোয় ভবিষ্যতের তারিখ এখনো নেওয়া হচ্ছে:',
            '',
            implode("\n", array_unique($open)),
            '',
            "প্রতিটায় `'before_or_equal:today'` যোগ করুন।",
        ]));
    }

    /**
     * নিয়মটা সত্যিই ফর্মে কাজ করে — কেবল ফাইলে লেখা নয়।
     *
     * উপরের পাহারাটা পাঠ্য পড়ে, আর পাঠ্য মিথ্যা বলতে পারে: কেউ
     * `before_or_equal:tomorrow` লিখলেও ওটা সবুজ থাকত। তাই এখানে
     * Laravel-এর ভ্যালিডেটরকে দিয়েই একবার চালিয়ে দেখা হয়।
     */
    public function test_the_rule_actually_refuses_tomorrow(): void
    {
        $validator = validator(
            ['trx_date' => now()->addDay()->toDateString()],
            ['trx_date' => ['required', 'date', 'before_or_equal:today']],
        );

        $this->assertTrue($validator->fails(), 'আগামীকালের তারিখ পাশ করে গেছে।');

        $today = validator(
            ['trx_date' => now()->toDateString()],
            ['trx_date' => ['required', 'date', 'before_or_equal:today']],
        );

        $this->assertFalse($today->fails(), 'আজকের তারিখই আটকে গেছে — সীমাটা এক দিন এগিয়ে।');
    }

    /** @return list<string> */
    private function sources(): array
    {
        $files = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }
}
