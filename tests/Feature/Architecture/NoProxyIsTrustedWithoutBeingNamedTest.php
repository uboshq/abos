<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * যেকোনো প্রক্সিকে বিশ্বাস মানে যে কারও পাঠানো ঠিকানাকে বিশ্বাস।
 *
 * ── ⛔ যা ছিল, ১৭ সেপ্টেম্বর ২০২৬ পর্যন্ত ─────────────────────────────
 *     $middleware->trustProxies(at: '*');
 *
 * ⚠️ `'*'` মানে **যেকোনো** উৎসের `X-Forwarded-For` মেনে নেওয়া। ⛔ একটা
 * হেডার জুড়ে দিলেই ব্যবহারকারীর ঠিকানা বদলে যেত, আর দুইটা জিনিস ভাঙত:
 *
 *   • **rate-limit এড়ানো** — প্রতি অনুরোধে নতুন ঠিকানা দেখিয়ে লগইনে
 *     যত খুশি চেষ্টা করা যেত, আর পাহারাটা কিছুই টের পেত না
 *   • **অডিট মিথ্যা বলত** — "কে কোথা থেকে করেছে" ঘরটায় আক্রমণকারীর
 *     নিজের বেছে দেওয়া ঠিকানা বসত
 *
 * ⓘ আর ভুলটা নীরব: সব কাজ করত, কেবল খাতায় লেখা ঠিকানাটা মিথ্যা হত।
 *
 * ── ⭐ কেন এই পরীক্ষাটা ─────────────────────────────────────────────
 * `'*'` লাইনটা কোনো ব্যাখ্যা ছাড়াই বসানো ছিল, আর সেটাই সবচেয়ে সহজে
 * ফিরে আসে: কেউ লাইভে "IP ঠিক আসছে না" দেখে আবার `'*'` বসিয়ে দেবে,
 * কারণ ওতে সমস্যাটা সাথে সাথে মিটে যায়।
 *
 * ⚠️ তাই নিয়মটা লিখিত: **প্রক্সি বিশ্বাস করতে হলে তার নাম বলতে হবে।**
 * ⓘ প্রকৃত প্রক্সি বসলে (Cloudflare, লোড ব্যালান্সার) তার IP রেঞ্জটা
 * লিখতে হবে — তালিকা খালি রাখা বা রেঞ্জ লেখা, দুইটাই বৈধ; `'*'` নয়।
 */
final class NoProxyIsTrustedWithoutBeingNamedTest extends TestCase
{
    /**
     * ⛔ `trustProxies(at: '*')` কোথাও নেই।
     */
    public function test_no_proxy_is_trusted_blindly(): void
    {
        $source = (string) file_get_contents(base_path('bootstrap/app.php'));

        /*
         * ⓘ মন্তব্য বাদ — এই ফাইলের ব্যাখ্যাতেই পুরনো লাইনটা উদ্ধৃত
         * আছে, আর সেটাকে কোড ধরলে পরীক্ষাটা নিজের ব্যাখ্যাকেই অপরাধ
         * বলত। ⚠️ আজ সকালেই [[AlpineHandlersHaveAScopeTest]] ঠিক এই
         * ফাঁদে পড়েছিল।
         */
        $code = $this->codeOnly($source);

        /*
         * ⚠️ ছাঁটাটা ভারবাহী: ফাইলটায় একটা মন্তব্য আছে যেখানে পুরনো
         * `trustProxies` লাইনটা উদ্ধৃত — কেন সরানো হয়েছে তা বোঝাতে।
         * ⛔ ছাঁটা না হলে এই দাবিটা চিরকাল লাল থাকত।
         *
         * ⓘ আর ছাঁটা **বেশি** হলে উল্টোটা: ফাইলটা খালি হয়ে যেত, কিছুই
         * মিলত না, আর দাবিটা চিরকাল সবুজ। তাই দুই দিকেই মাপা হয়।
         */
        $this->assertStringContainsString('trustProxies', $code, implode(PHP_EOL, [
            'মন্তব্য ছাঁটার পর `trustProxies` লাইনটাই আর নেই।',
            '',
            'হয় ছাঁটার নিয়মটা বেশি খেয়ে ফেলেছে, নয় সিদ্ধান্তটা সত্যিই',
            'মুছে গেছে — দুইটার যেকোনোটাই পাহারাটাকে অন্ধ করে দেয়।',
        ]));

        $this->assertDoesNotMatchRegularExpression(
            $this->wideOpen(),
            $code,
            implode("\n", [
                "bootstrap/app.php-এ trustProxies(at: '*') বসানো আছে।",
                '',
                '⛔ এতে যেকোনো উৎসের X-Forwarded-For মেনে নেওয়া হয় —',
                'অর্থাৎ যে কেউ নিজের ঠিকানা বদলে দেখাতে পারে।',
                '',
                '⚠️ ফল: rate-limit এড়ানো যায়, আর অডিটে মিথ্যা ঠিকানা বসে।',
                'ⓘ কিছুই ভাঙে না, তাই কেউ টেরও পায় না।',
                '',
                '⭐ প্রক্সি সত্যিই থাকলে তার IP রেঞ্জ লিখুন; না থাকলে',
                'খালি তালিকা — trustProxies(at: []).',
            ]),
        );
    }

    /**
     * ⭐ আর লাইনটা আছেই — কেউ যেন পুরোটা মুছে না ফেলে।
     *
     * ⓘ মুছে ফেললে Laravel-এর ডিফল্ট আচরণে ফিরে যেত, আর সেটা
     * সংস্করণভেদে বদলায়। ⚠️ ইচ্ছাকৃত সিদ্ধান্তটা লেখা থাকাই নিরাপদ।
     */
    public function test_the_decision_is_written_down(): void
    {
        /*
         * ⚠️ **ছাঁটা** কোড পড়া হয়, কাঁচা সোর্স নয় — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ আগে কাঁচা ফাইলটা পড়া হত, আর ফাইলে একটা মন্তব্য আছে যেখানে
         * পুরনো `trustProxies` লাইনটা উদ্ধৃত। ⓘ অর্থাৎ কেউ আসল লাইনটা
         * মুছে ফেললেও এই দাবিটা সবুজ থাকত — **মন্তব্যটাই তাকে সন্তুষ্ট
         * করত**, আর সিদ্ধান্তটা ডিফল্টের ভরসায় চলে যেত।
         */
        $this->assertStringContainsString(
            'trustProxies',
            $this->codeOnly((string) file_get_contents(base_path('bootstrap/app.php'))),
            'trustProxies লাইনটাই নেই — সিদ্ধান্তটা লেখা থাকা দরকার, ডিফল্টের ভরসায় নয়।',
        );
    }

    /** ⓘ মন্তব্য বাদ — ফাইলটা পুরনো লাইনটা উদ্ধৃত করে, আর সেটা কোড নয়। */
    private function codeOnly(string $source): string
    {
        $code = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;

        return preg_replace('#^\s*//.*$#m', '', $code) ?? $code;
    }

    /**
     * ⭐ পাহারাটা সত্যিই খোলা প্রক্সি ধরতে পারে — ২২ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ দুইটা দাবিই এতদিন কেবল বলত *"খারাপ জিনিসটা পাইনি"*, আর ছেঁড়া
     * জালও হুবহু ঐ কথাই বলত। ⚠️ তাই জালটাকে জানা মাছ খাওয়ানো হয়।
     */
    public function test_the_rule_actually_catches_a_wide_open_proxy(): void
    {
        $bad = '<?php $middleware->trustProxies(at: \'*\');';

        $this->assertMatchesRegularExpression($this->wideOpen(), $this->codeOnly($bad),
            'সব উৎস বিশ্বাস করার লাইনটাই চোখে পড়ছে না — পাহারাটা অন্ধ।');

        $fine = '<?php $middleware->trustProxies(at: []);';

        $this->assertDoesNotMatchRegularExpression($this->wideOpen(), $this->codeOnly($fine),
            'খালি তালিকাকেও খোলা প্রক্সি বলছে — তাহলে প্রতিটা রান লাল হত।');

        /*
         * ⛔ মন্তব্যের ভিতরের লাইনটা গোনা যাবে না — ঠিক এই কারণেই
         * ছাঁটাটা আছে, আর ফাইলে ঐরকম একটা মন্তব্য সত্যিই আছে।
         */
        $quoted = '<?php /* আগে ছিল: $middleware->trustProxies(at: \'*\'); */ $middleware->trustProxies(at: []);';

        $this->assertDoesNotMatchRegularExpression($this->wideOpen(), $this->codeOnly($quoted),
            'মন্তব্যে উদ্ধৃত পুরনো লাইনটাকেও অপরাধী গণ্য করা হচ্ছে।');
    }

    /** ⓘ নিয়মটা এক জায়গায় — সুইপ আর নিজের পরীক্ষা দুইটাই এটাই ডাকে। */
    private function wideOpen(): string
    {
        return '/trustProxies\s*\(\s*at:\s*[\'"]\*[\'"]/';
    }
}
