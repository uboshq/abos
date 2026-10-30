<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Sales\Models\SalesOrder;

/**
 * SalesOrder — কে কী পারে।
 *
 * অনুমতির নামগুলো module.php-তে ঘোষিত, আর ওখানেই একমাত্র তালিকা।
 */
class SalesOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.order.view');
    }

    public function view(User $user, SalesOrder $document): bool
    {
        // ⭐ এই কাগজের সইকারীও — সইয়ের পাতা/নোটিশ যে কাগজে তাঁর সই চায় বা চেয়েছিল, সেটা পড়তে পারেন; অন্য কাগজ নয়, আর বদল/পাকা করা নয় (fe, ২৪ অক্টোবর ২০২৬; [[VoucherPolicy::view()]]-এর একই নিয়ম)
        return $user->can('sales.order.view') || \App\Core\Engines\Approval\PaperSigners::isSigner($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->can('sales.order.create');
    }

    public function update(User $user, SalesOrder $document): bool
    {
        // নিশ্চিত হওয়া ডকুমেন্ট আর বদলায় না — সেবা স্তরও আটকায়, কিন্তু
        // বোতামটা দেখানোই উচিত নয়
        return $user->can('sales.order.update')
            && $document->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, SalesOrder $document): bool
    {
        return $user->can('sales.order.cancel');
    }
}
