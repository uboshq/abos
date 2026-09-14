<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Support\Csp;
use App\Http\Middleware\ContentSecurityPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * প্রতিটা ইনলাইন ব্লক তার ছাড়পত্র নিয়ে চলে — নাহলে চলেই না।
 *
 * ── কী বদলালো, ১৪ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
 * CSP থেকে `'unsafe-inline'` তুলে nonce বসানো হয়েছে। ⭐ লাভটা বড়: কেউ
 * একটা ঘরে `<script>` লিখে জমা দিলে, আর সেটা কোথাও এস্কেপ না হয়ে ছাপা
 * হলে, ব্রাউজার আর সেটা চালাবে না।
 *
 * ── ⚠️ কিন্তু দামটা হলো ভুলে যাওয়ার সুযোগ ────────────────────────────
 * নতুন কোনো পর্দায় কেউ `<script>` লিখলে আর `@nonce` না দিলে, ব্লকটা
 * **চুপচাপ চলবে না**। পাতা ২০০ দেবে, ব্লেড কম্পাইল হবে, কিছুই ছুঁড়বে না
 * — কেবল বোতামটা কাজ করবে না। ⓘ আজকের চেনা আকৃতি, ঠিক যেমন নিজেকে
 * শেষ করে দেওয়া মন্তব্যটা ([[ACommentThatEndsItselfPrintsTheRestTest]]):
 * লক্ষণ কেবল চোখে।
 *
 * ⭐ তাই সংখ্যাটা গোনা হয়, আর নীতিটাও আলাদা করে মাপা হয় — কারণ দুইটার
 * যেকোনো একটা খসে পড়লেই সুরক্ষাটা নীরবে শূন্য।
 */
final class EveryInlineScriptCarriesItsNonceTest extends TestCase
{
    /**
     * ⛔ কোনো ইনলাইন `<script>` বা `<style>` চিহ্ন ছাড়া নেই।
     */
    public function test_no_inline_block_is_missing_its_nonce(): void
    {
        $offenders = [];
        $blocks = 0;
        $files = 0;

        foreach ($this->bladeFiles() as $path) {
            $files++;
            $this->scan($path, $offenders, $blocks);
        }

        /*
         * ⚠️ দুইটা সংখ্যাই আগে দাবি করা হয়, শূন্য সংগ্রহে সবুজ থাকা
         * ঠেকাতে। ⓘ আজ একাধিকবার দেখা গেছে একটা মাপার যন্ত্র কিছুই না
         * মেপে সফল হয়েছে — ফাইল খোঁজাটা ভেঙে গেলে নিচের দাবিটা নীরবে
         * পাস করত।
         */
        $this->assertGreaterThan(200, $files,
            'একটাও ব্লেড ফাইল পাওয়া গেল না — খোঁজাটা কি আর কাজ করছে?');

        $this->assertGreaterThanOrEqual(12, $blocks, implode("\n", [
            'ইনলাইন ব্লক পাওয়া গেল '.$blocks.'টা, অথচ ১৪ সেপ্টেম্বর ২০২৬-এ',
            'মেপে পাওয়া গিয়েছিল ১২টা (৯টা script, ৩টা style)।',
            '',
            '⚠️ কমে যাওয়া মানে হয় ব্লকগুলো সরানো হয়েছে (ভালো), নয়তো এই',
            'পাহারার খোঁজাটা আর ওদের চিনতে পারছে না (খারাপ, আর নীরব)।',
        ]));

        sort($offenders);

        $this->assertSame([], $offenders, implode("\n", [
            'এই ইনলাইন ব্লকগুলোয় `@nonce` নেই:',
            '',
            '⛔ CSP-তে `unsafe-inline` আর নেই, তাই ব্রাউজার এগুলো চালাবে না।',
            '⚠️ পাতাটা তবু ২০০ দেবে — একমাত্র লক্ষণ কনসোলে CSP-র অভিযোগ আর',
            'একটা বোতাম যেটা কিছুই করে না।',
            '',
            'সারাই: `<script @nonce>` অথবা `<style @nonce>`।',
            '',
            ...$offenders,
        ]));
    }

    /**
     * ⭐ নীতিটা নিজেই — হেডারে যা সত্যিই যায়।
     *
     * ⓘ উপরের দাবিটা কেবল ব্লেড পড়ে। ব্লেডে `@nonce` থাকা আর হেডারে
     * `'nonce-...'` যাওয়া **দুইটা আলাদা সত্য**, আর একটা ছাড়া অন্যটা
     * অর্থহীন: চিহ্ন বসল অথচ হেডারে গেল না — সব ব্লক বন্ধ; হেডারে গেল
     * অথচ `unsafe-inline`-ও থাকল — সুরক্ষাটা শূন্য।
     */
    public function test_the_header_carries_a_nonce_and_not_unsafe_inline(): void
    {
        $directives = $this->directives();

        $this->assertArrayHasKey('script-src', $directives);
        $this->assertStringContainsString("'nonce-", $directives['script-src'],
            'script-src-এ nonce নেই — তাহলে ১২টা ইনলাইন ব্লকের কোনোটাই চলবে না।');
        $this->assertStringNotContainsString("'unsafe-inline'", $directives['script-src'],
            "script-src-এ `'unsafe-inline'` ফিরে এসেছে — nonce থাকলে ব্রাউজার ওটা উপেক্ষা করে, "
            .'কিন্তু ফেরানোর চেষ্টাটাই বলে দেয় কেউ ভুল পথে সারাই খুঁজছেন।');

        $this->assertStringContainsString("'nonce-", $directives['style-src']);
        $this->assertStringNotContainsString("'unsafe-inline'", $directives['style-src']);

        /*
         * ⚠️ এটা **থাকতেই হবে**, আর সেটা ছাড় নয় — কারণ। Alpine-এর
         * `x-show` নিজে `element.style.display` বসায়, আর ব্লেডে ১২০টা
         * ইনলাইন `style=` অ্যাট্রিবিউট আছে। না থাকলে `style-src`
         * অ্যাট্রিবিউটেও খাটত আর প্রতিটা `x-show` নীরবে থেমে যেত।
         */
        $this->assertSame("'unsafe-inline'", $directives['style-src-attr'] ?? null,
            'style-src-attr সরানো হয়েছে — তাহলে প্রতিটা x-show আর ইনলাইন style নীরবে বন্ধ।');

        $this->assertSame("'none'", $directives['object-src'] ?? null);
        $this->assertSame("'self'", $directives['frame-ancestors'] ?? null);
    }

    /**
     * ⛔ প্রতিটা উত্তরে আলাদা চিহ্ন।
     *
     * ⚠️ একই চিহ্ন বারবার গেলে nonce-টা আর গোপন থাকে না, আর তখন
     * আক্রমণকারী সেটা একবার পড়ে নিজের স্ক্রিপ্টে বসাতে পারে। ⓘ ভুলটা
     * কিছুই ভাঙত না — পাতা ঠিক আগের মতোই চলত। ঠিক সেজন্যই মাপা।
     */
    public function test_each_response_gets_a_fresh_nonce(): void
    {
        $first = $this->directives()['script-src'];
        $second = $this->directives()['script-src'];

        $this->assertNotSame($first, $second,
            'দুইটা অনুরোধে একই nonce গেছে — rotate() বাদ পড়েছে কি না দেখুন।');
    }

    /**
     * চিহ্নটা যথেষ্ট এলোমেলো — CSP-র নির্দিষ্টকরণ ১২৮ বিট চায়।
     */
    public function test_the_nonce_is_long_enough_to_be_a_secret(): void
    {
        $raw = base64_decode(Csp::rotate(), true);

        $this->assertIsString($raw);
        $this->assertGreaterThanOrEqual(16, strlen($raw),
            'চিহ্নটা ১২৮ বিটের কম — অনুমান করা যায় এমন nonce মানে কোনো nonce নয়।');
    }

    /**
     * মিডলওয়্যার চালিয়ে হেডারটা নির্দেশিকায় ভাঙা।
     *
     * @return array<string, string>
     */
    private function directives(): array
    {
        $response = (new ContentSecurityPolicy)->handle(
            Request::create('/'),
            fn (): Response => new Response('ok'),
        );

        $header = (string) $response->headers->get('Content-Security-Policy');

        $this->assertNotSame('', $header,
            'হেডারটাই বসেনি — ABOS_CSP কি off হয়ে আছে?');

        $map = [];

        foreach (explode(';', $header) as $piece) {
            $piece = trim($piece);

            if ($piece === '') {
                continue;
            }

            [$name, $value] = array_pad(explode(' ', $piece, 2), 2, '');
            $map[$name] = trim($value);
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $paths = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $paths[] = $file->getPathname();
        }

        foreach (File::directories(app_path('Modules')) as $module) {
            $views = $module.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'views';

            if (! File::isDirectory($views)) {
                continue;
            }

            foreach (File::allFiles($views) as $file) {
                $paths[] = $file->getPathname();
            }
        }

        return array_values(array_filter($paths, fn (string $p): bool => str_ends_with($p, '.blade.php')));
    }

    /**
     * @param  list<string>  $offenders
     */
    private function scan(string $path, array &$offenders, int &$blocks): void
    {
        /*
         * ⚠️ মন্তব্য আগে ফেলে দেওয়া হয়, নাহলে দুইটা ফাইল মিথ্যা অপরাধী
         * হত: `layouts/app.blade.php` তার মন্তব্যে `<script>` শব্দটা
         * ব্যাখ্যা করে, আর `mail/password_reset` লেখে যে ইমেইল ক্লায়েন্টরা
         * `<style>` ব্লক ফেলে দেয়। ⓘ দুইটাই ব্যাখ্যা, কোড নয়।
         */
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', File::get($path));

        if (! preg_match_all('/<(script|style)\b([^>]*)>/i', $source, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[2] as $i => [$attributes, $offset]) {
            // বাইরের ফাইল — `src=` বা `href=` থাকলে ভিতরে কিছু নেই
            if (preg_match('/\b(src|href)\s*=/i', $attributes) === 1) {
                continue;
            }

            $blocks++;

            if (preg_match('/@nonce\b|\bnonce\s*=/i', $attributes) === 1) {
                continue;
            }

            $line = substr_count(substr($source, 0, (int) $offset), "\n") + 1;
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)
                .':'.$line.' — <'.$matches[1][$i][0].'>';
        }
    }
}
