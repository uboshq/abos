<?php

declare(strict_types=1);

namespace App\Modules\Notification\Reports\Filters;

use App\Models\NotificationJob;

/** ⓘ ডেলিভারির অবস্থা ধরে ছাঁকনি (ধাপ ৪) */
final class StatusFilter extends FixedListFilter
{
    public function label(): string
    {
        return 'notification::report.filters.status';
    }

    protected function values(): array
    {
        return [NotificationJob::QUEUED, NotificationJob::PROCESSING, NotificationJob::SENT, NotificationJob::RETRYING,
            NotificationJob::HELD, NotificationJob::DEAD, NotificationJob::CANCELLED];
    }

    protected function labelOf(string $value): string
    {
        return (string) __('notification::delivery.statuses.'.$value);
    }
}
