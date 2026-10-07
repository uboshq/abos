<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ব্রাউজারকে বলা হয়নি কী বাইরে পাঠাবে না, আর কোন যন্ত্রে হাত দেবে না।
 *
 * ── কেন, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * নিরীক্ষায় লাইভের হেডারে `Referrer-Policy` আর `Permissions-Policy` দুইটাই
 * অনুপস্থিত। ⚠️ প্রথমটা ছাড়া ব্রাউজার বাইরের লিংকে ক্লিকের সাথে পুরো
 * ঠিকানাটা পাঠাতে পারে — `?customer=…&from=…` সহ। ⚠️ দ্বিতীয়টা ছাড়া
 * একটা ঢুকে পড়া স্ক্রিপ্ট ক্যামেরা বা অবস্থান চাইতে পারত, আর মানুষ
 * ভাবতেন ERP-ই চাইছে।
 *
 * ⓘ তিন রকম পাতা: লগইনের আগের, লগইনের পরের, আর ভুলের (৪০৪) — কারণ
 * ভুলের পাতাও ঠিকানা বহন করে, আর সেটাই সবচেয়ে কম দেখা হয়।
 */
final class TheBrowserWasNeverToldWhatNotToShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_carries_both_headers(): void
    {
        $this->assertBothHeaders($this->get('https://localhost/login')->assertOk(), 'লগইন পাতা');
    }

    public function test_a_signed_in_page_carries_both_headers(): void
    {
        $this->seed(DemoSeeder::class);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->assertBothHeaders($this->get('https://localhost/')->assertOk(), 'ড্যাশবোর্ড');
    }

    public function test_a_not_found_page_carries_both_headers(): void
    {
        $response = $this->get('https://localhost/p/'.str_repeat('a', 64))->assertNotFound();

        $this->assertBothHeaders($response, '৪০৪ পাতা');
    }

    /**
     * ⛔ কোনো রুটের সাথে মেলে না এমন ঠিকানা — ২৭ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ ওপরের ৪০৪-টা একটা রুটে মেলে (`/p/{token}`), তাই `web` গোষ্ঠী চলে।
     * ⚠️ অচেনা ঠিকানা গোষ্ঠীতে ঢোকেই না — মিডলওয়্যারটা সেখানে থাকলে এই
     * উত্তরে একটা হেডারও যেত না। সেজন্যই এটা এখন বিশ্বব্যাপী।
     */
    public function test_a_url_that_matches_no_route_still_carries_every_header(): void
    {
        $response = $this->get('https://localhost/no-such-page-'.str_repeat('z', 12))->assertNotFound();

        $this->assertBothHeaders($response, 'অচেনা ঠিকানার ৪০৪');
        $this->assertNotNull($response->headers->get('Content-Security-Policy'), '⛔ অচেনা ঠিকানায় CSP নেই।');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertNotNull($response->headers->get('Strict-Transport-Security'), '⛔ HTTPS-এর অচেনা ঠিকানায় HSTS নেই।');
    }

    /** ⓘ HSTS কেবল HTTPS-এ — দুই সার্ভারেই প্রক্সি যা বলে, কোডে বাঁধা কিছু নয়। */
    public function test_plain_http_gets_no_hsts_even_on_an_unknown_url(): void
    {
        $response = $this->get('http://localhost/no-such-page-'.str_repeat('z', 12))->assertNotFound();

        $this->assertNull($response->headers->get('Strict-Transport-Security'));
        $this->assertBothHeaders($response, 'HTTP-র অচেনা ঠিকানা');
    }

    /**
     * ⓘ পর্দার খোলস (`shell.js`) পূর্ণ পর্দা চায় — ওটা নিজের সাইটের জন্য
     * খোলা থাকতেই হবে, নাহলে বোতামটা নীরবে কাজ করা বন্ধ করত।
     */
    public function test_full_screen_stays_open_for_our_own_pages(): void
    {
        $policy = (string) $this->get('https://localhost/login')->headers->get('Permissions-Policy');

        $this->assertStringContainsString('fullscreen=(self)', $policy);
    }

    /**
     * ⛔ CSP-র সুইচ কেবল CSP নেভায় — এই দুইটা নয়, HSTS-এর মতোই।
     */
    public function test_switching_the_content_policy_off_does_not_take_these_with_it(): void
    {
        config(['abos.csp' => 'off']);

        $this->assertBothHeaders($this->get('https://localhost/login')->assertOk(), 'CSP বন্ধ অবস্থায়');
    }

    private function assertBothHeaders(TestResponse $response, string $page): void
    {
        $this->assertSame(
            'strict-origin-when-cross-origin',
            $response->headers->get('Referrer-Policy'),
            "⛔ {$page}: Referrer-Policy নেই — বাইরের লিংকে পুরো ঠিকানা যেতে পারে।"
        );

        $policy = (string) $response->headers->get('Permissions-Policy');

        foreach (['camera=()', 'microphone=()', 'geolocation=()', 'payment=()', 'usb=()'] as $denied) {
            $this->assertStringContainsString($denied, $policy, "⛔ {$page}: Permissions-Policy-তে {$denied} নেই।");
        }
    }
}
