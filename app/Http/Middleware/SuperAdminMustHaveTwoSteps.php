<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Security\MfaService;
use App\Core\Services\PermissionSyncer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * সুপার অ্যাডমিনের দুই ধাপ বাধ্যতামূলক — বসানোর পর্দাটা ছাড়া আর কিছুই খোলে না।
 *
 * ── ⭐ কেন, নিরীক্ষা §৩ ───────────────────────────────────────────────
 * সুপার অ্যাডমিনের হাতে গোটা ব্যবস্থা: প্রতিটা কোম্পানি, প্রতিটা টাকার
 * ঘর, প্রতিটা ব্যবহারকারী। ⚠️ ঐ একটা পাসওয়ার্ড ফাঁস হলে আর কোনো
 * দ্বিতীয় দরজা নেই।
 *
 * ⓘ দুই ধাপের যন্ত্র আগেই ছিল ([[MfaService]], [[Totp]], একবার-ব্যবহারের
 * উদ্ধার-কোড), কিন্তু চালু করা ছিল **ঐচ্ছিক** — অর্থাৎ যিনি সবচেয়ে
 * বেশি ঝুঁকিতে, তিনিই সেটা এড়িয়ে যেতে পারতেন।
 *
 * ── ⛔ লক-আউটই এখানকার আসল বিপদ, রোগটা নয় ─────────────────────────────
 * ⚠️ একটা বাধ্যতামূলক তালা ভুল হলে ক্ষতিটা তাৎক্ষণিক আর সম্পূর্ণ:
 * মালিক নিজের ব্যবসায় ঢুকতে পারেন না। ⓘ তাই এখানে তিনটা দরজা খোলা
 * রাখা হয়েছে, আর প্রত্যেকটার নিজের কারণ আছে:
 *
 *   ⭐ বসানোর পর্দা নিজেই — নাহলে চালু করার পথটাই বন্ধ হত, আর
 *     মিডলওয়্যারটা নিজের সাথে নিজে লড়ত (অসীম রিডাইরেক্ট)।
 *   ⭐ লগ-আউট — আটকে থাকা মানুষ অন্তত বেরোতে পারেন।
 *   ⭐ যা লগইনের আগের পথ (auth ছাড়া অনুরোধ) — এখানে কিছুই করার নেই।
 *
 * ⓘ আর ফোন হারালে: প্রথমে উদ্ধার-কোড; সেগুলোও শেষ হলে অন্য সুপার
 * অ্যাডমিন রিসেট করেন; আর একজনই সুপার অ্যাডমিন হলে সার্ভারে
 * `php artisan abos:two-step-reset` — তিনটাই নিরীক্ষার খাতায় দাগ রাখে।
 *
 * ── ⓘ কেন কেবল সুপার অ্যাডমিন ────────────────────────────────────────
 * ⛔ সবার জন্য বাধ্যতামূলক করলে যে গুদাম-কর্মীর ফোনেই অ্যাপ নেই, তিনি
 * কাজ করতে পারতেন না — আর ব্যবস্থাটা একদিনেই বন্ধ হয়ে যেত। ⭐ তাঁদের
 * জন্য এটা ঐচ্ছিকই থাকে, ঠিক আগের মতো।
 */
final class SuperAdminMustHaveTwoSteps
{
    /**
     * যে পথগুলো খোলা থাকে, নাম ধরে।
     *
     * ⛔ ঠিকানার উপসর্গ ধরে নয়, **রুটের নাম** ধরে। ⚠️ `/two-step` উপসর্গ
     * দেখলে ভবিষ্যতে কেউ ঐ উপসর্গে অন্য কিছু বসালে সেটাও নীরবে খুলে
     * যেত, আর তালাটায় একটা ফুটো তৈরি হত কেউ না জেনেই।
     *
     * @var list<string>
     */
    private const OPEN = ['mfa', 'mfa.begin', 'mfa.confirm', 'logout'];

    public function __construct(private readonly MfaService $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE)) {
            return $next($request);
        }

        if ($this->mfa->isOn($user) || in_array($request->route()?->getName(), self::OPEN, true)) {
            return $next($request);
        }

        /*
         * ⓘ API আর fetch-এর জন্য রিডাইরেক্ট নয়, ৪০৩ — একটা JSON
         * অনুরোধে লগইনের HTML ফেরত গেলে পর্দা সেটাকে **সফল** ধরে নিত।
         * ⚠️ এই ঘরে ঐ ভুলটা আগেও হয়েছে।
         */
        if ($request->expectsJson()) {
            return response()->json(['message' => __('auth.two_step_required')], 403);
        }

        return redirect()->route('mfa')->with('status', __('auth.two_step_required'));
    }
}
