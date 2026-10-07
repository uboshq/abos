<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * SalesInvoice — কে কী পারে।
 *
 * অনুমতির নামগুলো module.php-তে ঘোষিত, আর ওখানেই একমাত্র তালিকা।
 */
class SalesInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.invoice.view');
    }

    public function view(User $user, SalesInvoice $document): bool
    {
        return $user->can('sales.invoice.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales.invoice.create');
    }

    public function update(User $user, SalesInvoice $document): bool
    {
        // নিশ্চিত হওয়া ডকুমেন্ট আর বদলায় না — সেবা স্তরও আটকায়, কিন্তু
        // বোতামটা দেখানোই উচিত নয়
        return $user->can('sales.invoice.create')
            && $document->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, SalesInvoice $document): bool
    {
        return $user->can('sales.invoice.cancel');
    }
}
