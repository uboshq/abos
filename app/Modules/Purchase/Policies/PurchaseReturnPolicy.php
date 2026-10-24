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
        // ⭐ এই কাগজের সইকারীও — সইয়ের পাতা/নোটিশ যে কাগজে তাঁর সই চায় বা চেয়েছিল, সেটা পড়তে পারেন; অন্য কাগজ নয়, আর বদল/পাকা করা নয় (fe, ২৪ অক্টোবর ২০২৬; [[VoucherPolicy::view()]]-এর একই নিয়ম)
        return $user->can('purchase.return.view') || \App\Core\Engines\Approval\PaperSigners::isSigner($user, $document);
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
