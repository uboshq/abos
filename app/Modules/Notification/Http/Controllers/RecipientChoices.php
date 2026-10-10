<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\NotificationRecipientGroup;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⓘ নিয়ম, দল আর সূচির পর্দায় বাছাইয়ের তালিকা — কেবল এই কোম্পানির (ধাপ ৩)। বাছা আইডি এই তালিকার বাইরে হলে যাচাইয়ে আটকায়
 * ([[ids()]]), তাই অন্য কোম্পানির মানুষ বা রোল নিয়মে ঢুকতে পারে না।
 */
final class RecipientChoices
{
    /** @return array<string, array<int, string>> */
    public static function all(): array
    {
        $company = (int) CompanyContext::id();
        $bn = app()->getLocale() !== 'en';

        return [
            'users' => User::query()->withoutGlobalScope('company')
                ->whereExists(fn ($q) => $q->from('company_user')->whereColumn('company_user.user_id', 'users.id')
                    ->where('company_user.company_id', $company)->where('company_user.is_active', true))
                ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'roles' => DB::table('roles')->where('company_id', $company)->orderBy('name')->pluck('name', 'id')->all(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name_bn', 'name_en'])->mapWithKeys(fn ($b) => [$b->id => (string) ($bn ? ($b->name_bn ?: $b->name_en) : ($b->name_en ?: $b->name_bn))])->all(),
            'departments' => DB::table('mdm_departments')->where('company_id', $company)->whereNull('deleted_at')->where('is_active', true)
                ->orderBy('id')->get(['id', 'name_bn', 'name_en'])->mapWithKeys(fn ($d) => [(int) $d->id => (string) ($bn ? ($d->name_bn ?: $d->name_en) : ($d->name_en ?: $d->name_bn))])->all(),
            'groups' => NotificationRecipientGroup::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'templates' => NotificationTemplate::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    /** @return array<string, list<int>> যাচাইয়ের জন্য কেবল আইডি */
    public static function ids(): array
    {
        return array_map(fn (array $list) => array_map('intval', array_keys($list)), self::all());
    }
}
