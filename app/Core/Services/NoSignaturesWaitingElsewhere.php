<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\SignaturesWaitingElsewhere;
use App\Models\User;

/** ⓘ কোনো মডিউল এমন সই না রাখলে — বাক্সে বাড়তি কিছু নেই ([[SignaturesWaitingElsewhere]]) */
final class NoSignaturesWaitingElsewhere implements SignaturesWaitingElsewhere
{
    public function waitingFor(User $user): array
    {
        return [];
    }
}
