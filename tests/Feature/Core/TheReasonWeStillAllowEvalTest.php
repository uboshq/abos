<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Http\Middleware\ContentSecurityPolicy;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * কেন আমরা এখনো `eval` চলতে দিই — আর সেই কারণটা আজও সত্যি কি না।
 *
 * ── ⛔ নিরীক্ষার ফলাফল ৩.১, ১২ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"CSP থেকে `unsafe-inline` ও `unsafe-eval` তোলা — এটি এই পরিকল্পনার
 * সবচেয়ে কঠিন নিরাপত্তা-কাজ।"* ⓘ `unsafe-inline` চলে গেছে (nonce বসেছে)।
 * ⚠️ `unsafe-eval` রয়ে গেছে, কারণ Alpine `x-data`-র ভিতরের লেখাটা
 * মূল্যায়ন করে — আর সেটা `eval` ছাড়া হয় না।
 *
 * ── ⚠️ কারণটা লেখা ছিল, কিন্তু সংখ্যাটা পুরনো হয়ে গিয়েছিল ────────────
 * [[ContentSecurityPolicy]]-র মন্তব্যে হাতে লেখা ছিল **৭২টা** ব্লেড ফাইল।
 * ⓘ ১৮ সেপ্টেম্বর গুনে দেখা গেল **৮৬** — অর্থাৎ ঋণটা চোদ্দটা পর্দা বেড়েছে,
 * আর কেউ জানত না।
 *
 * ⛔ **একটা "মাপা কারণ" পুরনো হয়ে গেলে সেটা আর কারণ থাকে না — একটা
 * বিশ্বাস হয়ে যায়।** আর নিরাপত্তার সিদ্ধান্ত বিশ্বাসের উপর দাঁড়ালে সেটা
 * কেউ আর প্রশ্ন করে না, কারণ প্রশ্নের উত্তরটা তো "লেখাই আছে"।
 *
 * ── ⭐ তাই এই ফাইলটা লক্ষ্য মাপে না, ঋণ মাপে ──────────────────────────
 * সংখ্যাটা বাড়লেও লাল, কমলেও লাল। ⓘ বাড়লে জানা দরকার ঋণ বাড়ছে; কমলে
 * জানা দরকার শোধ হচ্ছে — আর শূন্য হলে `unsafe-eval` তুলে দেওয়ার দিন।
 */
final class TheReasonWeStillAllowEvalTest extends TestCase
{
    /**
     * ⭐ লেখা সংখ্যাটা আর গোনা সংখ্যাটা এক।
     */
    public function test_the_debt_we_wrote_down_is_the_debt_we_have(): void
    {
        $counted = count($this->screensUsingAlpine());

        $this->assertSame(
            ContentSecurityPolicy::SCREENS_USING_ALPINE,
            $counted,
            sprintf(
                "`unsafe-eval` রাখার কারণ হিসেবে লেখা আছে %d, আর সত্যিই আছে %d।\n\n".
                "⭐ দুইটার একটা করুন —\n".
                "  ১. ঋণ শোধ হয়ে থাকলে (`x-data` সরেছে) সংখ্যাটা কমিয়ে দিন\n".
                "  ২. নতুন পর্দা যোগ হয়ে থাকলে সংখ্যাটা বাড়িয়ে দিন, জেনে যে ঋণ বাড়ল\n\n".
                '⛔ শূন্য হলে `ContentSecurityPolicy`-র `script-src` থেকে `unsafe-eval` তুলে দিন।',
                ContentSecurityPolicy::SCREENS_USING_ALPINE,
                $counted,
            ),
        );
    }

    /**
     * ⓘ আর `unsafe-eval` সত্যিই তখনই থাকে যখন ঋণ আছে।
     *
     * ⚠️ ঋণ শূন্য হওয়ার পরেও কেউ লাইনটা তুলতে ভুলে গেলে সুরক্ষাটা অকারণে
     * বন্ধ থাকত — আর সেটা কেউ খেয়াল করত না, কারণ কিছুই ভাঙত না।
     */
    public function test_we_stop_allowing_eval_the_day_the_debt_is_paid(): void
    {
        /*
         * ⓘ `policy()` private, আর সেটাই ঠিক — কেবল পরীক্ষার জন্য একটা
         * পদ্ধতি public করা মানে অ্যাপের দরজা চওড়া করা।
         *
         * ⚠️ তাই প্রতিফলন। ⛔ আর নীতিটা **সত্যিই তৈরি করা হয়** — উৎস
         * ফাইলে `'unsafe-eval'` খুঁজে দেখলে একটা মন্তব্যেও মিলে যেত।
         */
        $method = new \ReflectionMethod(ContentSecurityPolicy::class, 'policy');
        $method->setAccessible(true);

        $policy = implode('; ', $method->invoke(app(ContentSecurityPolicy::class), 'test-nonce'));

        if (ContentSecurityPolicy::SCREENS_USING_ALPINE === 0) {
            $this->assertStringNotContainsString("'unsafe-eval'", $policy,
                'ঋণ শোধ হয়ে গেছে, তবু `unsafe-eval` রয়ে গেছে — সুরক্ষাটা অকারণে বন্ধ।');

            return;
        }

        $this->assertStringContainsString("'unsafe-eval'", $policy,
            'ঋণ আছে অথচ `unsafe-eval` নেই — Alpine-এর প্রতিটা পর্দা ভাঙার কথা।');
    }

    /**
     * ⓘ সেটআপের দাবি — স্ক্যানারটা সত্যিই ব্লেড খুঁজে পাচ্ছে তো?
     *
     * ⚠️ পথটা বদলালে গোনাটা শূন্য হত, আর উপরের দাবিটা তখন **ভুল কারণে**
     * লাল হত — বা ধ্রুবকটাও শূন্য হলে ভুল কারণে সবুজ।
     */
    public function test_the_scanner_actually_reads_blades(): void
    {
        $this->assertGreaterThan(200, $this->allBlades(),
            'ব্লেড ফাইল এত কম হতে পারে না — স্ক্যানারটাই কিছু খুঁজে পাচ্ছে না।');
    }

    /** @return list<string> */
    private function screensUsingAlpine(): array
    {
        $found = [];

        foreach ($this->blades() as $path) {
            if (str_contains((string) file_get_contents($path), 'x-data')) {
                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }

    private function allBlades(): int
    {
        return count(iterator_to_array($this->blades(), false));
    }

    /** @return \Generator<string> */
    private function blades(): \Generator
    {
        foreach ([base_path('app'), base_path('resources/views')] as $root) {
            /** @var iterable<\SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')) {
                    yield str_replace('\\', '/', $file->getPathname());
                }
            }
        }
    }
}
