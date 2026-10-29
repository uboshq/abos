<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ⭐ ওয়েবের পাতার নিজের JSON প্রশ্নের ভুল-যাচাই ৪২২ JSON-এ আসে, ফেরত-পাঠানো (৩০২) নয় — a6, ২৩ অক্টোবর ২০২৬ (fe-র হটফিক্স)।
 *
 * ── ⛔ যা হতো ─────────────────────────────────────────────────────────
 * `bootstrap/app.php` ভুলকে JSON বানাত কেবল `api/*`-এ। তাই ওয়েবের পাতা যখন নিজে `fetch` করে (Accept: application/json, যেমন
 * টাকা নেওয়ার প্রিভিউ, [[collection.js]]), ভুল-যাচাইয়ে ৩০২ পেত, fetch আগের পাতার HTML-এ যেত, আর `response.json()` ভেঙে পাতা
 * কেবল "আনা গেল না" দেখাত — কারণটা কখনো নয়। আদায়ের শাখার Mac দৌড়ে ধরা পড়ল (a59a63dc2), কিন্তু নিয়মটা গোটা ওয়েবের।
 *
 * ── ⭐ দাবি, দুই দিকে ──────────────────────────────────────────────────
 *  (ক) ওয়েবে JSON চাওয়া অনুরোধ: ৪২২, `message` প্রথম ভুলের বাংলা বাক্য, `errors` ঘর ধরে — ফোনের ৪২২-এর একই আকার।
 *  (খ) সাধারণ ফর্ম-জমা (JSON চায় না): আগের মতোই আগের পাতায় ফেরত, সেশনে ভুল।
 * ⓘ `api/*` এই বদলে ছোঁয়া হয়নি (উপরের shouldRenderJsonWhen আগের মতো); তার পাহারা ফোনের দাবিগুলোয় (tests/Feature/Api)।
 *
 * ⓘ রুট পরীক্ষা নিজেই বানায় ([[ARefusalToPostIsAnAnswerNotACrashTest]]-এর মতো) — কোনো মডিউলের নিয়মের উপর দাঁড়িয়ে নয়।
 */
final class AWebPagesOwnJsonRequestGetsItsErrorsAsJsonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('bn');

        $check = function (Request $request) {
            $request->validate(['amount' => ['required']]);

            return response()->json(['ok' => true]);
        };
        Route::middleware('web')->post('/__web-json-check', $check);
    }

    public function test_a_web_pages_json_request_gets_422_with_the_bengali_reason_not_a_redirect(): void
    {
        $response = $this->from('/__where-i-was')->postJson('/__web-json-check', []);

        $this->assertSame(422, $response->status(), '⛔ ওয়েবের JSON প্রশ্নের ভুল-যাচাই ৪২২ নয় ('.$response->status().') — পাতার fetch কারণ পায় না');
        $this->assertSame($response->json('errors.amount.0'), $response->json('message'), '⛔ message প্রথম ভুলের বাক্য নয়');
        // ⓘ lang/bn/validation.php-এর 'required' — ":attribute দিতেই হবে।"
        $this->assertStringContainsString('দিতেই হবে', (string) $response->json('message'), '⛔ message বাংলায় কারণ বলেনি — '.$response->json('message'));
        $this->assertNull($response->headers->get('Location'), '⛔ JSON প্রশ্নকে তবু অন্য পাতায় পাঠাল');
    }

    public function test_a_plain_form_post_still_goes_back_with_its_errors_in_the_session(): void
    {
        $response = $this->from('/__where-i-was')->post('/__web-json-check', []);

        $this->assertSame(302, $response->status(), '⛔ সাধারণ ফর্ম-জমা আর আগের পাতায় ফেরে না ('.$response->status().')');
        $response->assertRedirect('/__where-i-was');
        $response->assertSessionHasErrors('amount');
    }
}
