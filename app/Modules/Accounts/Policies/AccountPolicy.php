<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Policies;

use App\Models\User;
use App\Modules\Accounts\Models\Account;

/**
 * হিসাবের ছকে কে কী করতে পারে — অলঙ্ঘনীয় শর্ত ৪।
 *
 * দেখা আর বদলানো আলাদা অনুমতি, ইচ্ছাকৃতভাবে: বিক্রয়কর্মীকে ভাউচার লিখতে
 * খাতের তালিকা দেখতে হয়, কিন্তু ছক বদলানোর অধিকার তার থাকা উচিত নয় —
 * একটা ভুল ধরন বদল পুরো ব্যালেন্স শিট নষ্ট করে।
 *
 * কোম্পানি যাচাই এখানে নেই: BelongsToCompany-র গ্লোবাল স্কোপ অন্য
 * কোম্পানির খাত খুঁজতেই দেয় না, তাই এই পদ্ধতিগুলো পর্যন্ত সেটা পৌঁছায় না।
 */
class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounts.coa.view');
    }

    public function view(User $user, Account $account): bool
    {
        return $user->can('accounts.coa.view');
    }

    public function create(User $user): bool
    {
        return $user->can('accounts.coa.manage');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->can('accounts.coa.manage');
    }

    /**
     * মোছা মানে এখানে নিষ্ক্রিয় করা (নিয়ম ৫)।
     *
     * সিস্টেমের খাত কেউই পারে না — অনুমতির প্রশ্নই আসে না, কারণ ওটা
     * অধিকারের ব্যাপার নয়, সিস্টেম টিকে থাকার ব্যাপার।
     */
    public function delete(User $user, Account $account): bool
    {
        return $user->can('accounts.coa.manage') && ! $account->is_system;
    }
}
