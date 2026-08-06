<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Inventory\Models\StockTransfer;

/**
 * স্টক স্থানান্তর — কে কী পারে।
 */
class StockTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.transfer.view');
    }

    public function view(User $user, StockTransfer $document): bool
    {
        return $user->can('inventory.transfer.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.transfer.create');
    }

    public function update(User $user, StockTransfer $document): bool
    {
        // রওনা দেওয়ার পর আর বদলানো যায় না — মাল ট্রাকে
        return $user->can('inventory.transfer.create')
            && $document->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, StockTransfer $document): bool
    {
        return $user->can('inventory.transfer.cancel');
    }
}
