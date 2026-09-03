<?php

declare(strict_types=1);

namespace App\Modules\Customer\Support;

/**
 * পার্টির আচরণের ধরন — বাঁধা তালিকা, তিন দলে, তিন গুরুত্ব।
 *
 * ── কেন বাঁধা তালিকা, মুক্ত লেখা নয় ─────────────────────────────────
 * একই অভ্যাস প্রতিটা ডিপোতে একই কোডে বসে বলেই "কোন ডিলাররা মাল নামাতে
 * দেরি করে" একটা রিপোর্ট-প্রশ্ন হয়ে উঠতে পারে। মুক্ত লেখা হলে ওটা "slow
 * unload", "unloading slow", "মাল নামাতে দেরি" — তিন বানানে জমত, আর
 * কোনোদিন গোনা যেত না।
 *
 * ── কেন config, কোম্পানি-বাড়ানো row নয় (টান) ────────────────────────
 * সাধারণত ABOS-এ "ধরনের তালিকা" কোম্পানি-বাড়ানো সারি (ReasonCode-এর মতো)।
 * কিন্তু এটার পুরো দাম cross-depot মিলে যাওয়ায়: কোম্পানি নিজের কোড যোগ
 * করলে "সব ডিপোতে কোন ডিলাররা দেরি করে" ভেঙে যেত। তাই ধরনগুলো এখানে,
 * এক জায়গায়। প্রয়োজন বাড়লে `OTHER` (নোট বাধ্যতামূলক) খোলা দরজা, আর
 * চাইলে পরে seeded-row-এ নেওয়া যাবে — notes-টেবিলে `type` একটা stable
 * string code, তাই সেই বদল contained।
 *
 * severity ধরন থেকেই গোনা হয়, আলাদা কলামে জমা নয় — একটাই উৎস, drift নেই।
 */
final class ConductType
{
    // গুরুত্ব
    public const GOOD = 'good';
    public const NOTICE = 'notice';
    public const RISK = 'risk';

    // দল
    public const MONEY = 'money';
    public const DELIVERY = 'delivery';
    public const RELATIONSHIP = 'relationship';

    /** মুক্ত লেখার একমাত্র খোলা দরজা — বাছলে নোট বাধ্যতামূলক। */
    public const OTHER = 'OTHER';

    /**
     * প্রতিটা ধরন: [দল, গুরুত্ব]। ক্রমটাই পর্দার দলবদ্ধ তালিকার ক্রম।
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const TYPES = [
        // টাকা
        'LATE_PAYMENT' => [self::MONEY, self::RISK],
        'CHEQUE_DISHONOURED' => [self::MONEY, self::RISK],
        'DISPUTES_INVOICE' => [self::MONEY, self::RISK],
        'PAYS_ON_TIME' => [self::MONEY, self::GOOD],
        'PAYS_IN_ADVANCE' => [self::MONEY, self::GOOD],

        // ডেলিভারি
        'SLOW_UNLOADING' => [self::DELIVERY, self::NOTICE],
        'ADVANCE_NOTICE_REQUIRED' => [self::DELIVERY, self::NOTICE],
        'FIXED_DELIVERY_WINDOW' => [self::DELIVERY, self::NOTICE],
        'NO_LARGE_VEHICLE_ACCESS' => [self::DELIVERY, self::NOTICE],
        'REFUSES_AT_GATE' => [self::DELIVERY, self::RISK],
        'QUICK_UNLOADING' => [self::DELIVERY, self::GOOD],

        // সম্পর্ক
        'KEY_ACCOUNT' => [self::RELATIONSHIP, self::GOOD],
        'DORMANT' => [self::RELATIONSHIP, self::NOTICE],
        self::OTHER => [self::RELATIONSHIP, self::NOTICE],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TYPES);
    }

    public static function isValid(string $code): bool
    {
        return isset(self::TYPES[$code]);
    }

    public static function groupOf(string $code): string
    {
        return self::TYPES[$code][0];
    }

    /** ধরন থেকে গুরুত্ব — অজানা কোডে নিরাপদে notice। */
    public static function severityOf(string $code): string
    {
        return self::TYPES[$code][1] ?? self::NOTICE;
    }

    /** OTHER-এ নোট বাধ্যতামূলক — মুক্ত লেখার পিছনের দরজা যেন না হয়। */
    public static function requiresNote(string $code): bool
    {
        return $code === self::OTHER;
    }

    public static function label(string $code, ?string $locale = null): string
    {
        return __('customer::conduct.type.'.$code, [], $locale);
    }

    /**
     * পর্দার select-এর জন্য দলবদ্ধ — দলের লেবেল => [কোড => ধরনের লেবেল]।
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(?string $locale = null): array
    {
        $out = [];

        foreach (self::TYPES as $code => [$group]) {
            $groupLabel = __('customer::conduct.group.'.$group, [], $locale);
            $out[$groupLabel][$code] = self::label($code, $locale);
        }

        return $out;
    }
}
