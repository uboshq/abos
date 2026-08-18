<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Models\PeriodLock;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * এই তারিখে কিছু বসানো যায় কি না — খাতার দরজা।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * Control Panel-এ ঘরটা বছরখানেক ধরে ছিল — *"কত দিন পেছনের তারিখে এন্ট্রি
 * নেওয়া যাবে"*, ডিফল্ট ৭। সংখ্যাটা জমা হত, ফিরেও আসত, আর মালিক পর্দায়
 * দেখে ধরে নিতেন তালাটা কাজ করছে।
 *
 * **`accounts.backdate_days` কোথাও পড়াই হত না।** চারটা টেস্ট ছিল, আর
 * চারটাই কেবল দেখত সংখ্যাটা সংরক্ষিত হয় কি না — একটাও পুরনো তারিখের
 * এন্ট্রি বসিয়ে দেখেনি যে আটকায় কি না, কারণ আটকাত না। গত মাসের রিপোর্ট
 * বেরিয়ে যাওয়ার পরেও যেকোনো তারিখে ভাউচার বসত।
 *
 * ── দুই স্তরের তালা, আর দুইটা আলাদা জিনিস ───────────────────────────
 * **মাসের তালা** (`PeriodLock`) কঠিন: বন্ধ মাসে কিছুই বসে না। ওটা
 * খোলাতে হলে মাসটা খুলতে হবে, আর সেটা একটা সিদ্ধান্ত — কারণ ও অডিটসহ।
 *
 * **পেছনের জানালা** নরম: রোজকার দেরি সামলাতে। কেউ কেউ সীমার বাইরেও
 * এন্ট্রি করতে পারেন (`accounts.backdate.override`), কারণ পুরনো বিল
 * হাতে আসা স্বাভাবিক ঘটনা — অপরাধ নয়।
 *
 * উল্টোটা করলে দুইটাই অকেজো হত: মাসের তালা ডিঙানো গেলে ওটা তালাই নয়,
 * আর জানালা ডিঙানো না গেলে প্রতি সোমবার কাজ থেমে থাকত।
 *
 * ── অর্থবছরের তালা এর আগেই আছে ──────────────────────────────────────
 * `PostingEngine` বন্ধ অর্থবছরে পোস্ট করতে দেয় না — ওটা আগে থেকেই ছিল
 * আর ঠিক আছে। এখানে যোগ হলো তার নিচের দুইটা স্তর।
 */
final class OpenPeriod
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * বন্ধ থাকলে এখানেই থেমে যায়।
     *
     * @param  string  $field  কোন ঘরের নামে বার্তাটা বসবে
     */
    public function assertOpen(Carbon|string $date, string $field = 'trx_date'): void
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        if (($lock = $this->lockOn($date)) !== null) {
            throw ValidationException::withMessages([
                $field => __('core.period.month_closed', [
                    'month' => $lock->label(),
                    'reason' => $lock->reason ?: __('core.period.no_reason'),
                ]),
            ]);
        }

        $days = $this->windowDays();

        if ($days === null) {
            return;
        }

        $earliest = Carbon::today()->subDays($days);

        if ($date->startOfDay()->lt($earliest)) {
            throw ValidationException::withMessages([
                $field => __('core.period.too_far_back', [
                    'days' => $days,
                    'date' => $earliest->toDateString(),
                ]),
            ]);
        }
    }

    /** থামায় না, কেবল বলে — পর্দায় সতর্কতা দেখানোর জন্য। */
    public function isOpen(Carbon|string $date): bool
    {
        try {
            $this->assertOpen($date);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /** এই তারিখটা কোনো বন্ধ মাসের ভেতরে পড়ে কি না। */
    public function lockOn(Carbon|string $date): ?PeriodLock
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return PeriodLock::query()
            ->where('year', (int) $date->year)
            ->where('month', (int) $date->month)
            ->first();
    }

    /**
     * জানালাটা কত দিনের — বা কারও জন্য একেবারেই নেই।
     *
     * ── কেন অনুমতি থাকলে জানালাটাই উঠে যায় ──────────────────────────
     * সীমাটা ভুল ঠেকানোর জন্য, চুরি ঠেকানোর জন্য নয় — চুরি ঠেকায়
     * মাসের তালা। যাঁকে পুরনো বিল বসানোর দায়িত্ব দেওয়া হয়েছে, তাঁর
     * প্রতিটা এন্ট্রিতে বাধা দিলে তিনি তারিখটাই বদলে দিতেন, আর তখন
     * খাতায় ভুল তারিখ বসত — যা সীমা না থাকার চেয়েও খারাপ।
     */
    private function windowDays(): ?int
    {
        /*
         * কেউ লগইন না থাকলে জানালাটা খাটে না — আর মাসের তালা খাটে।
         *
         * ── কেন এই ভাগটা ───────────────────────────────────────────
         * জানালাটা **একজন মানুষের শৃঙ্খলা**: আজকের কাজ আজ লিখুন, গত
         * মাসের বিল হঠাৎ বসাবেন না। কেউ না থাকলে ওই শৃঙ্খলার কোনো
         * অর্থ নেই।
         *
         * আর যারা লগইন ছাড়া চলে, তারা ঠিক ওই কাজগুলোই করে যেগুলোর
         * তারিখ পুরনো হওয়াই স্বাভাবিক: পুরনো খাতা তোলা (খোলা মজুদ,
         * খোলা জের), ইমপোর্ট, সিডার, মাইগ্রেশন। ওখানে জানালা বসালে
         * **কোনো কোম্পানি ABOS-এ ঢুকতেই পারত না** — আলিন ফুডের ৮০৭টা
         * পুরনো বিল প্রথম দিনেই আটকে যেত।
         *
         * মাসের তালাটা তবু খাটে (উপরে, এর আগেই), কারণ ওটা মানুষের
         * শৃঙ্খলা নয় — ওটা ছাপা হয়ে যাওয়া হিসাবের সুরক্ষা, আর ইমপোর্ট
         * হোক বা মানুষ, বন্ধ মাস বন্ধই।
         */
        if (auth()->user() === null) {
            return null;
        }

        if (auth()->user()->can('accounts.backdate.override')) {
            return null;
        }

        $days = $this->settings->get('accounts.backdate_days');

        if ($days === null || $days === '') {
            return null;
        }

        $days = (int) $days;

        // শূন্য বা ঋণাত্মক মানে "সীমা নেই" — নাহলে শূন্য বসালে আজকের
        // এন্ট্রিও সন্দেহজনক হয়ে যেত, আর পুরো ডিপো থেমে থাকত
        return $days > 0 ? $days : null;
    }
}
