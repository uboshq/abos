<?php

declare(strict_types=1);

namespace App\Policies;

use App\Core\Engines\Report\ReportEngine;
use App\Models\ReportRun;
use App\Models\User;

/**
 * একটা নির্ধারিত-রিপোর্টের ফাইল কে নামাতে পারবেন।
 *
 * অনুমতিটা রেকর্ড দেখে ঠিক হয়, একটা স্থির চাবি দিয়ে নয় — তাই policy,
 * middleware-এর `can:` নয়। ফাইলটা কার জন্য তৈরি হয়েছিল (রেন্ডারের ছবি),
 * তিনিই কেবল পাবেন; সূচির চলতি প্রাপক-তালিকা নয়, কারণ পরে যোগ হওয়া
 * প্রাপকের কথা ভেবে ওই পুরনো ফাইলের কলাম ছাঁকা হয়নি।
 */
final class ReportRunPolicy
{
    public function download(User $user, ReportRun $run): bool
    {
        if (! $run->canBeDownloadedBy((int) $user->id)) {
            return false;
        }

        /*
         * ⛔ তালিকায় থাকলেই যথেষ্ট নয় — রিপোর্টটা দেখার অনুমতি **এখনো**
         * থাকতে হবে, ২৬ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ ছবিটা বলে ফাইলটা কার জন্য তৈরি হয়েছিল। ⚠️ কিন্তু তারপর কারও
         * চাবি কেড়ে নেওয়া হলে তিনি নামানোর পাতায় গিয়ে গত তিন মাসের সব
         * ফাইল নামাতে পারতেন — পর্দায় যে রিপোর্ট আর খুলতেই পারেন না,
         * তারই সংখ্যা। ⭐ মালিকের নিয়ম: প্রত্যেকে কেবল নিজেরটা পাবেন।
         *
         * ⓘ সূচি মুছে গেলেও ফাইলটা থাকতে পারে, তাই মোছা সূচিও পড়া হয়।
         * রিপোর্টটা কোড থেকে উঠে গেলে তার অনুমতিও আর জানা নেই — তখন
         * সন্দেহে কড়া দিক, অর্থাৎ **না**।
         */
        $key = $run->schedule()->withTrashed()->value('report_key');
        $reports = app(ReportEngine::class);

        if ($key === null || ! in_array($key, $reports->keys(), true)) {
            return false;
        }

        $permission = $reports->get($key)->permission;

        return $permission === null || $user->can($permission);
    }
}
