<?php

declare(strict_types=1);

namespace App\Core\Notifications;

/**
 * ⭐ টেমপ্লেটের অনুমোদিত চলক — আর লেখায় সেগুলো বসানো (মালিকের স্পেক §৯গ "Approved Variables", "Variable Validation";
 * বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩)।
 *
 * ── ⛔ নিয়ম ───────────────────────────────────────────────────────────
 *   · লেখায় কেবল এই তালিকার চলক (`{amount}`); অচেনা চলক থাকলে টেমপ্লেট সংরক্ষণই হয় না ([[unknownIn()]])।
 *   · মান বসে **সাধারণ লেখা** হিসেবে — HTML ট্যাগ ছেঁটে, দৈর্ঘ্য বেঁধে। পর্দা আর চিঠি সবসময় escape করে দেখায়; ব্যবহারকারীর
 *     লেখা কখনো HTML হিসেবে আঁকা হয় না।
 *   · ⛔ পাসওয়ার্ড, টোকেন বা গোপন কিছু চলক নয় — তালিকায় তেমন কিছু নেই, আর মডিউল পাঠালেও অচেনা নাম বাদ পড়ে ([[clean()]])।
 *   · টাকা আসে লেখা হিসেবে (মডিউল নিজে সাজিয়ে পাঠায়) — float কখনো নয়।
 */
final class NotificationVariables
{
    /** @var list<string> সব অনুমোদিত চলক — নাম => ভাষার চাবি `core.notify.var.<নাম>` */
    public const ALL = [
        'title', 'body', 'recipient', 'actor', 'company', 'branch', 'date',
        'paper_no', 'party', 'amount', 'due_date', 'days_left', 'status', 'level', 'stock_level', 'product',
    ];

    /** @var list<string> নিয়মের শর্তে যে ঘরগুলো তুলনা করা যায় */
    public const CONDITION_FIELDS = ['amount', 'days_left', 'stock_level', 'level', 'status', 'priority', 'branch_id'];

    /** @var list<string> শর্তে সংখ্যা হিসেবে তুলনা হয় যেগুলো */
    public const NUMERIC_FIELDS = ['amount', 'days_left', 'stock_level', 'level', 'branch_id'];

    private const MAX = 191;

    /** @return list<string> লেখায় থাকা অচেনা চলক — খালি মানে ঠিক আছে */
    public static function unknownIn(?string $text): array
    {
        return array_values(array_diff(self::namesIn($text), self::ALL));
    }

    /** @return list<string> লেখায় থাকা সব চলকের নাম */
    public static function namesIn(?string $text): array
    {
        preg_match_all('/\{([A-Za-z0-9_\.\-]+)\}/', (string) $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * মডিউলের পাঠানো মান থেকে কেবল অনুমোদিত চলক, সাধারণ লেখা হিসেবে।
     *
     * @param  array<string, mixed>|null  $data
     * @return array<string, string>
     */
    public static function clean(?array $data): array
    {
        $out = [];

        foreach ((array) $data as $name => $value) {
            if (! in_array($name, self::ALL, true) || ! (is_string($value) || is_int($value) || $value instanceof \Stringable)) {
                continue;
            }

            $value = trim(strip_tags((string) $value));
            $out[$name] = mb_substr($value, 0, self::MAX);
        }

        return $out;
    }

    /**
     * ⭐ লেখায় চলক বসানো — অচেনা বা মানহীন চলকের জায়গায় "—"। ফল সাধারণ লেখা; দেখানোর সময় escape হয়।
     *
     * @param  array<string, string>  $values
     */
    public static function render(?string $text, array $values): string
    {
        $rendered = preg_replace_callback('/\{([A-Za-z0-9_\.\-]+)\}/', function (array $m) use ($values): string {
            $name = $m[1];

            if (! in_array($name, self::ALL, true)) {
                return '—';
            }

            $value = $values[$name] ?? '';

            return $value === '' ? '—' : $value;
        }, (string) $text) ?? '';

        return trim(strip_tags($rendered));
    }

    /** @return array<string, string> পূর্বরূপের নমুনা মান — ভাষার ফাইল থেকে */
    public static function samples(): array
    {
        $out = [];

        foreach (self::ALL as $name) {
            $out[$name] = (string) __('core.notify.var_sample.'.$name);
        }

        return $out;
    }
}
