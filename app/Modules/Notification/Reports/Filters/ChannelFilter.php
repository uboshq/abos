<?php

declare(strict_types=1);

namespace App\Modules\Notification\Reports\Filters;

use App\Models\NotificationChannel;

/** ⓘ মাধ্যম ধরে ছাঁকনি (ধাপ ৪) */
final class ChannelFilter extends FixedListFilter
{
    public function label(): string
    {
        return 'notification::report.filters.channel';
    }

    protected function values(): array
    {
        return NotificationChannel::ALL;
    }

    protected function labelOf(string $value): string
    {
        return (string) __('notification::channel.names.'.$value);
    }
}
