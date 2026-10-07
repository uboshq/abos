<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseReturn;

/**
 * ক্রয় ফেরত — কে কী পারে।
 */
class PurchaseReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchase.return.view');
    }

    public function view(User $user, PurchaseReturn $document): bool
    {
        return $user->can('purchase.return.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchase.return.create');
    }

    public function update(User $user, PurchaseReturn $document): bool
    {
        return $user->can('purchase.return.create')
            && $document->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, PurchaseReturn $document): bool
    {
        return $user->can('purchase.return.cancel');
    }
}
