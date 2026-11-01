<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Support;

/**
 * ⭐ ব্যবহারকারীর ফর্মের ট্যাব — কোন ঘর কোন ট্যাবে, আর ভুল থাকলে কোনটা খুলে থাকে (কন্ট্রোল প্যানেলের পরিকল্পনা, মালিকের অনুমোদিত,
 * ২১ অক্টোবর ২০২৬, দফা ৮)।
 *
 * ⓘ ট্যাবের ক্রমে প্রথম ভুল-ধরা ট্যাব খোলে — পরিচয় → প্রবেশাধিকার → নিরাপত্তা; ⛔ নইলে ভুলটা অন্য ট্যাবের পেছনে লুকিয়ে থাকত।
 * ⓘ আলাদা ক্লাসে, কারণ পরীক্ষার সেশনে ভুলের ব্যাগ পাতায় পৌঁছায় না — নিয়মটা এখানে সোজা মাপা যায়।
 */
final class UserFormTabs
{
    public const ORDER = ['identity', 'access', 'security'];

    public static function of(string $field): string
    {
        return match (true) {
            in_array($field, ['password', 'password_confirmation', 'current_password'], true) => 'security',
            str_starts_with($field, 'roles') || str_starts_with($field, 'companies') || str_contains($field, '_scope')
                || str_starts_with($field, 'branch') => 'access',
            default => 'identity',
        };
    }

    /** @param  list<string|int>  $errorFields */
    public static function open(array $errorFields): string
    {
        $tabs = array_map(fn ($f) => self::of((string) $f), $errorFields);

        foreach (self::ORDER as $tab) {
            if (in_array($tab, $tabs, true)) {
                return $tab;
            }
        }

        return 'identity';
    }
}
