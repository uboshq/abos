<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ এই কাগজের সইয়ে এই মানুষ আছেন কি না — চলতি বা আগের (fe, ১৯ অক্টোবর ২০২৬; মালিকের বাগ "ভাউচার ভিউ হয় না")।
 *
 * <p>আছেন যদি: এই কাগজের কোনো অনুরোধ তিনি নিজে চেয়েছেন; অপেক্ষার অনুরোধে তিনি এখন সিদ্ধান্ত দিতে পারেন
 * ([[ApprovalEngine::canDecide()]], ভারসহ); অথবা এই কাগজের কোনো অনুরোধে আগে সিদ্ধান্ত দিয়েছেন (নিজে বা কারো হয়ে)।
 *
 * <p>⛔ কেবল **এই** কাগজ — অনুরোধ খোঁজা হয় কাগজের ধরন আর আইডি ধরে, কোম্পানির ভিতরে; অন্য কাগজ এই ছাড়ে খোলে না।
 * ⓘ শাখার দেয়ালের বাইরের সইকারীর জন্য — ইনবক্স যে কাগজে তাঁর সই চায়, সেটা তিনি পড়তে পারবেন ([[OpensInReach]])।
 */
final class PaperSigners
{
    public static function isSigner(User $user, Model $paper): bool
    {
        $approvals = Approval::query()
            ->withoutGlobalScopes()
            ->where('company_id', $paper->getAttribute('company_id'))
            ->where('approvable_type', $paper->getMorphClass())
            ->where('approvable_id', $paper->getKey())
            ->get();

        if ($approvals->isEmpty()) {
            return false;
        }

        $engine = app(ApprovalEngine::class);

        foreach ($approvals as $approval) {
            if ((int) $approval->requested_by === (int) $user->id) {
                return true;
            }
            if ($approval->status === Approval::PENDING && $engine->canDecide($approval, $user)) {
                return true;
            }
        }

        return ApprovalDecision::query()
            ->withoutGlobalScopes()
            ->whereIn('approval_id', $approvals->pluck('id'))
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('on_behalf_of', $user->id))
            ->exists();
    }
}
