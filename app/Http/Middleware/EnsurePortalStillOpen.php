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
 * জরুরি — গ্রাহকের সাথে সম্পর্ক ছিন্ন, বা পাসওয়ার্ড ফাঁস — সেই
 * মুহূর্তেই বোতামটা কিছু করত না, অথচ পর্দা বলত কাজ হয়ে গেছে।
 *
 * ── সেশনটা মুছে ফেলা হয়, কেবল ৪০৩ নয় ───────────────────────────────
 * ৪০৩ দিলে ব্রাউজারে কুকিটা থেকে যেত আর প্রতিটা ক্লিকে একই পাতা আসত।
 * বের করে দিয়ে লগইনে পাঠালে গ্রাহক একটা বোধগম্য পর্দা দেখেন — আর
 * বার্তাটা বলে ব্যবস্থাটা ভাঙেনি, তাঁর দরজাটা বন্ধ হয়েছে।
 */
class EnsurePortalStillOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::guard('portal')->user();

        /*
         * `withoutGlobalScopes()` নয়, `fresh()` নয় — সরাসরি কলামটা।
         *
         * গার্ডের বসানো মডেলটা এই অনুরোধের শুরুতেই ডাটাবেজ থেকে পড়া,
         * তাই মানটা এই মুহূর্তের। আরেকবার পড়লে প্রতিটা অনুরোধে একটা
         * বাড়তি কোয়েরি যেত, কিছু নতুন না জেনে।
         */
        // ⛔ নিষ্ক্রিয় বা মুছে ফেলা গ্রাহকও — দরজা বন্ধের একই মানে (পুনঃঅডিট ৯ অক্টোবর ২০২৬, গ্রাহক ১৫)
        if ($customer !== null && (! $customer->portal_enabled || ! $customer->is_active
            || (method_exists($customer, 'trashed') && $customer->trashed())
            || $this->passwordChangedSince($request, $customer))) {
            Auth::guard('portal')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('sales.portal.login')
                ->withErrors(['code' => __('sales::portal.closed')]);
        }

        return $next($request);
    }

    /** সেশনে এই গ্রাহকের পাসওয়ার্ডের ছাপ — কর্মীদের দরজার জোড়া ([[RefuseInactiveAccounts::FINGERPRINT]]) */
    public const FINGERPRINT = 'portal.password_fingerprint';

    /** ঢোকার মুহূর্তে বসানো — [[PortalController::login()]] */
    public static function remember(Request $request, \Illuminate\Contracts\Auth\Authenticatable $customer): void
    {
        $request->session()->put(self::FINGERPRINT, self::fingerprintOf($customer));
    }

    /**
     * ⛔ সেশন খোলার পরে পাসওয়ার্ড বদলেছে কি না (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[ANewPortalPasswordLeftTheOldSessionInTest]])।
     *
     * ⚠️ পাসওয়ার্ড ফাঁস হলে মালিক নতুন পাসওয়ার্ড বসান, পর্দা বলে "পুরনোটা আর চলবে না" — অথচ যিনি পুরনোটা দিয়ে আগেই ঢুকে
     * আছেন তিনি সেশন শেষ না হওয়া পর্যন্ত খাতা, বকেয়া দেখতেন আর আদেশ দিতেন। ⓘ সেশন ড্রাইভার `file`, তাই অন্যের সেশন মোছা
     * যায় না — প্রতিটা সেশন নিজের মধ্যে পাসওয়ার্ড-হ্যাশের HMAC রাখে, পরের অনুরোধে মেলায়। ⓘ ছাপ না থাকা পুরনো সেশন প্রথমবার ছাপ
     * পায় (সবাইকে একসাথে বের করে না)।
     */
    private function passwordChangedSince(Request $request, \Illuminate\Contracts\Auth\Authenticatable $customer): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $seen = $request->session()->get(self::FINGERPRINT);

        if (! is_string($seen)) {
            self::remember($request, $customer);

            return false;
        }

        return ! hash_equals($seen, self::fingerprintOf($customer));
    }

    private static function fingerprintOf(\Illuminate\Contracts\Auth\Authenticatable $customer): string
    {
        return hash_hmac('sha256', $customer->getAuthIdentifier().'|'.$customer->getAuthPassword(), (string) config('app.key'));
    }
}
