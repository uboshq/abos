<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

/**
 * ⭐ "আজ যা করতে হবে"-র প্রতিটা ঘরের তাড়া — রং অর্থ ধরে, ক্রম ধরে নয় (মালিক, ১৮ নভেম্বর ২০২৬: লাল জরুরি · কমলা আজই ·
 * হলুদ এ সপ্তাহে · সবুজ ঠিক আছে; পাশে সবসময় শব্দ — [[x-ui.status-chip]])।
 *
 * ⓘ তিন ধাপ, ঘরের নিজের ঘোষণা থেকে — নতুন কিছু গোনা হয় না:
 *   · জরুরি — সবচেয়ে পুরনোটা এক সপ্তাহ বা তার বেশি পড়ে আছে (মডিউলের "সবচেয়ে পুরনোটা N দিন" ইঙ্গিত, `core.dashboard.oldest_is`)
 *   · আজই — মডিউল নিজে সতর্ক করেছে (`warn`): খসড়া ভাউচার, অপেক্ষমাণ হস্তান্তর …
 *   · এ সপ্তাহে — বাকিগুলো: অপেক্ষায় আছে, তবে আজকের মধ্যে না হলেও চলে
 * ⛔ আগে রং ঘরের ক্রমে ঘুরত (লাল, কমলা, হলুদ, বেগুনি) — একই ঘর এক দিন লাল, পরদিন হলুদ হতে পারত।
 */
final class WorkLevel
{
    public const OVERDUE_DAYS = 7;

    public static function of(Widget $widget): string
    {
        $age = self::age($widget->hint);

        return match (true) {
            $age !== null && $age >= self::OVERDUE_DAYS => 'urgent',
            $widget->tone === 'warn' => 'today',
            default => 'week',
        };
    }

    /** "সবচেয়ে পুরনোটা 10 দিন" → 10; ইঙ্গিতটা বয়সের না হলে null */
    private static function age(?string $hint): ?int
    {
        if ($hint === null || $hint === '') {
            return null;
        }

        $shape = '/^'.str_replace(preg_quote(':days', '/'), '([0-9০-৯]+)', preg_quote((string) __('core.dashboard.oldest_is'), '/')).'$/u';

        if (preg_match($shape, trim($hint), $m) !== 1) {
            return null;
        }

        return (int) strtr($m[1], ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }
}
