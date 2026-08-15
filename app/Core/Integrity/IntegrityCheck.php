<?php

declare(strict_types=1);

namespace App\Core\Integrity;

use InvalidArgumentException;

/**
 * খাতা নিজের সম্পর্কে একটা প্রশ্ন — আর উত্তরটা চালিয়ে দেখা যায়।
 *
 * ── কেন "সতর্কবার্তা" নয় ────────────────────────────────────────────
 * স্পেক চেয়েছিল রিপোর্টের মাথায় "Data Quality Warning"। কিন্তু ভাঙা
 * খাতা নিয়ে সতর্ক করা ভুল সমাধান: সতর্কবার্তা দুই সপ্তাহে অদৃশ্য হয়ে
 * যায় — মানুষ ওটা পড়া বন্ধ করে দেয়, আর তারপর ওটা থাকা না-থাকা সমান।
 * তার চেয়ে একটা পর্দা, যেখানে গিয়ে চালিয়ে দেখা যায়, আর যা ভাঙা তার
 * **তালিকা** পাওয়া যায়।
 *
 * ── কেন প্রশ্নটা আর ভাঙলে কী হয় দুইটাই লেখা থাকে ────────────────────
 * ছয় মাস পরে যিনি লাল সারিটা দেখবেন তিনি এই কোড লেখেননি। "trial
 * balance mismatch" পড়ে তিনি বুঝবেন না এটা জরুরি না উপেক্ষণীয়। তাই
 * প্রতিটা যাচাই নিজেই বলে সে কী জিজ্ঞেস করছে, আর ভাঙলে বাস্তবে কী
 * ঘটে — নাহলে লাল সারিটা দেখা আর না দেখা একই।
 */
final class IntegrityCheck
{
    /**
     * @param  string  $key  `accounts.trial_balance` — মডিউলের নাম দিয়ে শুরু
     * @param  callable():list<IntegrityFinding>  $run  খালি তালিকা মানে খাতা মিলেছে
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,

        /** কী জিজ্ঞেস করা হচ্ছে — এক বাক্যে, হিসাবের ভাষায় */
        public readonly string $question,

        /** ভাঙলে বাস্তবে কী ঘটে — কেন সারিটা জরুরি */
        public readonly string $whenBroken,

        public readonly string $permission,
        private $run,
    ) {
        if (! str_contains($key, '.')) {
            throw new InvalidArgumentException(
                "Integrity check key '{$key}' has no module prefix. Use 'accounts.trial_balance', "
                .'not \'trial_balance\' — two modules would otherwise collide.'
            );
        }

        if (trim($permission) === '') {
            throw new InvalidArgumentException(
                "The check '{$key}' declares no permission. A broken-books list names every document "
                .'that is wrong, which is not everybody\'s business.'
            );
        }
    }

    /**
     * চালাও।
     *
     * ── কেন ফল ক্যাশ করা হয় না ──────────────────────────────────────
     * এই পর্দায় মানুষ আসেই কারণ সে সন্দেহ করছে কিছু ভাঙা। বাসি উত্তর
     * দেখানো মানে সে যেটা মাত্র সারিয়েছে সেটাও লাল দেখা, আর তখন সে
     * পর্দাটাই আর বিশ্বাস করে না।
     *
     * @return list<IntegrityFinding>
     */
    public function run(): array
    {
        return ($this->run)();
    }
}
