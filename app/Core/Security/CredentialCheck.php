<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Services\LoginJournal;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * "এই পরিচয় ও পাসওয়ার্ড কি সত্যি" — এক জায়গায়, সব দরজার জন্য।
 *
 * ── কেন এটা আলাদা করা হলো, ২ সেপ্টেম্বর ২০২৬ ───────────────────────
 * যাচাইটা [[LoginController::store()]]-এর ভেতরে ছিল, আর ওয়েবে সেটা
 * ঠিকঠাক কাজ করত। তারপর মোবাইলের `POST /api/v1/auth/login` লাগল।
 *
 * **নকল করলে যা হত, আর এটাই এই ফাইলের অস্তিত্বের কারণ:** তালা, throttle
 * আর `login_history` তিনটাই বসানো ছিল ওয়েবের দরজায়। দ্বিতীয় দরজাটা
 * ওগুলো ছাড়া বানালে **আক্রমণকারী কেবল প্রথম দরজাটা ব্যবহার করা বন্ধ
 * করে দিতেন**। দুইটার একটাতে তালা মানে তালা নেই।
 *
 * আর খাতাটাও অন্ধ হত: `UNKNOWN` আর `WRONG_PASSWORD` আলাদা করে লেখার
 * পুরো কারণটাই হলো "কেউ নাম আন্দাজ করছে" আর "কেউ পাসওয়ার্ড আন্দাজ
 * করছে" — দুইটা সম্পূর্ণ আলাদা ঘটনা — আলাদা করে চেনা। API কিছু না
 * লিখলে ফোনের দিকটা ওই তফাতে অন্ধ থাকত।
 *
 * ── এখানে যা **নেই**, আর কেন ────────────────────────────────────────
 * `Auth::login()`, `session()->regenerate()`, দুই-দরজার কুকি, redirect —
 * একটাও নয়। ওগুলো ব্রাউজারের জিনিস, আর টোকেন-ক্লায়েন্টের কাছে কোনো
 * মানে রাখে না। এই শ্রেণিটা **একটা ফল ফেরত দেয়, একটা উত্তর নয়** —
 * তাই ডাকা যায় ওয়েব কন্ট্রোলার থেকে, API থেকে, এমনকি একটা কমান্ড
 * থেকেও।
 *
 * throttle-ও এখানে নেই: ওটা রুটের জিনিস (`throttle:10,1`), আর একটা
 * সার্ভিস সেটা বহন করতে পারে না। **প্রতিটা নতুন দরজায় সেটা আলাদা করে
 * বসাতে হবে**, আর ওয়েবেরটার চেয়ে ঢিলা নয়।
 */
final class CredentialCheck
{
    public function __construct(
        private readonly LoginJournal $logins,
        private readonly MfaService $mfa,
        private readonly LoginLock $lock,
    ) {}

    /**
     * সফল হলে ব্যবহারকারী; নাহলে ছোঁড়ে।
     *
     * @param  string|null  $code  দুই ধাপের কোড, চালু থাকলে
     *
     * @throws ValidationException তালাবদ্ধ, বা পরিচয়/পাসওয়ার্ড ভুল
     * @throws MfaCodeRequired পাসওয়ার্ড ঠিক, কোড বাকি বা ভুল
     */
    public function verify(string $identifier, string $password, ?string $code = null): User
    {
        /*
         * তালাটা আগে — পাসওয়ার্ড যাচাইয়ের আগেও, ব্যবহারকারী খোঁজারও আগে।
         *
         * ── দুইটা আলাদা কারণ, দুইটাই দরকারি ─────────────────────────
         * **পরে বসালে** তালাবদ্ধ অবস্থাতেও প্রতিটা চেষ্টায় একটা bcrypt
         * যাচাই চলত — আক্রমণকারী ঢুকতে না পারলেও সার্ভারের CPU খরচ
         * করাতে পারতেন।
         *
         * **আর তালাটা টাইপ করা নামের উপর**, যে ব্যবহারকারীতে সেটা
         * মেলে তাঁর উপর নয়। নাহলে ভুল নামে চেষ্টা গোনাই হত না, আর
         * আক্রমণকারী নামের জগৎটা বিনামূল্যে হেঁটে যেতে পারতেন — যে
         * আক্রমণটা ঠেকানোর জন্যই তালাটা লেখা।
         */
        if (($minutes = $this->lock->locked($identifier)) !== null) {
            $this->logins->failed($identifier, null, LoginAttempt::LOCKED);

            throw ValidationException::withMessages([
                'identifier' => __('auth.locked', ['minutes' => $minutes]),
            ]);
        }

        $user = $this->findUser($identifier);

        /*
         * পাসওয়ার্ড **সবসময়** যাচাই হয়, ব্যবহারকারী না থাকলেও — একটা
         * ডামি হ্যাশের বিরুদ্ধে।
         *
         * অস্তিত্বহীন নামে সাথে সাথে উত্তর দিলে আক্রমণকারী **সময় মেপেই**
         * বুঝে ফেলেন কোন নামগুলো আসল, লগইন না করেই। এই লাইনটা তাই
         * অপচয় নয়, উত্তরটাকে সমান-সময়ের করার একমাত্র উপায় (সেকশন ১৬.৫)।
         */
        $hash = $user?->password ?? '$2y$12$'.str_repeat('.', 53);
        $passwordOk = Hash::check($password, $hash);

        if ($user === null || ! $passwordOk || ! $user->is_active) {
            /*
             * খাতায় আসল কারণটাই বসে, যদিও পর্দা তা বলে না।
             *
             * পর্দা তিনটা ক্ষেত্রেই একই বার্তা দেয় — নাহলে বার্তা পড়ে
             * বা সময় মেপে কেউ ব্যবহারকারীর তালিকা বের করে ফেলতেন।
             *
             * কিন্তু নিরাপত্তা পর্যালোচনায় ঠিক ওই তফাতটাই দরকার: অচেনা
             * নামে পঁচিশটা চেষ্টা মানে কেউ **নাম** আন্দাজ করছেন; একটা
             * চেনা নামে পঁচিশটা মানে কেউ **পাসওয়ার্ড** আন্দাজ করছেন।
             * দুইটা আলাদা ঘটনা, আর দুইটার ব্যবস্থাও আলাদা।
             */
            $this->logins->failed(
                $identifier,
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
         * দ্বিতীয় ধাপ — চালু থাকলে, আর **ঢোকার আগে**।
         *
         * কোড চাওয়ার আগেই কাউকে ঢুকিয়ে দিলে ওই মুহূর্তেই তিনি ভেতরে —
         * মাঝের একটা অনুরোধেই গোটা ব্যবস্থা খোলা। তাই কোড না মেলা
         * পর্যন্ত এই পদ্ধতি কোনো ব্যবহারকারী ফেরত দেয় না, আর কোনো
         * দরজাই খোলে না।
         */
        if ($this->mfa->isOn($user)) {
            $code = trim((string) $code);

            if ($code === '') {
                $this->logins->failed($identifier, $user, LoginAttempt::NEEDS_CODE);

                throw new MfaCodeRequired(__('auth.code_needed'));
            }

            if (! $this->mfa->verify($user, $code)) {
                $this->logins->failed($identifier, $user, LoginAttempt::WRONG_CODE);

                throw new MfaCodeRequired(__('auth.code_wrong'), wasWrong: true);
            }
        }

        return $user;
    }

    /**
     * ঢোকাটা খাতায় তোলা — যে দরজা দিয়েই হোক।
     *
     * ── কেন এটা এখানে, কন্ট্রোলারে নয় ──────────────────────────────
     * দুইটা জিনিস, আর দুইটাই **দরজা-নিরপেক্ষ**: কে কখন ঢুকলেন
     * (`login_history`) আর তাঁর শেষ ঢোকার সময় (`last_login_at`)। ফোন
     * দিয়ে ঢুকলে সেগুলো না লেখা হলে "কে কখন ঢুকেছে" প্রশ্নের উত্তরটা
     * অর্ধেক হয়ে যেত — আর ঠিক ওই অর্ধেকটাই মাঠের মানুষ।
     *
     * এটা [[verify()]] থেকে আলাদা, কারণ ওয়েবের দরজায় সফল যাচাইয়ের
     * পরেও কিছু কাজ বাকি থাকে (`Auth::login`, সেশন)। কন্ট্রোলার সেগুলো
     * সেরে তারপর এটা ডাকে, যাতে খাতায় "ঢুকেছেন" লেখাটা সত্যিই ঢোকার
     * পরে বসে।
     */
    public function recordSuccess(string $identifier, User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->save();

        /*
         * `last_login_at` কেবল **শেষ** বারটা রাখে — পরেরটা আগেরটাকে
         * ঢেকে দেয়। তাই "গত সপ্তাহে ইনি কবে কবে ঢুকেছিলেন" প্রশ্নের
         * উত্তর ওই ঘরটায় কোনোদিন ছিল না; সেটা এই খাতায়।
         */
        $this->logins->succeeded($identifier, $user);
    }

    /**
     * নাম বা ইমেইল — `users`-এ এই দুইটাই আছে।
     *
     * (পরিকল্পনার সেকশন ১৬.৩-এ মোবাইল নম্বরও ছিল, কিন্তু টেবিলে
     * `mobile` বা `username` কোনো কলামই নেই — মেপে দেখা, ২ সেপ্টেম্বর
     * ২০২৬। ওই ঘর যেদিন যোগ হবে, সেদিন এখানে একটা লাইন।)
     *
     * ── ⚠️ শর্ত দুইটা একটা গোষ্ঠীর ভেতরে, আর সেটা একটা সংশোধন ───────
     * সরানোর আগে এটা লেখা ছিল গোষ্ঠী ছাড়া:
     *
     *     ->where('email', $id)->orWhere('name', $id)
     *
     * `User`-এ `SoftDeletes` আছে, তাই Eloquent নিজে থেকে
     * `deleted_at is null` জোড়ে, আর SQL দাঁড়ায়
     *
     *     deleted_at IS NULL AND email = X OR name = X
     *
     * `AND`-এর অগ্রাধিকার বেশি, অর্থাৎ **নাম দিয়ে খুঁজলে নরম-মোছা
     * ব্যবহারকারীও ফিরে আসতেন** — আর তারপর পাসওয়ার্ড মিললে ঢুকেও
     * পড়তেন। বিদায় নেওয়া কর্মীর অ্যাকাউন্ট মুছে দেওয়ার পরেও কাজ করত।
     *
     * ইমেইল দিয়ে ঢুকলে ধরা পড়ত না (প্রথম শর্তটা `AND`-এর ভেতরে), তাই
     * বাগটা কেবল নাম দিয়ে লগইনে দেখা যেত — আর ডেমোর সবাই ইমেইল দিয়েই
     * ঢোকেন।
     */
    private function findUser(string $identifier): ?User
    {
        $identifier = trim($identifier);

        return User::query()
            ->where(function ($query) use ($identifier): void {
                $query->where('email', $identifier)
                    ->orWhere('name', $identifier);
            })
            ->first();
    }
}
