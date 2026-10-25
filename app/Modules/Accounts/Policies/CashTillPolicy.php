<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Policies;

use App\Models\User;
use App\Modules\Accounts\Models\CashTill;

/**
 * নগদ কাউন্টারে কে কী করতে পারে — অলঙ্ঘনীয় শর্ত ৪।
 *
 * দেখা আর বানানো আলাদা: ডেলিভারি ম্যানকে নিজের কাউন্টারে কত আছে তা
 * দেখতে হয়, কিন্তু নতুন কাউন্টার খোলা বা সীমা বদলানো তার কাজ নয়।
 */
class CashTillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounts.till.view');
    }

    public function view(User $user, CashTill $till): bool
    {
        // ⭐ এই কাগজের সইকারীও — সইয়ের পাতা/নোটিশ যে কাগজে তাঁর সই চায় বা চেয়েছিল, সেটা পড়তে পারেন; অন্য কাগজ নয়, আর বদল/পাকা করা নয় (fe, ২৪ অক্টোবর ২০২৬; [[VoucherPolicy::view()]]-এর একই নিয়ম)
        return $user->can('accounts.till.view') || \App\Core\Engines\Approval\PaperSigners::isSigner($user, $till);
    }

    public function create(User $user): bool
    {
        return $user->can('accounts.till.manage');
    }

    public function update(User $user, CashTill $till): bool
    {
        return $user->can('accounts.till.manage');
    }

    /** মোছা মানে এখানে বন্ধ করা (নিয়ম ৫)। */
    public function delete(User $user, CashTill $till): bool
    {
        return $user->can('accounts.till.manage');
    }
}
