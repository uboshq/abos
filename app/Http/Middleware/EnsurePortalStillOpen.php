<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * চাবি ফেরত নেওয়ার সাথে সাথেই দরজা বন্ধ।
 *
 * ── কেন `auth:portal` একা যথেষ্ট নয় ─────────────────────────────────
 * ওই মিডলওয়্যার একটাই প্রশ্ন করে: "ইনি কি ঢুকেছিলেন?" — উত্তরটা আসে
 * সেশন থেকে, ডাটাবেজ থেকে নয়। লগইনের সময় `portal_enabled` মেলানো হয়,
 * কিন্তু তারপর আর কেউ মেলায় না।
 *
 * ফলে পোর্টাল বন্ধ করার পরেও যিনি আগে থেকে ঢুকে আছেন তিনি সেশন শেষ না
 * হওয়া পর্যন্ত ভেতরে থেকে যেতেন। ঠিক যে মুহূর্তে বন্ধ করাটা সবচেয়ে
 * জরুরি — ডিলারের সাথে সম্পর্ক ছিন্ন, বা পাসওয়ার্ড ফাঁস — সেই
 * মুহূর্তেই বোতামটা কিছু করত না, অথচ পর্দা বলত কাজ হয়ে গেছে।
 *
 * ── সেশনটা মুছে ফেলা হয়, কেবল ৪০৩ নয় ───────────────────────────────
 * ৪০৩ দিলে ব্রাউজারে কুকিটা থেকে যেত আর প্রতিটা ক্লিকে একই পাতা আসত।
 * বের করে দিয়ে লগইনে পাঠালে ডিলার একটা বোধগম্য পর্দা দেখেন — আর
 * বার্তাটা বলে ব্যবস্থাটা ভাঙেনি, তাঁর দরজাটা বন্ধ হয়েছে।
 */
class EnsurePortalStillOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $dealer = Auth::guard('portal')->user();

        /*
         * `withoutGlobalScopes()` নয়, `fresh()` নয় — সরাসরি কলামটা।
         *
         * গার্ডের বসানো মডেলটা এই অনুরোধের শুরুতেই ডাটাবেজ থেকে পড়া,
         * তাই মানটা এই মুহূর্তের। আরেকবার পড়লে প্রতিটা অনুরোধে একটা
         * বাড়তি কোয়েরি যেত, কিছু নতুন না জেনে।
         */
        if ($dealer !== null && ! $dealer->portal_enabled) {
            Auth::guard('portal')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('sales.portal.login')
                ->withErrors(['code' => __('sales::portal.closed')]);
        }

        return $next($request);
    }
}
