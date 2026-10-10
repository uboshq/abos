<?php

declare(strict_types=1);

namespace App\Modules\Notification\Reports\Filters;

use App\Core\Support\NotificationKinds;

/** ⓘ গুরুত্ব ধরে ছাঁকনি (ধাপ ৪) */
final class PriorityFilter extends FixedListFilter
{
    public function label(): string
    {
        return 'notification::report.filters.priority';
    }

    protected function values(): array
    {
        return NotificationKinds::PRIORITIES;
    }

    protected function labelOf(string $value): string
    {
        return (string) __('core.notify.priority.'.$value);
    }
}
