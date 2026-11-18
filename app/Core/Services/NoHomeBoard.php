<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\HomeBoard;
use App\Models\User;

/**
 * Executive বন্ধ — হোমের গোটা-ব্যবসার বোর্ডে দেখানোর কিছু নেই ([[HomeBoard]]); হোম তার আগের অংশগুলো নিয়েই থাকে।
 */
final class NoHomeBoard implements HomeBoard
{
    public function for(User $user, string $period): array
    {
        return ['charts' => [], 'cards' => [], 'lists' => [], 'companies' => null, 'todo' => []];
    }
}
