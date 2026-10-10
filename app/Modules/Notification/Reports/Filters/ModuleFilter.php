<?php

declare(strict_types=1);

namespace App\Modules\Notification\Reports\Filters;

use App\Core\Support\NotificationKinds;

/** ⓘ খবরের উৎস-মডিউল ধরে ছাঁকনি — যে মডিউলগুলোর ধরন আছে (ধাপ ৪) */
final class ModuleFilter extends FixedListFilter
{
    public function label(): string
    {
        return 'notification::report.filters.module';
    }

    protected function values(): array
    {
        return collect(array_keys(NotificationKinds::all()))
            ->map(fn ($type) => NotificationKinds::classify($type)['module'])->unique()->sort()->values()->all();
    }

    protected function labelOf(string $value): string
    {
        return NotificationKinds::sourceLabel($value);
    }
}
