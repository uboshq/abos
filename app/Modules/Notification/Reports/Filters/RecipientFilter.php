<?php

declare(strict_types=1);

namespace App\Modules\Notification\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Core\Support\CompanyContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** ⓘ প্রাপক ধরে ছাঁকনি — কেবল এই কোম্পানির সক্রিয় সদস্য (ধাপ ৪) */
final class RecipientFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'notification::report.filters.recipient';
    }

    protected function query(): Builder
    {
        return User::query()->withoutGlobalScope('company')
            ->whereExists(fn ($q) => $q->from('company_user')->whereColumn('company_user.user_id', 'users.id')
                ->where('company_user.company_id', CompanyContext::id())->where('company_user.is_active', true))
            ->orderBy('name');
    }

    protected function codeOf(Model $model): string
    {
        return '';
    }

    protected function nameOf(Model $model): string
    {
        return (string) $model->getAttribute('name');
    }
}
