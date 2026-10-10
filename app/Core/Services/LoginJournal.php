<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Models\LoginAttempt;
use App\Models\User;

/**
 * ঢোকার খাতা — সফল ও ব্যর্থ, দুইটাই।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * ABOS-এ ছিল `users.last_login_at` — একটাই সংখ্যা, আর সেটা কেবল শেষ
 * সফল ঢোকার সময়। পরেরটা আগেরটাকে ঢেকে দেয়, তাই "গত সপ্তাহে ইনি কবে
 * কবে ঢুকেছিলেন" প্রশ্নের উত্তর কোনোদিন ছিল না। আর ব্যর্থ চেষ্টা?
 * একটাও কোথাও লেখা হত না।
 *
 * ফল: অডিট বলতে পারে কোন বিলে ছাড় বসেছে আর কে বসিয়েছে, কিন্তু সেই
 * লোকটা আদৌ ঢুকেছিল কি না, কোথা থেকে — কিছুই বলতে পারে না।
 *
 * ── খাতাটা নীরবে ব্যর্থ হয় ──────────────────────────────────────────
 * খাতা লিখতে গিয়ে কিছু ভাঙলে লগইন আটকানো হয় না। রপ্তানির খাতার একই
 * যুক্তি: খাতার জন্য কারও কাজ থামানো ভুল বিনিময়। তবু `report()` দিয়ে
 * লগে যায়, তাই ঘটলে খুঁজে বের করা যায়।
 *
 * এখানে আরও একটা কারণ আছে: খাতা ভাঙলে যদি লগইনই আটকে যেত, তবে সেটা
 * পুরো ব্যবস্থায় ঢোকার একমাত্র দরজা বন্ধ করে দিত — আর যিনি সারাবেন
 * তিনিও ঢুকতে পারতেন না।
 */
class LoginJournal
{
    public function succeeded(string $identifier, User $user): ?LoginAttempt
    {
        return $this->write($identifier, $user, true, null);
    }

    public function failed(string $identifier, ?User $user, string $reason): ?LoginAttempt
    {
        return $this->write($identifier, $user, false, $reason);
    }

    /**
     * সফল ঢোকা — যিনি `User` নন (পোর্টালের ডিলার), তাঁর কোম্পানি হাতে দিয়ে।
     *
     * ── ⭐ কেন আলাদা পদ্ধতি, ২৭ সেপ্টেম্বর ২০২৬ ──────────────────────────
     * পোর্টাল এখন কর্মীর দরজার সেই একই [[LoginLock]] মানে, আর তালাটা গোনে
     * "শেষ সফল লগইনের পর থেকে" — সফলটা না লিখলে ডিলারের গোনা কখনো শূন্যে
     * ফিরত না। ⚠️ [[succeeded()]] একটা `User` চায়, আর `login_history.user_id`
     * কেবল `users`-এর দিকে তাকায়; তাই সারিটা `user_id` ছাড়া, কোম্পানি
     * ডাকার জায়গা থেকে।
     *
     * ⓘ হাতে লেখা দ্বিতীয় একটা `create()` নয় — একই [[write()]], যাতে নাম
     * ছাঁটা, IP আর নীরবে-ব্যর্থ হওয়ার নিয়ম দুই জায়গায় দুই রকম না হয়।
     */
    public function succeededFor(string $identifier, ?int $companyId): ?LoginAttempt
    {
        return $this->write($identifier, null, true, null, $companyId);
    }

    /**
     * ⛔ অচেনা নাম খাতায় ঢাকা — ৩০ সেপ্টেম্বরের নিরীক্ষা (Core #login-identifier-raw), ১০ অক্টোবর ২০২৬।
     *
     * ⚠️ নামের ঘরে কেউ ভুলে পাসওয়ার্ড টাইপ করলে কোনো ব্যবহারকারী মেলে না — আর ঠিক সেই সারিটাই আগে
     * কাঁচা বসত। ⓘ তাই অচেনা নামের প্রথম দুই অক্ষর আর একটা চাবি-বাঁধা ছাপ: পড়া যায় না, তবু একই
     * নামে বারবার চেষ্টা একই ছাপে পড়ে, তাই [[LoginLock]] আগের মতোই গোনে।
     */
    public static function unknownKey(string $identifier): string
    {
        $normal = mb_strtolower(trim($identifier));

        // ⓘ পোর্টালের দরজা নিজের নামে লেখে (`portal:…`) — দরজার নামটা গোপন নয়, তাই থাকে; ঢাকা হয় তার পরেরটুকু
        $door = str_starts_with($normal, 'portal:') ? 'portal:' : '';
        $typed = mb_substr($normal, mb_strlen($door));

        return $door.mb_substr($typed, 0, 2).'…#'.substr(hash_hmac('sha256', $normal, (string) config('app.key')), 0, 12);
    }

    private function write(string $identifier, ?User $user, bool $succeeded, ?string $reason, ?int $companyId = null): ?LoginAttempt
    {
        try {
            /*
             * ⭐ কোথা থেকে — ব্রাউজার লোকেশন দিলে (মালিক, ১ অক্টোবর ২০২৬, [[LoginPlace]])। ⓘ না দিলে ঘরগুলো
             * পাঠানোই হয় না; নামটা উত্তর পাঠানোর পরে বসে, যাতে লগইন বাইরের সেবার জন্য অপেক্ষা না করে।
             */
            $where = app(LoginPlace::class)->fromRequest(request());

            $row = LoginAttempt::create([...$where, ...[
                /*
                 * কোম্পানিটা ব্যবহারকারীর নিজের, `CompanyContext` থেকে নয়।
                 *
                 * লগইনের মুহূর্তে কোনো কোম্পানি বাছা হয়নি — সেটা হয়
                 * ঢোকার পরে। কনটেক্সট থেকে নিলে প্রতিটা সারিতে খালি
                 * বসত, আর কোম্পানি ধরে ছাঁকা যেত না।
                 */
                'company_id' => $user?->current_company_id ?? $companyId,
                'user_id' => $user?->getKey(),

                /*
                 * যা টাইপ করা হয়েছিল — ১৯১ অক্ষরে ছাঁটা।
                 *
                 * কেউ ঘরটায় দশ হাজার অক্ষর পাঠালে সেটা যেন খাতা লেখার
                 * সময় ভেঙে না পড়ে। ⛔ অচেনা নাম ঢাকা ([[unknownKey()]]):
                 * মানুষ প্রায়ই আসল পাসওয়ার্ড ভুল ঘরে টাইপ করে, আর তখন
                 * খাতাটাই পাসওয়ার্ডের তালিকা হয়ে যেত।
                 */
                'identifier' => $reason === LoginAttempt::UNKNOWN
                    ? self::unknownKey($identifier)
                    : mb_substr(trim($identifier), 0, 191),

                'succeeded' => $succeeded,
                'reason' => $reason,
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
            ]]);

            if ($where !== []) {
                $id = (int) $row->getKey();
                dispatch(fn () => app(LoginPlace::class)->fill($id))->afterResponse();
            }

            return $row;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
