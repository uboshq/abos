<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Sales\Models\Shipment;

/**
 * Shipment — কে কী পারে।
 *
 * অনুমতির নামগুলো module.php-তে ঘোষিত, আর ওখানেই একমাত্র তালিকা।
 */
class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.shipment.view');
    }

    public function view(User $user, Shipment $document): bool
    {
        return $user->can('sales.shipment.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales.shipment.create');
    }

    public function update(User $user, Shipment $document): bool
    {
        // গাড়ি বেরিয়ে যাওয়ার পর কাগজটা আর বদলায় না — সেবাও আটকায়,
        // কিন্তু বোতামটা দেখানোই উচিত নয়
        return $user->can('sales.shipment.create')
            && $document->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, Shipment $document): bool
    {
        return $user->can('sales.shipment.cancel');
    }
}
