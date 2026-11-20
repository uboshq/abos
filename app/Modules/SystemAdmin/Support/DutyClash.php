<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Support;

/**
 * ⭐ দায়িত্বের সংঘাত (segregation of duties) — মালিকের অনুমোদিত রোলের পাতার নমুনা, ২০ নভেম্বর ২০২৬, দফা ৬।
 *
 * ⓘ একই কাগজে **লেখা** (`create`, বা তৈরি-সম্পাদনা-মোছা একসাথে `manage`) আর **পাকা করা** (`approve` বা `post`) একই রোলে থাকলে
 *   লেখক নিজেই নিজের কাগজ পাকা করতে পারেন — আন্তর্জাতিক নিয়মে (SOX, COSO) এটাই সবচেয়ে পুরনো সতর্কতা।
 * ⚠️ এটা কেবল জানায়, আটকায় না (মালিক, ১৮ নভেম্বর ২০২৬: "সতর্কতা কখনো কাজ আটকায় না") — ছোট ডিপোতে একজনকেই দুটো করতে হয়।
 */
final class DutyClash
{
    /** পাকা করার কাজ */
    private const FINISH = ['approve', 'post'];

    /** লেখার কাজ */
    private const WRITE = ['create', 'manage'];

    /**
     * যে জিনিসগুলোয় সংঘাত — অনুমতির বিষয় (`accounts.voucher`), নামের ক্রমে।
     *
     * @param  list<string>  $held
     * @return list<string>
     */
    public static function of(array $held): array
    {
        $set = array_flip($held);
        $found = [];

        foreach ($held as $name) {
            $parts = explode('.', $name);

            if (count($parts) < 2 || ! in_array(array_pop($parts), self::FINISH, true)) {
                continue;
            }

            $subject = implode('.', $parts);

            foreach (self::WRITE as $verb) {
                if (isset($set[$subject.'.'.$verb])) {
                    $found[$subject] = true;
                }
            }
        }

        $subjects = array_keys($found);
        sort($subjects);

        return $subjects;
    }
}
