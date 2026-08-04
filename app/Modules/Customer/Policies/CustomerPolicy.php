<?php

declare(strict_types=1);

namespace App\Modules\Customer\Policies;

use App\Models\User;
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
        return $user->can('customer.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customer.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customer.update');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customer.delete');
    }

    /**
     * ক্রেডিট লিমিট ছাড়িয়ে বিল করা।
     *
     * আলাদা অনুমতি, কারণ এটা সাধারণ সম্পাদনার চেয়ে আলাদা সিদ্ধান্ত:
     * বিক্রয়কর্মী গ্রাহক বানাতে পারে, কিন্তু বাকির সীমা ভাঙতে পারে না।
     */
    public function overrideCreditLimit(User $user): bool
    {
        return $user->can('customer.credit_limit.override');
    }
}
