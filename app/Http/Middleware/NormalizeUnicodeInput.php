<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Normalizer;
use Symfony\Component\HttpFoundation\Response;

/**
 * প্রতিটা ইনপুট একই ইউনিকোড রূপে আনা।
 *
 * বাংলায় ড়, ঢ় ও য় দুইভাবে লেখা যায়:
 *
 *   ভাড়া = ভ + া + ড়(U+09DC) + া          — একক অক্ষর
 *   ভাড়া = ভ + া + ড(U+09A1) + ়(U+09BC) + া — ড আর নুক্তা আলাদা
 *
 * পর্দায় দুটো হুবহু এক, কিন্তু বাইট আলাদা — তাই একটা দিয়ে খুঁজলে অন্যটা
 * পাওয়া যায় না। কোন রূপটা আসবে তা নির্ভর করে কীবোর্ডের উপর: Avro,
 * Bijoy, Android ও iOS-এর ডিফল্ট — সবাই এক নয়। ফলে একজনের লেখা গ্রাহক
 * আরেকজন খুঁজে পেত না, আর কেউ বুঝতেও পারত না কেন।
 *
 * ধরা পড়েছে হিসাবের ছকে "ভাড়া" খুঁজতে গিয়ে — খাতটা তালিকায় চোখের
 * সামনে ছিল, তবু খোঁজায় শূন্য ফল।
 *
 * এখানে, মিডলওয়্যারে, কারণ নিয়মটা প্রতিটা মডিউলের প্রতিটা ঘরে খাটে।
 * প্রতিটা মডেলে বা প্রতিটা খোঁজায় আলাদা করে লিখলে একদিন কেউ ভুলে যেত,
 * আর ভুলে যাওয়া ঘরটা নীরবে খুঁজে না পাওয়া হয়ে থাকত।
 *
 * NFC বাছা হয়েছে কারণ ড়/ঢ়/য় ইউনিকোডের composition exclusion তালিকায় —
 * NFC এদের একক অক্ষরে জোড়ে না, ভেঙে রাখে। ফলে NFC-ই এদের একমাত্র
 * নিয়মিত রূপ, আর দুই দিক থেকেই সেখানে পৌঁছানো যায়।
 */
class NormalizeUnicodeInput
{
    /**
     * যেগুলো ছোঁয়া হয় না।
     *
     * পাসওয়ার্ড ব্যবহারকারী যা টাইপ করেছে ঠিক তা-ই থাকতে হবে: বদলে দিলে
     * পুরনো হ্যাশের সাথে আর মিলত না, আর কেউ লগইন করতে পারত না।
     *
     * @var list<string>
     */
    private const SKIP = ['password', 'password_confirmation', 'current_password', '_token'];

    public function handle(Request $request, Closure $next): Response
    {
        // ext-intl না থাকলে চুপচাপ পাশ কাটানো — সার্ভারে না থাকলে অ্যাপ
        // বন্ধ হওয়ার চেয়ে খোঁজা কম নিখুঁত হওয়া ভালো।
        if (! class_exists(Normalizer::class)) {
            return $next($request);
        }

        /*
         * ⛔ প্রতিটা উৎস নিজের ব্যাগেই ফেরে — ১৫ সেপ্টেম্বর ২০২৬।
         *
         * ── এখানে আগে কী ছিল ────────────────────────────────────────
         *     $request->merge($this->normalise($request->input(), ''));
         *
         * দেখতে নির্দোষ, কিন্তু দুইটা কথা একসাথে সত্য:
         *
         *   • `input()` **শরীর আর query string একসাথে** দেয়
         *     (`getInputSource()->all() + $this->query->all()`)
         *   • `merge()` তার ফলটা **কেবল getInputSource()-এ** লেখে,
         *     আর JSON অনুরোধে সেটা হলো json ব্যাগ
         *
         * ⚠️ ফল: **প্রতিটা JSON দরজায় query string-এর ঘরগুলো শরীরের
         * ভিতরে ঢুকে যেত।** ⓘ শরীরটা অবজেক্ট হলে কেউ টের পেত না —
         * একটা বাড়তি চাবি, কেউ পড়ে না। কিন্তু শরীরটা **তালিকা** হলে
         * সেটা আর তালিকা থাকত না।
         *
         * ── ⛔ যেভাবে ধরা পড়ল, আর কেন এত দেরিতে ─────────────────────
         * মোবাইল sync push (`POST /api/v1/sync/{module}/push`) শরীরে
         * একটা তালিকা পাঠায়, আর চুক্তির §২ অনুযায়ী ঠিকানায় সবসময়
         * `?deviceId=` থাকে। তাই শরীর `[0 => {…}]` আর query
         * `['deviceId' => '…']` মিলে হত `[0 => {…}, 'deviceId' => '…']`
         * — `array_is_list()`-এ ব্যর্থ, আর সার্ভার ৪২২।
         *
         * ⚠️ ⛔ এর আগে কারণটা ভুল জায়গায় খোঁজা হয়েছিল: কন্ট্রোলারে
         * `$request->all()` বদলে `$request->json()->all()` করা হয় আর
         * সেটা **সারাই হিসেবে লাইভে যায়**। কিন্তু দূষণটা ঘটে এখানে,
         * অনেক আগে — json ব্যাগটা নিজেই ততক্ষণে নোংরা। ⓘ অর্থাৎ ঐ
         * সারাইয়ের পরেও একটাও push সফল হত না।
         *
         * ⭐ নিয়মটা এখন সরল: **যে ব্যাগ থেকে পড়া, সেই ব্যাগেই লেখা।**
         * query query-তে, শরীর শরীরে। ⓘ পড়ার দিকে কিছুই বদলায় না —
         * `input()` ও `all()` আগের মতোই দুইটা মিলিয়ে দেয়, কেবল এখন
         * দুইটাই আলাদা করে normalised।
         */
        $request->query->replace($this->normalise($request->query->all(), ''));

        /*
         * শরীরের ব্যাগটা — JSON হলে json ব্যাগ, নাহলে POST ব্যাগ।
         *
         * ── ⛔ `getInputSource()` ডাকা যায় না, ১৫ সেপ্টেম্বর ২০২৬ ────
         * Laravel-এর ঐ মেথডটা **protected** (`Request.php:482`), তাই
         * বাইরে থেকে ডাকলে `__call()`-এ পড়ে আর `BadMethodCallException`।
         *
         * ⚠️ আর ব্যর্থতাটা এখানে সবচেয়ে চওড়া হত: এই middleware
         * `bootstrap/app.php`-এ prepend করা, তাই **প্রতিটা দরজা ৫০০**
         * দিত — JSON নয় শুধু, সাধারণ GET আর ফর্ম POST-ও।
         *
         * ⓘ ভুলটা চোখে পড়ে না, কারণ Laravel-এর নিজের কোডে লাইনটা
         * বহুবার আছে — কিন্তু সবই ক্লাসের **ভিতর** থেকে।
         *
         * ── ⚠️ আর `getPayload()` নয় ─────────────────────────────────
         * Symfony-র `getPayload()` public, দেখতে সহজ বিকল্প। ⛔ কিন্তু
         * JSON অনুরোধে সে **প্রতিবার নতুন InputBag বানিয়ে ফেরায়**, তাই
         * তাতে `replace()` করলে বদলটা অনুরোধে বসতই না — আর কোথাও কোনো
         * ভুলও দেখাত না। ⓘ ঠিক উপরের মূল বাগটার মতোই নীরব।
         */
        $body = $request->isJson() ? $request->json() : $request->request;
        $body->replace($this->normalise($body->all(), ''));

        return $next($request);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private function normalise(array $input, string $prefix): array
    {
        foreach ($input as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $input[$key] = $this->normalise($value, $path);

                continue;
            }

            if (! is_string($value) || in_array((string) $key, self::SKIP, true)) {
                continue;
            }

            // ইতিমধ্যে NFC হলে normalize() কাজই করে না, তাই আগে দেখে
            // নেওয়া হয় — বেশিরভাগ অনুরোধে সব ঘরই ইংরেজি ও ASCII।
            if (Normalizer::isNormalized($value, Normalizer::FORM_C)) {
                continue;
            }

            $normalised = Normalizer::normalize($value, Normalizer::FORM_C);

            // ভাঙা বাইট এলে normalize() false দেয় — তখন মূলটাই থাক,
            // কারণ ভ্যালিডেশনের কাজ ওটা ধরা, এই মিডলওয়্যারের নয়।
            if ($normalised !== false) {
                $input[$key] = $normalised;
            }
        }

        return $input;
    }
}
