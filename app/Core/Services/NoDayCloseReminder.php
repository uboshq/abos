<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\DayCloseReminder;
use App\Models\User;

/** ⓘ হিসাব মডিউল বন্ধ — দিন বন্ধের খবর নেই ([[DayCloseReminder]]) */
final class NoDayCloseReminder implements DayCloseReminder
{
    public function forTicker(User $user): ?array
    {
        return null;
    }
}
