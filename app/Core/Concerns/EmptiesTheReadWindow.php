<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Services\ReadWindow;

/**
 * ⭐ A write to this row empties the read-only page's memories ([[ReadWindow::invalidate()]]; speed work 23 Nov 2026) —
 * the chart's lookups ([[AccountLookups]]) and the leaf balances ([[LedgerBalances]]) are then asked again.
 *
 * ⓘ The model carries the rule itself, so the core never has to name a module's model (BoundariesTest). Before this, the
 *   list of models stood in AppServiceProvider::boot().
 */
trait EmptiesTheReadWindow
{
    public static function bootEmptiesTheReadWindow(): void
    {
        foreach (['saved', 'deleted'] as $event) {
            static::{$event}(static fn () => app(ReadWindow::class)->invalidate());
        }
    }
}
