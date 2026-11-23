<?php

declare(strict_types=1);

namespace App\Modules\Hr\Policies;

use App\Models\User;
use App\Modules\Hr\Support\LeaveSigner;

/**
 * ⭐ ছুটির তালিকা — ছুটির চাবি, বা নিজের দল আছে এমন ব্যবস্থাপক (রিপোর্টিং লাইন; [[LeaveSigner::teamOf()]])। মেনুর সারি আর পাতা
 * একই প্রশ্ন করে ([[MenuBuilder::pageLets()]]; fe, ২১ অক্টোবর ২০২৬) — সারি দেখা গেলে পাতা খোলে, পাতা ৪০৩ হলে সারি নেই।
 */
class LeaveApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('hr.leave.view') || app(LeaveSigner::class)->teamOf($user) !== [];
    }
}
