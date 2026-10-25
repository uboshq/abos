<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseReceipt;

/**
 * মাল বুঝে নেওয়া — কে কী পারে।
 *
 * অনুমতির নামগুলো module.php-তে ঘোষিত, আর ওখানেই একমাত্র জায়গা যেখানে
 * তালিকাটা আছে। এখানে দ্বিতীয় তালিকা রাখলে দুইটা একদিন আলাদা হত।
 */
class PurchaseReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchase.receipt.view');
    }

    public function view(User $user, PurchaseReceipt $document): bool
    {
        // ⭐ এই কাগজের সইকারীও — সইয়ের পাতা/নোটিশ যে কাগজে তাঁর সই চায় বা চেয়েছিল, সেটা পড়তে পারেন; অন্য কাগজ নয়, আর বদল/পাকা করা নয় (fe, ২৪ অক্টোবর ২০২৬; [[VoucherPolicy::view()]]-এর একই নিয়ম)
        return $user->can('purchase.receipt.view') || \App\Core\Engines\Approval\PaperSigners::isSigner($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->can('purchase.receipt.create');
    }

    public function update(User $user, PurchaseReceipt $document): bool
    {
        // নিশ্চিত হওয়া ডকুমেন্ট আর বদলায় না — সেবা স্তরও এটা আটকায়,
        // কিন্তু বোতামটা দেখানোই উচিত নয়
        return $user->can('purchase.receipt.create')
            && $document->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, PurchaseReceipt $document): bool
    {
        return $user->can('purchase.receipt.cancel');
    }
}
