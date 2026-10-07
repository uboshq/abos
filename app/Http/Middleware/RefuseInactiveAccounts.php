<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⛔ বিদায় নেওয়া হাতে চাবি থাকে না — নিরীক্ষা §১.৫, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `is_active` দেখা হত **কেবল লগইনের মুহূর্তে** ([[CredentialCheck]])।
 * তারপর খোলা সেশন চলতেই থাকত, "মনে রাখুন" কুকি পাসওয়ার্ড বদলের পরেও
 * খুলত, আর ফোনের refresh টোকেন প্রতিবার নতুন ৩০ দিনের জোড়া দিত।
 * ⚠️ বরখাস্ত একজন বিক্রয়কর্মী ফোনটা চালু রাখলে **চিরকাল** ভেতরে থাকতেন।
 *
 * ── কী করে ──────────────────────────────────────────────────────────
 * প্রতিটা অনুরোধে, ওয়েব ও API দুই গ্রুপেই:
 *
 *     মানুষটা আর সক্রিয় নন (বা সারিটাই নেই)
 *         ওয়েব → সেশন শেষ, লগইন পর্দায় বাংলা বার্তা (JSON চাইলে ৪০১)
 *         API  → ৪০১, আর তাঁর সব টোকেন মুছে যায়
 *
 *     ওয়েবে: এই সেশন খোলার পর পাসওয়ার্ড অন্য কোথাও বদলেছে
 *         → সেশন শেষ
 *
 * ── ⚠️ কেন পাসওয়ার্ডের ছাপটা এখানে, সেশন মুছে নয় ─────────────────────
 * সেশন ড্রাইভার `file` (লাইভ ও লোকাল), তাই একজনের **অন্য** সেশনগুলো
 * খুঁজে বের করে মোছার কোনো উপায় নেই — ফাইলগুলোয় কার সেশন তা লেখা
 * থাকে না। ⓘ তাই প্রতিটা সেশন নিজের মধ্যে পাসওয়ার্ড-হ্যাশের একটা HMAC
 * রাখে, আর পরের অনুরোধে মিলিয়ে দেখে। ড্রাইভার যা-ই হোক, কাজ করে।
 * যে সেশন থেকে নিজে পাসওয়ার্ড বদলানো হলো সেটা থাকে
 * ([[keepThisSession()]])।
 *
 * ── ⚠️ কেন ডাটাবেজ থেকে টাটকা পড়া ─────────────────────────────────
 * গার্ডের হাতে থাকা বস্তুটা পুরনো হতে পারে (টেস্টে একই অ্যাপ বহু
 * অনুরোধ চালায়; Octane-এও তাই)। একটা প্রাইমারি-কী কোয়েরি সস্তা, আর
 * পুরনো বস্তু বিশ্বাস করলে পাহারাটা ঠিক সেই মুহূর্তে অন্ধ হত যখন দরকার।
 */
final class RefuseInactiveAccounts
{
    /** সেশনে এই মানুষের পাসওয়ার্ডের ছাপ — কার, আর কোনটা। */
    public const FINGERPRINT = 'auth.password_fingerprint';

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * ⓘ সেশন থাকলে ওয়েব গ্রুপ, না থাকলে API — একই ক্লাস দুই গ্রুপে।
         */
        if ($request->hasSession()) {
            $user = Auth::guard('web')->user();

            if ($user instanceof User && ($refusal = $this->checkWeb($request, $user)) !== null) {
                return $refusal;
            }

            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();

        if ($user instanceof User && ! $this->stillActive($user)) {
            self::revokeStandingAccess($user);

            return response()->json(['message' => __('auth.dismissed')], 401);
        }

        return $next($request);
    }

    /**
     * একজনের হাতে থাকা সব টেকসই চাবি বাতিল — নিষ্ক্রিয় করা, পাসওয়ার্ড
     * বদল ও রিসেটের পথগুলো এটাই ডাকে।
     *
     *     remember_token ঘোরানো   → পুরনো "মনে রাখুন" কুকি আর খোলে না
     *     Sanctum টোকেন মোছা      → ফোনের access ও refresh দুইটাই মরে
     *
     * ⓘ খোলা ওয়েব সেশনগুলো এখানে মোছা হয় না (ড্রাইভার `file`, গোনা যায়
     * না) — সেগুলো পরের অনুরোধেই [[handle()]]-এ কাটা পড়ে।
     *
     * ⚠️ কোয়েরি দিয়ে লেখা, `save()` দিয়ে নয়: হাতের বস্তুটা পুরনো হলে
     * `save()` অন্য ঘরও ফিরিয়ে লিখতে পারত।
     */
    public static function revokeStandingAccess(User $user): void
    {
        $token = Str::random(60);

        User::query()->whereKey($user->getKey())->update(['remember_token' => $token]);
        $user->setRememberToken($token);
        $user->syncOriginalAttribute('remember_token');

        $user->tokens()->delete();
    }

    /**
     * যে সেশন থেকে পাসওয়ার্ডটা বদলানো হলো, সেটা খোলা থাকে।
     */
    public static function keepThisSession(Request $request, User $user): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::FINGERPRINT, self::fingerprintOf($user->getKey(), (string) $user->getAuthPassword()));
        }
    }

    private function checkWeb(Request $request, User $user): ?Response
    {
        $row = User::query()->whereKey($user->getKey())->first(['id', 'is_active', 'password']);

        if ($row === null || ! $row->is_active) {
            self::revokeStandingAccess($user);

            return $this->logOut($request, __('auth.dismissed'));
        }

        $now = self::fingerprintOf($row->getKey(), (string) $row->password);
        $seen = $request->session()->get(self::FINGERPRINT);

        /*
         * ⓘ ছাপটা মানুষ ধরে: অন্য কারও ছাপ থাকলে (একই ব্রাউজারে আরেকজন)
         * সেটা তুলনা নয়, নতুন করে বসানো।
         */
        if (is_array($seen)
            && ($seen['id'] ?? null) === $now['id']
            && ! hash_equals((string) ($seen['hash'] ?? ''), $now['hash'])) {
            return $this->logOut($request, __('auth.password_changed_elsewhere'));
        }

        if ($seen !== $now) {
            $request->session()->put(self::FINGERPRINT, $now);
        }

        return null;
    }

    private function stillActive(User $user): bool
    {
        return (bool) User::query()->whereKey($user->getKey())->value('is_active');
    }

    private function logOut(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->withErrors(['identifier' => $message]);
    }

    /**
     * @return array{id: int|string, hash: string}
     */
    private static function fingerprintOf(int|string $id, string $passwordHash): array
    {
        return [
            'id' => $id,
            'hash' => hash_hmac('sha256', $passwordHash, (string) config('app.key')),
        ];
    }
}
