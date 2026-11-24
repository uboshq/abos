<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Does this person's look take the one-row list head (title, tabs, filter, search and create on one row)?
 *
 * Only the three ABOS looks — rose, navy, blue (owner, 24 Nov 2026). SAP, Dynamic and Odoo keep their own page heads.
 */
final class AbosHead
{
    public static function shows(): bool
    {
        return in_array(Ui::clean(LookRegistry::lookFor(LookPreview::orChosen(auth()->user()?->ui))), Ui::FOLD_MENU_LOOKS, true);
    }
}
