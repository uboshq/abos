<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Policies;

use App\Models\User;
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
        return $user->can('supplier.view');
    }

    public function create(User $user): bool
    {
        return $user->can('supplier.create');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->can('supplier.update');
    }

    /**
     * "মোছা" মানে এখানে নিষ্ক্রিয় করা (নিয়ম ৫)।
     *
     * তবু অনুমতিটা আলাদা: নিষ্ক্রিয় সরবরাহকারীর নামে নতুন ক্রয় ঢোকে
     * না, তাই কাজটা দেখতে যত নিরীহ, ফল তত নয়।
     */
    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->can('supplier.delete');
    }
}
