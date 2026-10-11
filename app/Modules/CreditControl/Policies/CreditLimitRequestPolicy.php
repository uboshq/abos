<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Policies;

use App\Models\User;
use App\Modules\CreditControl\Models\CreditLimitRequest;

/**
 * সীমা বাড়ানোর অনুরোধ — দেখা বাকির দেখার চাবিতে, লেখা (আর সাথে কাগজ তোলা) অনুরোধের চাবিতে (বাকি নিয়ন্ত্রণ ধাপ ২.৫)।
 *
 * ⛔ বদলানো বা মোছা কারও নয় — অনুরোধ যা লেখা হয়েছিল তা-ই থাকে; বদলায় কেবল সইয়ের ফলে অবস্থা।
 */
class CreditLimitRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('credit_control.view');
    }

    public function view(User $user, CreditLimitRequest $request): bool
    {
        return $user->can('credit_control.view');
    }

    public function create(User $user): bool
    {
        return $user->can('credit_control.request_limit');
    }

    public function update(User $user, CreditLimitRequest $request): bool
    {
        return false;
    }

    public function delete(User $user, CreditLimitRequest $request): bool
    {
        return false;
    }
}
