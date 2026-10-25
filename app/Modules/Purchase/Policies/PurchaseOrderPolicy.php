<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseOrder;

/**
 * ক্রয় আদেশ — কে কী পারে।
 *
 * অনুমতির নামগুলো module.php-তে ঘোষিত, আর ওখানেই একমাত্র জায়গা যেখানে
 * তালিকাটা আছে। এখানে দ্বিতীয় তালিকা রাখলে দুইটা একদিন আলাদা হত।
 */
class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchase.order.view');
    }

    public function view(User $user, PurchaseOrder $document): bool
    {
        // ⭐ এই কাগজের সইকারীও — সইয়ের পাতা/নোটিশ যে কাগজে তাঁর সই চায় বা চেয়েছিল, সেটা পড়তে পারেন; অন্য কাগজ নয়, আর বদল/পাকা করা নয় (fe, ২৪ অক্টোবর ২০২৬; [[VoucherPolicy::view()]]-এর একই নিয়ম)
        return $user->can('purchase.order.view') || \App\Core\Engines\Approval\PaperSigners::isSigner($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->can('purchase.order.create');
    }

    public function update(User $user, PurchaseOrder $document): bool
    {
        // নিশ্চিত হওয়া ডকুমেন্ট আর বদলায় না — সেবা স্তরও এটা আটকায়,
        // কিন্তু বোতামটা দেখানোই উচিত নয়
        return $user->can('purchase.order.update')
            && $document->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, PurchaseOrder $document): bool
    {
        return $user->can('purchase.order.cancel');
    }
}
