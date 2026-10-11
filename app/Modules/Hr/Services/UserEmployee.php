<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Contracts\KnowsAUsersEmployee;
use App\Modules\Hr\Models\Employee;

/**
 * ⭐ লগইন করা মানুষটা কোন কর্মী — কর্মীর সারির `user_id` থেকে ([[KnowsAUsersEmployee]]; স্থায়ী সম্পদ ধাপ ৪)।
 *
 * ⓘ কোম্পানির দেয়াল কর্মীর মডেলেই ([[BelongsToCompany]]) — অন্য কোম্পানির কর্মী-সারি এখানে আসে না।
 */
final class UserEmployee implements KnowsAUsersEmployee
{
    public function employeeIdOf(int $userId): ?int
    {
        $id = Employee::query()->where('user_id', $userId)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }
}
