<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Policies;

use App\Core\Contracts\KnowsAUsersEmployee;
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

    /**
     * ⭐ "আমার সম্পদ" — দায়িত্বে থাকা কর্মী নিজের জিনিস দেখেন, হিসাবের চাবি ছাড়াই (ধাপ ৪)।
     * ⓘ ব্যবহারকারী কোনো কর্মী না হলে পাতাটা তাঁর জন্য নয় ([[KnowsAUsersEmployee]])।
     */
    public function viewOwn(User $user): bool
    {
        return app(KnowsAUsersEmployee::class)->employeeIdOf((int) $user->id) !== null;
    }

    /** ⓘ কেবল যিনি এখন দায়িত্বে — অন্যের জিনিস "বুঝে নিয়েছি" বলা যায় না */
    public function acknowledge(User $user, FixedAsset $asset): bool
    {
        $employee = app(KnowsAUsersEmployee::class)->employeeIdOf((int) $user->id);

        return $employee !== null && (int) $asset->custodian_id === $employee;
    }
}
