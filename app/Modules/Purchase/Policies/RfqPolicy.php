<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Purchase\Models\Rfq;

/**
 * দরপত্রের অনুরোধ — কে কী পারে।
 *
 * ── ⚠️ পাঠানো আর দর লেখা এক অধিকার নয় ──────────────────────────────
 * ⓘ অনুরোধ পাঠানো ক্রয় বিভাগের কাজ। ⛔ দর **লেখা** অন্য কাজ: ওটা
 * সরবরাহকারীর কাগজ থেকে টুকে বসানো, আর ঐ সংখ্যাগুলোই পরে সিদ্ধান্তের
 * ভিত্তি।
 *
 * ⚠️ এক চাবিতে রাখলে যিনি অনুরোধ পাঠান তিনিই দর বসাতে পারতেন — আর
 * তখন *"তিনজনের দর নিয়ে তুলনা করা হয়েছে"* কথাটার কোনো স্বাধীন
 * সাক্ষী থাকত না।
 */
class RfqPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchase.rfq.view');
    }

    public function view(User $user, Rfq $document): bool
    {
        return $user->can('purchase.rfq.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchase.rfq.create');
    }

    /**
     * ⭐ পাঠানো — আর এরপর তালিকা আর বদলায় না।
     */
    public function send(User $user, Rfq $document): bool
    {
        return $user->can('purchase.rfq.create')
            && $document->status === DocumentStatus::DRAFT;
    }

    /**
     * ⭐ দর লেখা — নিজের চাবি।
     *
     * ⛔ কেবল পাঠানো অনুরোধে: খসড়ায় দর লেখার মানে হয় না, কারণ
     * ⚠️ কাউকে জিজ্ঞেসই করা হয়নি।
     */
    public function quote(User $user, Rfq $document): bool
    {
        return $user->can('purchase.quotation.create')
            && $document->status === DocumentStatus::CONFIRMED;
    }
}
