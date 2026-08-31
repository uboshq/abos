<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * পাতা কোথা থেকে জিনিস আনতে পারবে — ব্রাউজারকে বলে দেওয়া।
 *
 * ── কী ছিল না, ৩১ আগস্ট ২০২৬ ─────────────────────────────────────────
 * লাইভ সার্ভারে HSTS, X-Frame-Options, X-Content-Type-Options আর
 * Referrer-Policy চারটাই বসানো ছিল — **কেবল CSP ছিল না**। ওটাই একমাত্র
 * হেডার যেটা বলে "এই পাতা বাইরের কোনো সার্ভার থেকে স্ক্রিপ্ট আনবে না,
 * আর কোনো তথ্য বাইরে পাঠাবে না"।
 *
 * ক্রেতার IT বিভাগ নিরাপত্তার তালিকা মেলাতে গিয়ে প্রথমেই এটা খোঁজে।
 *
 * ── কেন `unsafe-inline` আর `unsafe-eval` রাখা হয়েছে ──────────────────
 * সৎ থাকা ভালো: এই দুইটা থাকলে CSP-র সবচেয়ে বড় সুরক্ষাটা (ইনজেক্ট করা
 * স্ক্রিপ্ট চলতে না দেওয়া) দুর্বল হয়ে যায়। তবু এখন দুইটাই লাগে —
 * Alpine.js `x-` অ্যাট্রিবিউটের ভেতরের লেখা মূল্যায়ন করে, আর কাউন্টারের
 * পর্দায় বড় একটা ইনলাইন স্ক্রিপ্ট আছে।
 *
 * ওগুলো সরানো মানে প্রতিটা পর্দার জাভাস্ক্রিপ্ট আলাদা ফাইলে সরানো আর
 * Alpine-এর CSP সংস্করণে যাওয়া — একটা সত্যিকারের কাজ, আর সেটা এই
 * হেডারটা বসানোর সাথে একসাথে করতে গেলে দুইটাই আধা হত।
 *
 * ── তবু এটা ফাঁকা নয় ─────────────────────────────────────────────────
 * যা আজই আটকায়:
 *   · বাইরের কোনো ডোমেইন থেকে স্ক্রিপ্ট বা স্টাইল আনা;
 *   · `connect-src 'self'` — চুরি করা তথ্য অন্য সার্ভারে পাঠানো;
 *   · `form-action 'self'` — ফর্মকে বাইরের ঠিকানায় submit করানো;
 *   · `base-uri 'self'` — `<base>` বসিয়ে সব লিংক ঘুরিয়ে দেওয়া;
 *   · `frame-ancestors 'self'` — অন্য সাইটে iframe-এ বসিয়ে ক্লিক চুরি।
 *
 * অ্যাপের প্রতিটা অ্যাসেট নিজের ডোমেইনেই আছে (যাচাই করা: ব্লেডে বাইরের
 * ঠিকানা মাত্র একটা, আর সেটা একটা WhatsApp **লিংক**, কোনো ফাইল নয়),
 * তাই এতে কিছু ভাঙার কথা নয়।
 *
 * ── বন্ধ করার সুইচ ───────────────────────────────────────────────────
 * `ABOS_CSP=off` দিলে হেডারটা বসে না। কোনো পর্দা ভাঙলে ডিপ্লয় ফিরিয়ে
 * আনার আগে একটা লাইনেই থামানো যায় — নিরাপত্তার হেডারের জন্য কাউন্টার
 * বন্ধ থাকা সবচেয়ে খারাপ ফল।
 */
class ContentSecurityPolicy
{
    private const POLICY = [
        "default-src 'self'",

        // Alpine ও ইনলাইন স্ক্রিপ্টের জন্য — উপরের মন্তব্যে কারণ
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",

        // Tailwind ও ইনলাইন style অ্যাট্রিবিউট
        "style-src 'self' 'unsafe-inline'",

        // data: — বারকোড ও কিউআর ছবি ইনলাইন হিসেবে তৈরি হয়
        "img-src 'self' data:",
        "font-src 'self' data:",

        "connect-src 'self'",
        "form-action 'self'",
        "base-uri 'self'",
        "frame-ancestors 'self'",
        "object-src 'none'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (strtolower((string) env('ABOS_CSP', 'on')) === 'off') {
            return $response;
        }

        /*
         * আগে থেকে বসানো থাকলে ছোঁয়া হয় না।
         *
         * সামনের প্রক্সি (nginx বা Caddy) একদিন নিজে একটা CSP বসালে
         * দুইটা হেডার একসাথে যেত, আর ব্রাউজার তখন **দুইটার মধ্যে
         * কড়াটা** মানে — ফল হত অপ্রত্যাশিতভাবে ভাঙা পর্দা।
         */
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', implode('; ', self::POLICY));
        }

        return $response;
    }
}
