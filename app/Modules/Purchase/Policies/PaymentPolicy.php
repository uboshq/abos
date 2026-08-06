<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Purchase\Models\Payment;

/**
 * পরিশোধ — কে কী পারে।
 *
 * ── কেন বাতিলের চাবি আলাদা ──────────────────────────────────────────
 * পরিশোধ বাতিল করা মানে খতিয়ানে টাকা ফিরিয়ে আনা। যিনি রোজ টাকা দেন
 * তিনি সেটা করতে পারবেন কি না, সেটা আলাদা সিদ্ধান্ত — আর বেশিরভাগ
 * ডিপোতে উত্তরটা "না"।
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchase.payment.view');
    }

    public function view(User $user, Payment $document): bool
    {
        return $user->can('purchase.payment.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchase.payment.create');
    }

    public function update(User $user, Payment $document): bool
    {
        // নিশ্চিত হওয়া পরিশোধ আর বদলায় না — সেবা স্তরও এটা আটকায়,
        // কিন্তু বোতামটা দেখানোই উচিত নয়
        return $user->can('purchase.payment.create')
            && $document->status === DocumentStatus::DRAFT;
    }

    public function delete(User $user, Payment $document): bool
    {
        return $user->can('purchase.payment.cancel');
    }
}
