<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Profile;

use App\Core\Contracts\ContributesToAProfile;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Support\Carbon;

/**
 * ⭐ "এই মাসে আমার কাজ"-এর হিসাবের সংখ্যা — পোস্ট করা ভাউচার ([[ContributesToAProfile]])।
 *
 * ⛔ কেবল পাকা ভাউচার ([[DocumentStatus::POSTED]])।
 */
final class AccountsProfile implements ContributesToAProfile
{
    public function identityOf(User $owner): array
    {
        return [];
    }

    public function stat(string $key, User $owner, Carbon $from, Carbon $to): ?string
    {
        if ($key !== 'vouchers_posted') {
            return null;
        }

        return number_format(Voucher::acrossBranches()
            ->where('created_by', $owner->getKey())
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereBetween('trx_date', [$from->toDateString(), $to->toDateString()])
            ->count());
    }
}
