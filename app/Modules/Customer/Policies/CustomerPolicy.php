<?php

declare(strict_types=1);

namespace App\Modules\Customer\Policies;

use App\Core\Services\DataScope;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;

/**
 * কে কী করতে পারে — অলঙ্ঘনীয় শর্ত ৪ ("প্রতিটা রুটে permission")।
 *
 * কোম্পানি যাচাই এখানে নেই ইচ্ছাকৃতভাবে: BelongsToCompany-র গ্লোবাল স্কোপ
 * অন্য কোম্পানির গ্রাহককে খুঁজতেই দেয় না, তাই এই পদ্ধতিগুলো পর্যন্ত সেটা
 * পৌঁছায় না। দুই জায়গায় একই যাচাই মানে একদিন একটা বদলাবে আর অন্যটা
 * থেকে যাবে।
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customer.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customer.view') && $this->reaches($user, $customer);
    }

    public function create(User $user): bool
    {
        return $user->can('customer.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customer.update') && $this->reaches($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customer.delete') && $this->reaches($user, $customer);
    }

    /**
     * ⛔ শাখার দেয়াল — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (গ্রাহক ১১; [[ACustomerOfAnotherBranchIsNotMineToEditTest]])।
     *
     * ⓘ কোম্পানির দেয়াল গ্লোবাল স্কোপে, কিন্তু শাখার দেয়াল কেবল তালিকায় ছিল ([[Customer::scopeInViewedBranch()]]); নম্বর বসিয়ে অন্য
     * শাখার গ্রাহকের পাতা খোলা, সম্পাদনা আর নিষ্ক্রিয় করা যেত। শাখা লেখা নেই এমন গ্রাহক গোটা কোম্পানির — সবার নাগালে, তালিকার একই
     * নিয়মে ([[DataScope::allows()]])।
     */
    private function reaches(User $user, Customer $customer): bool
    {
        return app(DataScope::class)->allows($user, UserDataScope::BRANCH, $customer->branch_id === null ? null : (int) $customer->branch_id);
    }

    /**
     * গ্রাহককে নিজের পাতার চাবি দেওয়া বা কেড়ে নেওয়া।
     *
     * ── কেন `customer.update` যথেষ্ট নয় ─────────────────────────────
     * সম্পাদনার অনুমতি থাকে ডাটা এন্ট্রির লোকের কাছে — ফোন নম্বর
     * শোধরানো, ঠিকানা বদলানো। চাবি দেওয়া অন্য জিনিস: এতে বাইরের
     * একজন মানুষ ইন্টারনেট থেকে নিজের বকেয়া দেখতে পান। ওই
     * সিদ্ধান্তটা মালিকের, আর তাই আলাদা চাবি।
     */
    public function managePortal(User $user, Customer $customer): bool
    {
        return $user->can('customer.portal');
    }
}
