<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Inventory\Models\Production;

/**
 * রান্নার কাগজের চাবি।
 *
 * ── কেন নিশ্চিত করার চাবি আলাদা ─────────────────────────────────────
 * খসড়া লেখা নিরীহ — কিছুই নড়ে না। **নিশ্চিত করা** মানে গুদাম থেকে মাল
 * বেরিয়ে যাওয়া, আর সেটা সমন্বয়ের সমান ক্ষমতা।
 *
 * এক চাবিতে রাখলে যিনি রোজ হাঁড়ির হিসাব লেখেন তিনিই স্টক নামাতে
 * পারতেন, আর ভুল সংখ্যা লিখে ফেললে সেটা সাথে সাথেই খাতায় বসত।
 */
class ProductionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.production.view');
    }

    public function view(User $user, Production $production): bool
    {
        return $user->can('inventory.production.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.production.create');
    }

    /**
     * নিশ্চিত হয়ে যাওয়া কাগজ আর বদলানো যায় না।
     *
     * মাল বেরিয়ে গেছে; সংখ্যাটা বদলালে খাতা আর গুদাম আলাদা কথা বলত।
     * ভুল হলে বাতিল করে নতুন কাগজ — ইতিহাসে দুইটাই থাকে।
     */
    public function update(User $user, Production $production): bool
    {
        return $production->status === DocumentStatus::DRAFT
            && $user->can('inventory.production.create');
    }

    public function confirm(User $user, Production $production): bool
    {
        return $production->status === DocumentStatus::DRAFT
            && $user->can('inventory.production.confirm');
    }

    public function delete(User $user, Production $production): bool
    {
        return $production->status === DocumentStatus::DRAFT
            && $user->can('inventory.production.create');
    }
}
