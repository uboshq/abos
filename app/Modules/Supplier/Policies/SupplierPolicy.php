<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Policies;

use App\Core\Services\DataScope;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Supplier\Models\Supplier;

/**
 * কে কী করতে পারে — অলঙ্ঘনীয় শর্ত ৪।
 *
 * কোম্পানি যাচাই এখানে ইচ্ছাকৃতভাবে নেই: BelongsToCompany-র গ্লোবাল
 * স্কোপ অন্য কোম্পানির সরবরাহকারী খুঁজতেই দেয় না, তাই $supplier
 * পর্যন্ত পৌঁছালে সে নিশ্চিতভাবেই চলতি কোম্পানির।
 */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('supplier.view');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->can('supplier.view') && self::inReach($user, $supplier);
    }

    public function create(User $user): bool
    {
        return $user->can('supplier.create');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can('supplier.update') && self::inReach($user, $supplier);
    }

    /**
     * "মোছা" মানে এখানে নিষ্ক্রিয় করা (নিয়ম ৫)।
     *
     * তবু অনুমতিটা আলাদা: নিষ্ক্রিয় সরবরাহকারীর নামে নতুন ক্রয় ঢোকে
     * না, তাই কাজটা দেখতে যত নিরীহ, ফল তত নয়।
     */
    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->can('supplier.delete') && self::inReach($user, $supplier);
    }

    /**
     * ⛔ সরবরাহকারীর শাখা মানুষের নাগালে — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
     *
     * ⚠️ তালিকা দেখার শাখায় ছাঁকা ছিল, কিন্তু দরজাগুলো কেবল চাবি দেখত — শাখায় আটকানো একজন ঠিকানায় আইডি
     * বদলে অন্য শাখার সরবরাহকারী খুলতে, বদলাতে আর নিষ্ক্রিয় করতে পারতেন। ⓘ শাখাহীন সরবরাহকারী (গোটা
     * কোম্পানির) সবার নাগালে — [[DataScope::allows()]]-এর নিয়ম।
     */
    private static function inReach(User $user, Supplier $supplier): bool
    {
        return app(DataScope::class)->allows($user, UserDataScope::BRANCH,
            $supplier->branch_id === null ? null : (int) $supplier->branch_id);
    }
}
