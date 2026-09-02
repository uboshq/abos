<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Security\CredentialCheck;
use App\Core\Security\MfaCodeRequired;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

/**
 * লগইন — প্ল্যান সেকশন ১৬।
 *
 * কোম্পানি এখানে জিজ্ঞেস করা হয় না। v9 স্পেসিফিকেশনে লগইনের আগে একটা
 * Workspace ড্রপডাউন ছিল, কিন্তু সেকশন ১৬.৩-এ ধরা পড়েছে সেটা Zero Trust-এর
 * সাথে যায় না: ড্রপডাউনে সব কোম্পানির নাম দেখানো মানে যে কেউ URL খুলেই
 * জেনে যাবে সার্ভারে কোন কোন প্রতিষ্ঠান আছে।
 *
 * তাই ব্যবহারকারী শুধু নিজের পরিচয় দেয়; কোম্পানি ঠিক হয় লগইনের পরে, তার
 * নিজের রেকর্ড থেকে (ResolveCompanyContext)।
 */
class LoginController extends Controller
{
    /**
     * এই ব্রাউজার থেকে আগে কেউ ঢুকেছে কি না।
     *
     * ── কেন কুকি, localStorage নয় ────────────────────────────────────
     * সিদ্ধান্তটা নিতে হয় **পাতা আঁকার আগে**। localStorage পড়া যায়
     * কেবল JavaScript চলার পরে, অর্থাৎ তখন ভুল পাতাটা এক ঝলক দেখা
     * যেত আর তারপর লাফিয়ে অন্যটায় যেত — যা ভাঙা মনে হয়।
     *
     * ── কেন সেশনে নয় ─────────────────────────────────────────────────
     * সেশন লগআউটে মুছে যায়, আর প্রশ্নটা ঠিক তার উল্টো: **এই যন্ত্রটা
     * ABOS চেনে কি না**। কাল সকালে আবার এসে তাঁকে আবার বিক্রির পাতা
     * দেখানোর কোনো মানে নেই।
     *
     * ── কী রাখা হয়, আর কী রাখা হয় না ────────────────────────────────
     * কেবল `1`। কে ঢুকেছিলেন, কোন কোম্পানি, কোন সময় — কিছুই নয়। এটা
     * একটা পছন্দ, পরিচয় নয়; আর যা পরিচয় নয় তা কুকিতে রাখলে একদিন
     * সেটা পরিচয় হয়ে ওঠে।
     */
    public const RETURNING = 'abos_returning';

    public function __construct(private readonly CredentialCheck $credentials) {}

    /**
     * পূর্ণ দরজা — প্রথমবারের জন্য।
     *
     * ── কে কোনটা দেখেন ──────────────────────────────────────────────
     * যে ব্রাউজার থেকে আগে কেউ ঢুকেছে, সে সোজা শান্ত দরজায় যায়। আটটা
     * বৈশিষ্ট্যের তালিকা পঞ্চাশতম দিনে আর কোনো খবর নয় — কেবল দুইটা ঘর
     * আর একটা বোতামের মাঝে দাঁড়ানো একটা দেয়াল।
     *
     * ── `?full` কেন লাগে ────────────────────────────────────────────
     * এটা ছাড়া পূর্ণ পাতাটায় **আর কোনোদিন পৌঁছানোই যেত না** — শান্ত
     * পাতা থেকে লিংক দিলে সেটা এখানে এসে আবার শান্ত পাতায় ফেরত পাঠাত,
     * অর্থাৎ একটা চক্র। আর যে পাতা দেখা যায় না, সেটা একদিন নীরবে
     * ভেঙে পড়ে থাকে।
     */
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->has('full') && $request->cookie(self::RETURNING) !== null) {
            return redirect()->route('login.calm');
        }

        return view('auth.login');
    }

    /**
     * দ্বিতীয় দরজা — শান্ত, এক কলাম।
     *
     * ── কেন একটাই কন্ট্রোলার, দুইটা পদ্ধতি ───────────────────────────
     * দুইটা দরজার **কাজ এক**: একই যাচাই, একই throttle, একই MFA, একই
     * `login_history`। আলাদা কন্ট্রোলার বানালে ওই নিয়মগুলোও দুই
     * জায়গায় থাকত, আর একদিন একটা বদলে অন্যটা থেকে যেত।
     *
     * পার্থক্যটা কেবল কোন ভিউ — অর্থাৎ পার্থক্যটা নকশার, ব্যবসার নয়।
     */
    public function calm(): View
    {
        return view('auth.signin');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        /*
         * যাচাইটা এখানে নেই — [[CredentialCheck]]-এ, আর সেটাই API-র
         * দরজাও ডাকে (২ সেপ্টেম্বর ২০২৬)।
         *
         * ── কেন সরানো হলো ───────────────────────────────────────────
         * নামের উপর তালা, ডামি হ্যাশ, MFA আর `login_history` — চারটাই
         * এখানে লেখা ছিল, আর ওয়েবে ঠিকঠাক কাজ করত। মোবাইলের দরজাটা
         * বানাতে গিয়ে দেখা গেল নকল করলে **আক্রমণকারী কেবল এই দরজাটা
         * ব্যবহার করা বন্ধ করে দিতেন** — দুইটার একটাতে তালা মানে তালা
         * নেই।
         *
         * throttle এখানেই থাকে, রুটে (`throttle:10,1`) — একটা সার্ভিস
         * সেটা বহন করতে পারে না।
         */
        try {
            $user = $this->credentials->verify(
                $credentials['identifier'],
                $credentials['password'],
                $request->input('code'),
            );
        } catch (MfaCodeRequired $needsCode) {
            /*
             * দ্বিতীয় ধাপের ঘরটা দেখানো — কোড বাকি, বা ভুল।
             *
             * ── কেন সার্ভিস এই উত্তরটা বানায় না ─────────────────────
             * `back()->withInput()` ব্রাউজারের আকার। সার্ভিসটা সেটা
             * ফেরত দিলে API-র উত্তরেও HTML-এর অর্থ ঢুকে যেত — একটা
             * redirect, JSON-এর ছদ্মবেশে। তাই সার্ভিস একটা **ঘটনা**
             * জানায়, আর প্রতিটা দরজা নিজের ভাষায় তার উত্তর দেয়।
             *
             * ⚠️ ভুল কোড তালা বাড়ায়, কোড না দেওয়া বাড়ায় না — পার্থক্যটা
             * [[LoginLock::locked()]]-এ, আর সেটা ইচ্ছাকৃত: কোড না দেওয়া
             * লগইনের স্বাভাবিক প্রথম ধাপ, প্রতিবারই ঘটে।
             */
            return back()
                ->withInput($request->only('identifier', 'remember'))
                ->with('mfa', true)
                ->withErrors(['code' => $needsCode->getMessage()]);
        }

        Auth::login($user, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate();

        /*
         * সফল ঢোকাটাও খাতায়।
         *
         * `last_login_at` কেবল **শেষ** বারটা রাখে — পরেরটা আগেরটাকে
         * ঢেকে দেয়। তাই "গত সপ্তাহে ইনি কবে কবে ঢুকেছিলেন" প্রশ্নের
         * উত্তর ওই ঘরটায় কোনোদিন ছিল না।
         */
        $this->credentials->recordSuccess($credentials['identifier'], $user);

        /*
         * এই যন্ত্রটা এখন ABOS চেনে — পরেরবার শান্ত দরজা।
         *
         * এক বছর, কারণ প্রশ্নটা "সাম্প্রতিক কি না" নয়, "কোনোদিন
         * ঢুকেছে কি না"। আর `httpOnly`: JavaScript-এর এটা পড়ার কোনো
         * কাজ নেই, তাই দেওয়াও হয়নি।
         */
        Cookie::queue(
            self::RETURNING, '1', 60 * 24 * 365,
            null, null, null, true, false, 'lax',
        );

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
