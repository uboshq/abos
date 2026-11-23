<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\CreditWall;
use Illuminate\Database\Eloquent\Model;

/** ⓘ বিক্রয় বন্ধ — বাকির সীমার হিসাব নেই, তাই দেয়ালও নেই ([[CreditWall]]) */
final class NoCreditWall implements CreditWall
{
    public function assertRoom(int $customerId, string $adding, Model $paper, bool $locked = false): void {}
}
