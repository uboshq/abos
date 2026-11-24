<?php

declare(strict_types=1);

namespace App\Modules\Hr\Policies;

use App\Models\User;
use App\Modules\Hr\Models\OvertimeSheet;
use App\Modules\Hr\Services\OvertimeService;
use App\Modules\Hr\Support\LeaveSigner;

/**
 * ⭐ অধিকালের শিটে "সই দিন" — কর্মীর নিজের ব্যবস্থাপক (নাগালে), বা মালিক; নিজের বা নিজে বানানো শিটে কেবল মালিক
 * (a6, ২১ অক্টোবর ২০২৬; [[OvertimeService::maySign()]])।
 *
 * ⓘ চাবি নয়, রিপোর্টিং লাইন — যিনি দলটা চালান তিনিই জানেন কে সত্যিই দেরি পর্যন্ত ছিলেন। সেবাও সই দেওয়ার মুহূর্তে একই প্রশ্ন
 * আবার করে, তালা দিয়ে।
 */
class OvertimeSheetPolicy
{
    /** ⭐ তালিকা — হাজিরার চাবি, বা নিজের দল আছে এমন ব্যবস্থাপক (fe, ২১ অক্টোবর ২০২৬, দফা ৩; [[LeaveSigner::teamOf()]]) */
    public function viewAny(User $user): bool
    {
        return $user->can('hr.attendance.view') || app(LeaveSigner::class)->teamOf($user) !== [];
    }

    public function sign(User $user, OvertimeSheet $sheet): bool
    {
        return app(OvertimeService::class)->maySign($user, $sheet);
    }
}
