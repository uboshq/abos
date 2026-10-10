<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Policies;

use App\Models\User;
use App\Modules\Accounts\Models\FixedAsset;

/**
 * ⭐ স্থায়ী সম্পদের পাতা আর তার ছবি-কাগজ (স্থায়ী সম্পদ ধাপ ১, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ সংযুক্তির কার্ড ([[components/ui/attachments]]) জিজ্ঞেস করে "এই ধরনের কাগজ বানাতে পারেন কি না" — সম্পদের বেলায় সেটা
 * `accounts.asset.manage`। দেখা `accounts.asset.view`; শাখার দেয়াল মডেলের নিজের ([[ScopedToUserBranch]])।
 */
class FixedAssetPolicy
{
    public function view(User $user, FixedAsset $asset): bool
    {
        return $user->can('accounts.asset.view');
    }

    public function create(User $user): bool
    {
        return $user->can('accounts.asset.manage');
    }

    public function update(User $user, FixedAsset $asset): bool
    {
        return $user->can('accounts.asset.manage');
    }
}
