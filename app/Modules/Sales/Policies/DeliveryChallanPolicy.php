<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Sales\Models\DeliveryChallan;

/**
 * DeliveryChallan — কে কী পারে।
 *
 * অনুমতির নামগুলো module.php-তে ঘোষিত, আর ওখানেই একমাত্র তালিকা।
 */
class DeliveryChallanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.challan.view');
    }

    public function view(User $user, DeliveryChallan $document): bool
    {
        return $user->can('sales.challan.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales.challan.create');
    }

    public function update(User $user, DeliveryChallan $document): bool
    {
        // নিশ্চিত হওয়া ডকুমেন্ট আর বদলায় না — সেবা স্তরও আটকায়, কিন্তু
        // বোতামটা দেখানোই উচিত নয়
        return $user->can('sales.challan.create')
            && $document->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, DeliveryChallan $document): bool
    {
        return $user->can('sales.challan.cancel');
    }
}
