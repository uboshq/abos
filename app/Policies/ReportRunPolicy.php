<?php

declare(strict_types=1);

namespace App\Policies;

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
        return $run->canBeDownloadedBy((int) $user->id);
    }
}
