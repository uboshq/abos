<?php

declare(strict_types=1);

namespace App\Modules\Hr\Policies;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\User;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Support\BranchReach;

/**
 * ⭐ কে একটা খরচের দাবি দেখতে পারেন (মালিকের আদেশ, ৭ অক্টোবর ২০২৬)।
 *
 * ⓘ নিজের দাবি — সবসময়; সবার দাবি — `hr.claim.view` আর কর্মীটা নাগালে ([[BranchReach]], বেতনের একই নিয়ম); সইকারী — সইয়ের
 * পাতা থেকে খোলেন, তাই `approval.decide` থাকলে কেবল সেই দাবি, যেটায় তিনি সই দিতে পারেন বা দিয়েছেন। ⛔ অন্য শাখার কর্মীর খরচ নয়।
 */
class ExpenseClaimPolicy
{
    public function view(User $user, ExpenseClaim $claim): bool
    {
        if ((int) $claim->requested_by === (int) $user->id) {
            return true;
        }

        /*
         * ⛔ সইয়ের চাবি মানে সব দাবি নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[AnEmployeeAsksForMoneyTest]])।
         * ⓘ `approval.decide` বিক্রি, কেনা, হিসাবের সব সইকারীর হাতে থাকে; আগে সেটা থাকলেই নাগালের যেকোনো সহকর্মীর দাবি, কারণ আর
         * রসিদের ছবি খোলা যেত (দাবির নম্বর গুনে গুনে)। এখন সইকারী কেবল সেই দাবি দেখেন, যেটার খোলা সইয়ে তিনি সই দিতে পারেন,
         * বা যেটায় আগে সিদ্ধান্ত দিয়েছেন — সইয়ের পাতা থেকে আসা ঠিক সেই কাজটুকু।
         */
        if (! $user->can('hr.claim.view') && ! ($user->can('approval.decide') && $this->signsFor($user, $claim))) {
            return false;
        }

        return app(BranchReach::class)->reaches($user, $claim->employee);
    }

    /** এই দাবির খোলা সইয়ে ইনি সই দিতে পারেন, বা আগে সিদ্ধান্ত দিয়েছেন */
    private function signsFor(User $user, ExpenseClaim $claim): bool
    {
        $approvals = Approval::query()
            ->where('approvable_type', $claim->getMorphClass())
            ->where('approvable_id', $claim->getKey())
            ->get();

        if ($approvals->isEmpty()) {
            return false;
        }

        if (ApprovalDecision::query()->whereIn('approval_id', $approvals->modelKeys())->where('user_id', $user->id)->exists()) {
            return true;
        }

        $engine = app(ApprovalEngine::class);

        return $approvals->contains(fn (Approval $a): bool => $a->status === Approval::PENDING && $engine->canDecide($a, $user));
    }
}
