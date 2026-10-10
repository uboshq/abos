<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Policies;

use App\Models\User;
use App\Modules\Accounts\Models\AssetVerificationLine;

/**
 * ⭐ গোনার সারি আর তার ছবি (স্থায়ী সম্পদ ধাপ ৪)।
 *
 * ⓘ ছবির সংযুক্তি খুলতে [[AttachmentController]] এই নিয়ম জিজ্ঞেস করে; গোনার সারিতে ফল লেখার পথও একই নিয়মে ঢোকে।
 * দেখা মানে সম্পদ দেখার চাবি — শাখার দেয়াল অভিযানের নিজের ([[ScopedToUserBranch]])।
 */
class AssetVerificationLinePolicy
{
    public function view(User $user, AssetVerificationLine $line): bool
    {
        return $user->can('accounts.asset.view')
            && $line->verification()->exists();
    }
}
