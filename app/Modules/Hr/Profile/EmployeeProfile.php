<?php

declare(strict_types=1);

namespace App\Modules\Hr\Profile;

use App\Core\Contracts\ContributesToAProfile;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ প্রোফাইলের পরিচয়ের ঘর — পদ, ফোন, কর্মী নম্বর, যোগদানের দিন ([[ContributesToAProfile]])।
 *
 * ⓘ কথাগুলো HR-এর, মানুষটা কেবল তার বিষয় — ঠিক [[EmployeeFacts]]-এর মতো। ⛔ `user_id` কোম্পানি ধরে অনন্য, তাই
 *   এই চালু কোম্পানির কর্মী-সারিই কেবল (একই মানুষ দুই কোম্পানিতে দুই পদে থাকতে পারেন)।
 */
final class EmployeeProfile implements ContributesToAProfile
{
    public function identityOf(User $owner): array
    {
        $employee = Employee::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->where('user_id', $owner->getKey())
            ->whereNull('deleted_at')
            ->with('designation')
            ->orderBy('id')
            ->first();

        if (! $employee instanceof Model) {
            return [];
        }

        $joined = $employee->getAttribute('joining_date');
        $mobile = trim((string) ($employee->getAttribute('mobile') ?? ''));
        $code = (string) ($employee->getAttribute('code') ?? '');

        return [
            'designation' => $this->designationOf($employee),
            'phone' => $mobile !== '' ? $mobile : null,
            'employee_no' => $code !== '' ? $code : null,
            'joined_on' => $joined instanceof CarbonInterface ? $joined->toDateString() : null,
        ];
    }

    /** ⓘ HR সংখ্যা দেয় না */
    public function stat(string $key, User $owner, Carbon $from, Carbon $to): ?string
    {
        return null;
    }

    private function designationOf(Model $employee): ?string
    {
        $designation = $employee->getRelationValue('designation');

        if (! $designation instanceof Model) {
            return null;
        }

        $bn = (string) $designation->getAttribute('name_bn');
        $en = (string) $designation->getAttribute('name_en');
        $name = app()->getLocale() === 'bn' ? ($bn ?: $en) : ($en ?: $bn);

        return $name !== '' ? $name : null;
    }
}
