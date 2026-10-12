<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\KnowsAUsersEmployee;

/**
 * HR বন্ধ — কোনো ব্যবহারকারী কর্মী নন ([[KnowsAUsersEmployee]])।
 */
final class NoKnowsAUsersEmployee implements KnowsAUsersEmployee
{
    public function employeeIdOf(int $userId): ?int
    {
        return null;
    }
}
