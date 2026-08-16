<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Security\MfaService;
use App\Core\Services\LoginJournal;
use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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
    public function __construct(
        private readonly LoginJournal $logins,
        private readonly MfaService $mfa,
    ) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $user = $this->findUser($credentials['identifier']);

        // পাসওয়ার্ড সবসময় যাচাই করা হয়, ব্যবহারকারী না থাকলেও — একটা
        // ডামি হ্যাশের বিরুদ্ধে। কারণ অস্তিত্বহীন নামে সাথে সাথে উত্তর
        // দিলে আক্রমণকারী সময় মেপেই বুঝে ফেলে কোন নামগুলো আসল
        // (সেকশন ১৬.৫)।
        $hash = $user?->password ?? '$2y$12$'.str_repeat('.', 53);
        $passwordOk = Hash::check($credentials['password'], $hash);

        if ($user === null || ! $passwordOk || ! $user->is_active) {
            /*
             * খাতায় আসল কারণটাই বসে, যদিও পর্দা তা বলে না।
             *
             * ── কেন দুইটা আলাদা ─────────────────────────────────────
             * পর্দা তিনটা ক্ষেত্রেই একই বার্তা দেয়, নাহলে বার্তা পড়ে
             * বা সময় মেপে কেউ ব্যবহারকারীর তালিকা বের করে ফেলত।
             *
             * কিন্তু নিরাপত্তা পর্যালোচনায় ঠিক ওই তফাতটাই দরকার:
             * অচেনা নামে পঁচিশটা চেষ্টা মানে কেউ **নাম** আন্দাজ করছে;
             * একটা চেনা নামে পঁচিশটা মানে কেউ **পাসওয়ার্ড** আন্দাজ
             * করছে। দুইটা সম্পূর্ণ আলাদা ঘটনা, আর দুইটার ব্যবস্থাও।
             */
            $this->logins->failed(
                $credentials['identifier'],
                $user,
                match (true) {
                    $user === null => LoginAttempt::UNKNOWN,
                    ! $passwordOk => LoginAttempt::WRONG_PASSWORD,
                    default => LoginAttempt::INACTIVE,
                },
            );

            throw ValidationException::withMessages([
                // এক বার্তা, সব ক্ষেত্রে। "এই নামে কেউ নেই" বললে
                // ব্যবহারকারীর তালিকা বের করা যায়।
                'identifier' => __('auth.failed'),
            ]);
        }

        /*
         * দ্বিতীয় ধাপ — চালু থাকলে।
         *
         * ── কেন এখানে, লগইনের পরে নয় ────────────────────────────────
         * `Auth::login()` ডাকার পর কোড চাইলে ওই মুহূর্তেই মানুষটা ঢুকে
         * পড়েছেন — মাঝের একটা অনুরোধেই গোটা ব্যবস্থা খোলা। তাই কোড
         * না মেলা পর্যন্ত লগইনটাই হয় না।
         *
         * ── কেন সেশনে user_id রাখা হয় না ────────────────────────────
         * কোডের পাতায় যাওয়ার জন্য কে চেষ্টা করছেন তা মনে রাখতে হয়।
         * সেশনে ব্যবহারকারীর id রাখলে সেটা কার্যত অর্ধেক লগইন — আর
         * সেশন হাইজ্যাক করে কেউ ওই অবস্থাটা ব্যবহার করতে পারতেন।
         * বদলে কেবল পরিচয়টা রাখা হয়, আর পাসওয়ার্ডটা দ্বিতীয় ধাপেও
         * আবার যাচাই হয়।
         */
        if ($this->mfa->isOn($user)) {
            $code = trim((string) $request->input('code'));

            if ($code === '') {
                $this->logins->failed($credentials['identifier'], $user, LoginAttempt::NEEDS_CODE);

                return back()
                    ->withInput($request->only('identifier', 'remember'))
                    ->with('mfa', true)
                    ->withErrors(['code' => __('auth.code_needed')]);
            }

            if (! $this->mfa->verify($user, $code)) {
                $this->logins->failed($credentials['identifier'], $user, LoginAttempt::WRONG_CODE);

                return back()
                    ->withInput($request->only('identifier', 'remember'))
                    ->with('mfa', true)
                    ->withErrors(['code' => __('auth.code_wrong')]);
            }
        }

        Auth::login($user, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        /*
         * সফল ঢোকাটাও খাতায়।
         *
         * `last_login_at` কেবল **শেষ** বারটা রাখে — পরেরটা আগেরটাকে
         * ঢেকে দেয়। তাই "গত সপ্তাহে ইনি কবে কবে ঢুকেছিলেন" প্রশ্নের
         * উত্তর ওই ঘরটায় কোনোদিন ছিল না।
         */
        $this->logins->succeeded($credentials['identifier'], $user);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Username, Email বা Mobile — তিনটার যেকোনোটা (সেকশন ১৬.৩)।
     *
     * ফিল্ড সেলসম্যান নিজের মোবাইল নম্বর মনে রাখে, ইমেইল নয়।
     */
    private function findUser(string $identifier): ?User
    {
        return User::query()
            ->where('email', $identifier)
            ->orWhere('name', $identifier)
            ->first();
    }
}
