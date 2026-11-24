<?php
ndeclare(strict_types=1);

namespace App\Core\Support;

use App\Models\User;

/**
 * এক সারির তালিকার মাথা কেবল তিন ABOS রূপে — ABOS Smart (rose), Navy, Blue (মালিক, ২৪ নভেম্বর ২০২৬)।
 *
 * ⛔ SAP, Dynamic, Odoo আর বাকি রূপে পাতার মাথা হুবহু আগের মতো। ⓘ জাভাস্ক্রিপ্টের একই তালিকা: abos-list-head.js-এর
 *   `ABOS_HEAD_LOOKS` — দুইটা একসাথে বদলায়।
 */
final class AbosHead
{
    public const LOOKS = ['rose', 'navy', 'blue'];

    public static function on(?User $user = null): bool
    {
        $user ??= auth()->user();

        return in_array(LookRegistry::lookFor(LookPreview::orChosen($user?->ui)), self::LOOKS, true);
    }
}
