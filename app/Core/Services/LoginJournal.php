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

    private function write(string $identifier, ?User $user, bool $succeeded, ?string $reason): ?LoginAttempt
    {
        try {
            return LoginAttempt::create([
                /*
                 * কোম্পানিটা ব্যবহারকারীর নিজের, `CompanyContext` থেকে নয়।
                 *
                 * লগইনের মুহূর্তে কোনো কোম্পানি বাছা হয়নি — সেটা হয়
                 * ঢোকার পরে। কনটেক্সট থেকে নিলে প্রতিটা সারিতে খালি
                 * বসত, আর কোম্পানি ধরে ছাঁকা যেত না।
                 */
                'company_id' => $user?->current_company_id,
                'user_id' => $user?->getKey(),

                /*
                 * যা টাইপ করা হয়েছিল — ১৯১ অক্ষরে ছাঁটা।
                 *
                 * কেউ ঘরটায় দশ হাজার অক্ষর পাঠালে সেটা যেন খাতা লেখার
                 * সময় ভেঙে না পড়ে। পাসওয়ার্ড কখনো এখানে বসে না, এমনকি
                 * ভুলটাও নয়: মানুষ প্রায়ই আসল পাসওয়ার্ড ভুল ঘরে টাইপ
                 * করে, আর তখন খাতাটাই পাসওয়ার্ডের তালিকা হয়ে যেত।
                 */
                'identifier' => mb_substr(trim($identifier), 0, 191),

                'succeeded' => $succeeded,
                'reason' => $reason,
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
