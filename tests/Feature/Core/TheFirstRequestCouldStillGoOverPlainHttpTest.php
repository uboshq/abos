<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * HTTPS-এ ঢুকলে ব্রাউজারকে বলা হয় আর কখনো খোলা পথে না যেতে।
 *
 * ── কেন, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * নিরীক্ষায় ধরা পড়ল `Strict-Transport-Security` হেডারটা নেই। ⓘ ছোট
 * ফাঁক, কিন্তু আসল: ব্রাউজার প্রথমবার `http://` দিয়ে ঢোকে আর সার্ভার
 * `https://`-এ পাঠায় — ঐ **প্রথম অনুরোধটা খোলা যায়**। ⚠️ কেউ মাঝপথে
 * বসে থাকলে সে ওটাই ধরে, আর সেশন কুকিটা নিয়ে যায়।
 *
 * ⭐ HSTS থাকলে দ্বিতীয়বার থেকে ব্রাউজার নিজেই আর http-এ যায় না।
 *
 * ── ⓘ লগইনের পাতা কেন, ড্যাশবোর্ড নয় ────────────────────────────────
 * হেডারগুলো বসায় গ্লোবাল মিডলওয়্যার, তাই যেকোনো `web` পাতাই সমান
 * প্রমাণ। ⚠️ প্রথম খসড়ায় `/dashboard` ধরা হয়েছিল — ওটা পুনর্নির্দেশ
 * করে, আর পরীক্ষাটা ভুল কারণে লাল দিচ্ছিল। ⭐ সাথে এখানে ডাটাবেস বা
 * লগইন কিছুই লাগে না, তাই চার মিনিটের বদলে এক সেকেন্ডে চলে।
 */
final class TheFirstRequestCouldStillGoOverPlainHttpTest extends TestCase
{
    public function test_an_https_page_tells_the_browser_to_stay_on_https(): void
    {
        $header = $this->get('https://localhost/login')
            ->headers->get('Strict-Transport-Security');

        $this->assertNotNull($header, implode("\n", [
            '⛔ HTTPS-এ দেওয়া পাতায় `Strict-Transport-Security` নেই।',
            '',
            'ⓘ তাহলে পরের বার কেউ `http://` টাইপ করলে ব্রাউজার সত্যিই',
            'খোলা পথে একটা অনুরোধ পাঠাবে, আর সেশন কুকিটা তাতে যেতে পারে।',
        ]));

        preg_match('/max-age=(\d+)/', $header, $m);

        /* ⓘ এক বছর — কম হলে ব্রাউজার মনে রাখাটা তাড়াতাড়ি ভুলে যায়। */
        $this->assertGreaterThanOrEqual(
            31536000,
            (int) ($m[1] ?? 0),
            'মেয়াদটা এক বছরের কম — ততদিনে ব্রাউজার ভুলে যাবে।'
        );

        /*
         * ⛔ `preload` যেন **না** থাকে।
         *
         * ⚠️ ওটা ব্রাউজারের ভিতরে বেক হওয়া তালিকা, আর ফেরার পথ নেই।
         * কোনো সাবডোমেইন কোনোদিন http-এ লাগলে মাসখানেক অচল থাকত, আর
         * সারানোর উপায় কেবল অপেক্ষা।
         */
        $this->assertStringNotContainsString('preload', $header, implode("\n", [
            '⛔ `preload` বসানো হয়েছে — এটা একমুখী দরজা।',
            '',
            '⚠️ তুলে নিতে চাইলে ব্রাউজারের তালিকা থেকে নামতে মাস লাগে।',
            'সচেতনভাবে চাইলে এই দাবিটা বদলান, আর কারণটা লিখুন।',
        ]));
    }

    /**
     * ⛔ উন্নয়নে যেন না বসে।
     *
     * ⚠️ `http://localhost`-এ হেডারটা একবার বসলে ব্রাউজার ঐ হোস্টটাকে
     * চিরকালের জন্য https ধরত, আর স্থানীয় কাজ বন্ধ হয়ে যেত — সারানোর
     * একমাত্র পথ ব্রাউজারের ভিতরের তালিকা হাতে মোছা।
     */
    public function test_a_plain_http_page_does_not_get_it(): void
    {
        $this->assertNull(
            $this->get('http://localhost/login')->headers->get('Strict-Transport-Security'),
            '⛔ খোলা http-এও HSTS বসছে — উন্নয়নের মেশিন এতে আটকে যায়।'
        );
    }

    /**
     * ⓘ ব্রাউজার যেন ফাইলের ধরন নিজে থেকে আন্দাজ না করে।
     *
     * ⚠️ সংযুক্তির ইঞ্জিন ব্রাউজারের বলা mime সংরক্ষণ করে আর ফেরতও দেয়;
     * `nosniff` ছাড়া ঐ পথে একদিন একটা HTML ফাইল পাতা হিসেবে চলত।
     */
    public function test_the_browser_is_told_not_to_guess_a_file_type(): void
    {
        $this->get('https://localhost/login')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * ⛔ CSP বন্ধ করলেও এই দুইটা যেন থেকে যায়।
     *
     * ⚠️ `ABOS_CSP=off` সুইচটা বানানো *"CSP কোনো পাতা ভাঙছে"* অবস্থার
     * জন্য। ⓘ প্রথম খসড়ায় হেডার দুইটা ঐ সুইচের নিচে বসেছিল, তাই সুইচ
     * টিপলে নিরাপত্তার বাকি দুইটাও নিভে যেত — আর সেটা কেউ চায়নি।
     */
    public function test_switching_the_content_policy_off_does_not_take_these_with_it(): void
    {
        putenv('ABOS_CSP=off');

        try {
            $response = $this->get('https://localhost/login');

            $this->assertNotNull(
                $response->headers->get('Strict-Transport-Security'),
                '⛔ CSP নেভালে HSTS-ও নিভে গেছে — দুইটা আলাদা জিনিস।'
            );

            $response->assertHeader('X-Content-Type-Options', 'nosniff');
        } finally {
            putenv('ABOS_CSP');
        }
    }
}
