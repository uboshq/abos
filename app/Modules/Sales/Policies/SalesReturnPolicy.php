<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Sales\Models\SalesReturn;

/**
 * বিক্রয় ফেরত — কে কী পারে।
 *
 * ── কেন ফেরতের চাবি বিক্রয়ের চাবি থেকে আলাদা ────────────────────────
 * ফেরত নেওয়া মানে গ্রাহকের পাওনা কমিয়ে দেওয়া — অর্থাৎ টাকা ছাড়াই
 * খাতা থেকে অঙ্ক সরানো। বিক্রি করার অধিকার থাকলেই সেটা করা যাবে না;
 * নাহলে যে কেউ ভুয়া ফেরত দেখিয়ে নিজের ঘাটতি ঢাকতে পারত।
 */
class SalesReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.return.view');
    }

    public function view(User $user, SalesReturn $document): bool
    {
        return $user->can('sales.return.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales.return.create');
    }

    public function update(User $user, SalesReturn $document): bool
    {
        return $user->can('sales.return.create')
            && $document->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, SalesReturn $document): bool
    {
        return $user->can('sales.return.cancel');
    }
}
