<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Services\LoginJournal;
use App\Core\Services\WebInsideApp;
use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\WebHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * `GET /handoff/{token}` — অ্যাপের ভিতরের ওয়েব-জানালা একবারের চাবি দিয়ে ঢোকে (মালিক, ১৩ অক্টোবর ২০২৬)।
 *
 * ── ⛔ চাবি খরচের নিয়ম ───────────────────────────────────────────────────
 *   ⭐ আগে খরচ, তারপর যাচাই: একটা `UPDATE … WHERE consumed_at IS NULL AND expires_at > now` — দুইটা অনুরোধ একসাথে এলে
 *     কেবল একটা জেতে; আর ভুল হ্যান্ডসেট থেকে আসা চেষ্টাও চাবিটা পুড়িয়ে দেয় — ফাঁস হওয়া লিংক দ্বিতীয়বার চলে না।
 *   ⭐ হ্যান্ডসেট: অ্যাপ জানালা খোলার সময় হেডারে নিজের ডিভাইস-আইডি পাঠায় (`X-ABOS-Device`); চাবিতে তার ছাপ বাঁধা।
 *   ⭐ মানুষ এখনো সচল, কোম্পানির সদস্য, শাখা তাঁর নাগালে ([[User::switchCompany()]] আবার দেখে), কোম্পানির সুইচ চালু,
 *     দুই ধাপ বাধ্যতামূলক অথচ বসানো নেই — এমন নয়।
 *   ⭐ আগের যেকোনো সেশন বাতিল, নতুন সেশন (session fixation নয়), সেশনে "অ্যাপের ভিতরে" দাগ ([[WebInsideApp::SESSION]])।
 *   ⭐ ঢোকা আর ফেরানো দুটোই লগইনের খাতায় (`app-web:` নামে) — বাকি সব দরজার মতো।
 *
 * ⓘ রুটে লগইন নেই — চাবিটাই চাবি, ইমেইল-নিশ্চিতকরণের লিংকের মতো ([[EveryRouteIsGuardedTest]]-এ কারণসহ); `throttle:20,1`।
 */
class WebHandoffController extends Controller
{
    public const DEVICE_HEADER = 'X-ABOS-Device';

    public function __construct(
        private readonly WebInsideApp $inside,
        private readonly LoginJournal $logins,
    ) {}

    public function __invoke(Request $request, string $token): RedirectResponse|Response
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return $this->refuse(null);
        }

        $hash = hash('sha256', $token);

        $claimed = WebHandoff::query()
            ->where('token_hash', $hash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now(), 'consumed_ip' => $request->ip()]);

        $handoff = WebHandoff::query()->where('token_hash', $hash)->first();
        $user = $handoff === null ? null : User::query()->find($handoff->user_id);

        if ($claimed !== 1 || $handoff === null || ! $user instanceof User) {
            return $this->refuse($user);
        }

        if (! hash_equals((string) $handoff->device_hash, hash('sha256', (string) $request->header(self::DEVICE_HEADER, '')))) {
            return $this->refuse($user);
        }

        if (! $user->is_active) {
            return $this->refuse($user);
        }

        try {
            // ⛔ সদস্যপদ আর শাখার নাগাল আবার — চাবি বানানোর পরে বাদ পড়লে ঢোকা নয়; প্রসঙ্গও এখানেই বসে
            $user->switchCompany((int) $handoff->company_id, $handoff->branch_id === null ? null : (int) $handoff->branch_id);
        } catch (ValidationException) {
            return $this->refuse($user);
        }

        $user->refresh();

        if (! $this->inside->isOn() || $this->inside->twoStepMissing($user)
            || $this->inside->target($handoff->target, $user) === null) {
            return $this->refuse($user);
        }

        // ⓘ এই জানালায় আগে কেউ থাকলে (আগের মানুষ, বা আগের সেশন) — বেরিয়ে, নতুন সেশন
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        $request->session()->invalidate();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(WebInsideApp::SESSION, true);

        $this->logins->succeeded(WebInsideApp::journalName($user), $user);

        return redirect()->to((string) $handoff->target);
    }

    /**
     * ফেরানো — কারণ পর্দায় বলা হয় না (চাবি অচল, পুরনো, অন্য ফোনের — সবই এক কথা), খাতায় লেখা থাকে।
     */
    private function refuse(?User $user): Response
    {
        app()->setLocale($user?->locale ?? 'bn');

        $this->logins->failed(WebInsideApp::journalName($user), $user, LoginAttempt::HANDOFF_REFUSED);

        return response()->view('errors.layout', [
            'code' => '403',
            'title' => __('mobile.web_handoff_refused_title'),
            'body' => __('mobile.web_handoff_refused'),
        ], 403);
    }
}
