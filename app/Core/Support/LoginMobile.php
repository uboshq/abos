<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * লগইনের মোবাইল নম্বর — যে ছাঁদে লগইন সেটা মেলায়, আর একজনের নম্বর একজনেরই।
 *
 * ── ⛔ কেন লাগল, পুনঃনিরীক্ষা ৯ অক্টোবর ২০২৬ ──────────────────────────
 * [[CredentialCheck]] মোবাইল দিয়েও লগইন নেয়, আর একই পরিচয়ে দুইজন মিললে **কাউকেই**
 * ঢোকায় না (ইচ্ছাকৃত — ভুল মানুষকে ঢোকানোর চেয়ে কাউকে না ঢোকানো ভালো)। ⚠️ অথচ
 * প্রোফাইল আর ব্যবহারকারীর পাতা মোবাইলের ঘরে যেকোনো লেখা নিত, অনন্যতা ছাড়া —
 * তাই একজন সহকর্মীর নম্বর নিজের ঘরে বসালেই ঐ সহকর্মী আর নম্বর দিয়ে ঢুকতে পারতেন না।
 *
 * ⭐ এখন সংরক্ষণের আগে নম্বরটা লগইনের ছাঁদে আসে: ফাঁকা, ড্যাশ আর বন্ধনী বাদ, বাংলা
 * অঙ্ক ইংরেজিতে; থাকে কেবল অঙ্ক (সামনে চাইলে `+`), ১০ থেকে ১৫টা। আর ঐ নম্বর অন্য
 * কারও ঘরে থাকলে ফেরত।
 *
 * ⓘ "স্বামী-স্ত্রী একই নম্বর" ([[CredentialCheck::findUser()]]-এর পুরনো নোট) এখন আর
 * চলে না — একটা নম্বর একটা লগইন; দ্বিতীয়জন ইমেইল বা আইডি দিয়ে ঢোকেন।
 */
final class LoginMobile
{
    public const PATTERN = '/^\+?\d{10,15}$/';

    /** লগইনের ছাঁদে আনা — না আনা গেলে যা এসেছিল তাই (তখন নিয়মটা ফেরায়)। */
    public static function normalise(mixed $raw): mixed
    {
        if (! is_string($raw)) {
            return $raw;
        }

        $text = strtr(trim($raw), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        $plain = preg_replace('/[\s\-().]/u', '', $text) ?? $text;

        return preg_match(self::PATTERN, $plain) === 1 ? $plain : $raw;
    }

    /** অনুরোধের ঘরটা যাচাইয়ের আগে ছাঁদে আনা। */
    public static function prepare(Request $request, string $key = 'mobile'): void
    {
        if ($request->has($key)) {
            $request->merge([$key => self::normalise($request->input($key))]);
        }
    }

    /** @return list<mixed> */
    public static function rules(?int $ignoreUserId): array
    {
        return ['nullable', 'string', 'max:25', 'regex:'.self::PATTERN,
            Rule::unique('users', 'mobile')->ignore($ignoreUserId)];
    }

    /** @return array<string, string> */
    public static function messages(string $key = 'mobile'): array
    {
        return [
            $key.'.regex' => __('validation.login_mobile_format'),
            $key.'.unique' => __('validation.login_mobile_taken'),
        ];
    }
}
