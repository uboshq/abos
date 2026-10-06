<?php

declare(strict_types=1);

namespace App\Modules\Hr\Policies;

use App\Models\User;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Support\BranchReach;

/**
 * ⭐ কে একটা খরচের দাবি দেখতে পারেন (মালিকের আদেশ, ৭ অক্টোবর ২০২৬)।
 *
 * ⓘ নিজের দাবি — সবসময়; সবার দাবি — `hr.claim.view` আর কর্মীটা নাগালে ([[BranchReach]], বেতনের একই নিয়ম); সইকারী — সইয়ের
 * পাতা থেকে খোলেন, তাই `approval.decide` থাকলে নাগালের দাবি। ⛔ অন্য শাখার কর্মীর খরচ নয়।
 */
class ExpenseClaimPolicy
{
    public function view(User $user, ExpenseClaim $claim): bool
    {
        if ((int) $claim->requested_by === (int) $user->id) {
            return true;
        }

        if (! $user->can('hr.claim.view') && ! $user->can('approval.decide')) {
            return false;
        }

        return app(BranchReach::class)->reaches($user, $claim->employee);
    }
}
