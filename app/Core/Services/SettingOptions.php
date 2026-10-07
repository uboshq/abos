<?php

declare(strict_types=1);

namespace App\Core\Services;

use Closure;

/**
 * ⭐ একটা সেটিংয়ের বৈধ মানগুলো — ঘোষণা যে আকারেই লিখুক (৩ অক্টোবর ২০২৬)।
 *
 * ── ⚠️ কী ঘটেছিল, লাইভে ──────────────────────────────────────────────────
 * মালিক কন্ট্রোল প্যানেলের বিক্রয় ট্যাব দুইবার সংরক্ষণ করলেন — ১৩:৩২-এ কোম্পানি ৪,
 * ১৪:০৮-এ কোম্পানি ৫। ⓘ প্রতিবার প্রায় ১৮টা সারি বসল মান `"0"` নিয়ে:
 * `sales.print.paper.*`, `sales.print.design.*`, `sales.print.signature_count`,
 * `sales.margin.action` — অথচ কোনোটার তালিকায় `0` নেই। ⛔ অচেনা নকশা মানে পুরনো চলতি
 * কাগজ, তাই **মালিকের বাছা ছাপার নকশাগুলো নীরবে ছাপা বন্ধ হয়ে গেল**।
 *
 * ⓘ কারণ: ঘোষণায় তালিকা তিন আকারে আসে — খালি তালিকা (`['a4', 'a5']`, মানটাই মান),
 * `মান => নমুনা` (`'d/m/Y' => '১৮/০২/২০২৬'`, চাবিটাই মান), আর একটা ডাক
 * (`[DateFormat::class, 'dateOptions']`)। ⛔ পর্দার `<select>` প্রথম আকারকে দ্বিতীয়টা
 * ধরে **ক্রমিক নম্বর** পাঠাত, আর কন্ট্রোলার সেটাই বসাত। ⭐ তাই আকার চেনার নিয়ম
 * এখানে একবার — পর্দা, দরজা আর [[SettingsService::set()]]-এর পাহারা সবাই এখান থেকে নেয়।
 *
 * ── ⚠️ কেন আলাদা ক্লাস, SettingsService-এ নতুন পদ্ধতি নয় ─────────────────────
 * ভাগের গাছে চলমান টেস্ট-রান পুরনো SettingsService আগেই লোড করে রাখে; নতুন পদ্ধতির ডাক
 * এলে তার বাকি সব টেস্ট ভাঙত ([[BranchSettings]]-এর মাথায় একই শিক্ষা)। ⓘ স্থির ফাংশন,
 * কারণ প্রশ্নটা কেবল ঘোষণার — ডাটাবেস বা কোম্পানির কিছু লাগে না।
 */
final class SettingOptions
{
    /**
     * বৈধ মানগুলো, লেখা হিসেবে। ⓘ তালিকা নেই → `null` (খোলা ঘর — নাম, সংখ্যা)।
     * ⚠️ `choice` অথচ তালিকা নেই → `[]`: বাছাইয়ের ঘরে কিছুই বৈধ নয়, কারণ ঘোষণাটাই অসম্পূর্ণ।
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>|null
     */
    public static function of(array $definition): ?array
    {
        $options = $definition['options'] ?? null;

        if ($options === null) {
            return ($definition['type'] ?? null) === 'choice' ? [] : null;
        }

        /*
         * ⓘ ডাক চেনা হয় কড়া করে, `is_callable()` একা নয়: `['modern', 'standard']`-এর মতো
         * দুই-শব্দের তালিকাকেও সে ক্লাস-মেথড ভাবার চেষ্টা করত।
         */
        if ($options instanceof Closure) {
            $options = $options();
        } elseif (is_array($options) && count($options) === 2 && array_is_list($options)
            && is_string($options[0]) && is_string($options[1])
            && class_exists($options[0]) && method_exists($options[0], $options[1])) {
            $options = call_user_func($options);
        }

        $options = (array) $options;

        return array_map('strval', array_is_list($options) ? $options : array_keys($options));
    }

    /**
     * এই মান কি এই সেটিংয়ে বসতে পারে।
     *
     * ⓘ তালিকাহীন সেটিংয়ে সবই চলে (ধরন মেলানো দরজার কাজ)। তালিকা থাকলে মানটা লেখা হিসেবে
     * তালিকায় থাকতে হবে — `3` আর `'3'` এক, কারণ টেক্সট কলামে দুইটাই `'3'`।
     * ⛔ `true`/`false`/`null`/তালিকা কখনো নয়: লেখায় ওগুলো `'1'`, `''` বা ভাঙা কিছু হত।
     *
     * @param  array<string, mixed>  $definition
     */
    public static function allows(array $definition, mixed $value): bool
    {
        $options = self::of($definition);

        if ($options === null) {
            return true;
        }

        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        return in_array((string) $value, $options, true);
    }
}
