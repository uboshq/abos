<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Services\PermissionSyncer;
use App\Models\User;

/**
 * ⛔ যিনি লিখেছেন বা গুনেছেন, তিনি নিজে মেনে নেন না — মালিকের maker-checker নিয়ম (১৪ অক্টোবর ২০২৬), ওয়েব আর ফোন দুটোতেই।
 *
 * <p>ⓘ ছাড় কেবল super admin-এর — নগদ গোনার একই নিয়ম ([[CashCountService::assertSomeoneElseApproves()]])। মানুষ না থাকলে (রাতের
 * কাজ, শেষ সইয়ের শ্রোতা) আটকায় না: সেখানে সিদ্ধান্ত আগেই অন্য হাতে হয়েছে।
 */
final class MakerChecker
{
    /** এই মানুষ কি লেখক নন, বা super admin — অর্থাৎ মেনে নিতে পারেন */
    public static function isAnotherHand(?User $user, ?int $makerId): bool
    {
        if ($user === null || $makerId === null || (int) $user->id !== $makerId) {
            return true;
        }

        return $user->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE);
    }
}
