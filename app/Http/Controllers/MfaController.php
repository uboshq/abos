<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Security\MfaService;
use App\Core\Security\Totp;
use App\Core\Services\MenuBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * দুই ধাপের লগইন — নিজের অ্যাকাউন্টে।
 *
 * ── কেন এটা নিজের পর্দা, প্রশাসনিক নয় ───────────────────────────────
 * নিজের অ্যাকাউন্টে দ্বিতীয় তালা বসানো নিজের সিদ্ধান্ত, প্রোফাইল বা
 * চেহারার মতোই। প্রশাসকের হাতে দিলে সেটা "চালু করে দেওয়া" হত, আর যাঁর
 * ফোনে অ্যাপ নেই তিনি নিজের ব্যবস্থা থেকে বাইরে থাকতেন।
 */
class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly MenuBuilder $menu,
    ) {}

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('auth.mfa', [
            'menu' => $this->menu->forUser($user),
            'on' => $this->mfa->isOn($user),
            'codesLeft' => $this->mfa->recoveryCodesLeft($user),

            /*
             * চাবিটা কেবল তখনই, যখন বসানোর কাজ চলছে।
             *
             * চালু হয়ে যাওয়ার পর আর দেখানো হয় না — একবার বসে গেলে
             * চাবিটা দেখার আর কোনো কারণ নেই, আর পর্দায় খোলা রাখলে
             * কাঁধের উপর দিয়ে কেউ দেখে ফেলতে পারতেন।
             */
            'secret' => $this->mfa->isOn($user) ? null : $user->mfa_secret,

            'uri' => $this->mfa->isOn($user) || ! $user->mfa_secret ? null : Totp::uri(
                $user->mfa_secret,
                $user->email ?: $user->name,
                config('app.name'),
            ),
        ]);
    }

    /** চাবি বসানো — কিন্তু চালু নয়, প্রথম কোড না মেলা পর্যন্ত। */
    public function begin(Request $request): RedirectResponse
    {
        $this->mfa->begin($request->user());

        return back();
    }

    /** প্রথম কোডটা মিলল — এখন সত্যিই চালু, আর পুনরুদ্ধার কোড দেখানো হয়। */
    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        $codes = $this->mfa->confirm($request->user(), $data['code']);

        if ($codes === null) {
            return back()->withErrors(['code' => __('auth.code_wrong')]);
        }

        /*
         * কোডগুলো সেশনে, একবারের জন্য।
         *
         * ডাটাবেজে কেবল হ্যাশ বসে, তাই এই একবারই সেগুলো দেখানো যায়।
         * সেশনে রাখা মানে পাতাটা রিফ্রেশ করলে হারিয়ে যায় — আর সেটাই
         * ঠিক, কারণ পর্দায় চিরকাল ঝুলে থাকা পুনরুদ্ধার কোড কাঁধের
         * উপর দিয়ে যে কেউ পড়ে নিতে পারতেন।
         */
        return back()->with('recovery_codes', $codes);
    }

    /**
     * বন্ধ করা — পাসওয়ার্ড দিয়ে।
     *
     * ── কেন পাসওয়ার্ড আবার চাওয়া হয় ────────────────────────────────
     * কেউ খোলা কম্পিউটারের সামনে বসে পড়লে এক ক্লিকেই দ্বিতীয় তালাটা
     * খুলে ফেলতে পারতেন — আর তারপর আর কিছুই আটকাত না। MFA বন্ধ করাটা
     * MFA পেরোনোর সমান ক্ষমতা, তাই একই রকম প্রমাণ লাগে।
     */
    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            return back()->withErrors(['password' => __('auth.password_wrong')]);
        }

        $this->mfa->turnOff($request->user());

        return back()->with('status', __('auth.mfa_off'));
    }
}
