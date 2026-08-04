<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;

/**
 * কে কী করতে পারে — অলঙ্ঘনীয় শর্ত ৪।
 *
 * কোম্পানি যাচাই নেই ইচ্ছাকৃতভাবে: গ্লোবাল স্কোপ অন্য কোম্পানির সারি
 * খুঁজতেই দেয় না, তাই মডেল পর্যন্ত পৌঁছালে সেটা নিশ্চিতভাবেই চলতি
 * কোম্পানির।
 */
class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.warehouse.view');
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $user->can('inventory.warehouse.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.warehouse.create');
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $user->can('inventory.warehouse.update');
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return $user->can('inventory.warehouse.delete');
    }
}
