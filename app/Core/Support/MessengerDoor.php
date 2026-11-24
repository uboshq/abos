<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Is the messenger drawn for this person? its routes + `messaging.use` + one of the three ABOS looks ([[AbosHead]]).
 *
 * One rule for the messenger's own chrome ([[MessengerChrome::on()]]) and for pages outside the module that offer it.
 * ⓘ The core knows only route names and a key, no module class (BoundariesTest): without the module the answer is no.
 */
final class MessengerDoor
{
    public static function open(mixed $user): bool
    {
        return $user instanceof User
            && Route::has('messaging.index') && Route::has('messaging.x.unread')
            && AbosHead::on($user)
            && $user->can('messaging.use');
    }
}
