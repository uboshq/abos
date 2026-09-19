<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\AlpineLiteral;
use App\Http\Middleware\ContentSecurityPolicy;
use Illuminate\Support\Facades\Blade;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ব্রাউজার আমাদের কোনো লেখাকে কোড বানিয়ে চালায় না — `unsafe-eval` নেই।
 *
 * ── ⭐ নিরীক্ষার ফলাফল ৩.১, শেষ হলো ১৯ সেপ্টেম্বর ২০২৬ ────────────────────
 * *"CSP থেকে `unsafe-inline` ও `unsafe-eval` তোলা — এটি এই পরিকল্পনার
 * সবচেয়ে কঠিন নিরাপত্তা-কাজ।"* ⓘ `unsafe-inline` গিয়েছিল ১৪ সেপ্টেম্বরে;
 * আজ `unsafe-eval`। অ্যাপ এখন চলে `@alpinejs/csp`-এ।
 *
 * ── ⚠️ এই ফাইলটা আগে ঋণ গুনত, আর গুনত ভুল ──────────────────────────────
 * আগের নাম ছিল `TheReasonWeStillAllowEvalTest`, আর সে বলত ১,৯০২টা
 * এক্সপ্রেশন বাকি। ⛔ ধারণাটা ছিল "CSP সংস্করণ কেবল নাম বোঝে" — ভুল। আসল
 * পার্সার দিয়ে মাপলে বাকি ছিল ২২৩টা, আর তার সাথে এমন দুইটা ফাঁদ যা
 * পুরনো গোনা দেখতেই পেত না:
 *
 *   · পাঁচটা পর্দা `<script>`-এ বৈশ্বিক ফাংশন লিখত (`function pos()`),
 *     আর CSP-Alpine বৈশ্বিক নাম দেখে না — পর্দা পুরো অচল হত
 *   · Laravel-এর `@js` লেখে `JSON.parse('ব…')` — `JSON` বৈশ্বিক,
 *     আর `\u` CSP-পার্সার চেনে না: বাংলা লেবেল হত `u09ac`
 *
 * ⓘ তাই এক্সপ্রেশনের পাহারা এখন PHP-তে নয় — `resources/js/csp-expressions.test.js`,
 * যেটা CSP-Alpine-এর **নিজের** `Tokenizer` আর `Parser` চালায় (`npm test`)।
 * অনুমান নয়: ব্রাউজার যা পড়তে পারবে না, সে-ও পারবে না।
 *
 * ── এই ফাইল যা পাহারা দেয় ────────────────────────────────────────────
 * PHP-র দিকের তিনটা জিনিস, যেগুলো JS পরীক্ষা দেখে না।
 */
final class TheBrowserNeverEvaluatesOurCodeTest extends TestCase
{
    /**
     * ⭐ নীতিতে `unsafe-eval` নেই — আর নীতিটা সত্যিই তৈরি করে দেখা।
     *
     * ⚠️ উৎস ফাইলে খুঁজলে মন্তব্যেও শব্দটা মিলত; তাই প্রতিফলন দিয়ে
     * `policy()` ডাকা (ওটা private, আর private-ই থাকা উচিত)।
     */
    public function test_the_policy_does_not_allow_eval(): void
    {
        $method = new ReflectionMethod(ContentSecurityPolicy::class, 'policy');
        $method->setAccessible(true);

        $policy = implode('; ', $method->invoke(app(ContentSecurityPolicy::class), 'test-nonce'));

        $this->assertStringContainsString("script-src 'self' 'nonce-test-nonce'", $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy,
            '`unsafe-eval` ফিরে এসেছে। ⛔ Alpine-এর CSP সংস্করণে এর দরকার নেই —'
            .' কোন পর্দা ভাঙছে সেটা খুঁজুন, দরজাটা খোলা নয়।');
    }

    /**
     * ⭐ যে বান্ডল পাঠানো হয় তাতে `Function(` নেই।
     *
     * ⛔ নীতি থেকে শব্দটা তুলে দিলেই হয় না: কেউ একদিন `import Alpine from
     * 'alpinejs'` ফিরিয়ে আনলে বান্ডল আবার `new AsyncFunction(...)` দিয়ে
     * চালাত, আর নীতি সেটা আটকাত — **প্রতিটা পর্দা একসাথে অচল**। ⓘ সাধারণ
     * Alpine-এর বান্ডলে ঠিক একবার `Function(` ছিল; CSP সংস্করণে শূন্য।
     *
     * ⚠️ `public/build` কমিট করা হয় (cPanel-এ npm নেই), তাই এটা সেই
     * ফাইলই — যেটা সত্যিই লাইভে যায়।
     */
    public function test_the_shipped_bundle_never_builds_code_from_text(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $bundle = (string) file_get_contents(public_path('build/'.$manifest['resources/js/app.js']['file']));

        $this->assertGreaterThan(50_000, strlen($bundle), 'বান্ডলটাই পাওয়া যায়নি — পরীক্ষা কিছু দেখছে না।');
        $this->assertStringContainsString('CSP Parser Error', $bundle,
            'বান্ডলে CSP-Alpine-এর পার্সার নেই — সাধারণ Alpine ফিরে এসেছে?');
        $this->assertStringNotContainsString('Function(', $bundle,
            'বান্ডলে `Function(` আছে — `unsafe-eval` ছাড়া এটা চলবে না।');
    }

    /**
     * ⭐ `@js` একটা সরল লিটারাল লেখে — `JSON.parse` নয়, `\u` নয়।
     *
     * ⓘ ফ্রেমওয়ার্কের নির্দেশিকা বদলানো ([[AlpineLiteral]]); কেউ override
     * তুলে দিলে এটা লাল হয়।
     */
    public function test_js_writes_a_literal_the_csp_parser_can_read(): void
    {
        $html = Blade::render('<div x-data="f(@js($v))"></div>', [
            'v' => ['label' => "বিক্রয় 'আজ' & \"কাল\"", 'n' => 5, 'on' => true],
        ]);

        $this->assertStringNotContainsString('JSON.parse', $html);
        $this->assertStringNotContainsString('\\u', $html);
        $this->assertStringContainsString('বিক্রয়', $html);

        // ⓘ অ্যাট্রিবিউট ভাঙে না — ব্রাউজার `&quot;` পড়ে `"` বানায়
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('f({"label":"বিক্রয় \'আজ\' & \\"কাল\\"","n":5,"on":true})', $decoded);
        $this->assertSame(1, substr_count($html, '"f('), 'মানের ভিতরের উদ্ধৃতি অ্যাট্রিবিউট শেষ করে দিয়েছে।');

        $this->assertSame('"x"', AlpineLiteral::json('x'));
    }
}
