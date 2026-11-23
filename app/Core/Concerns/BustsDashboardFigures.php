<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Services\DashboardFigureCache;
use App\Core\Support\CompanyContext;

/**
 * ⭐ A write to this row busts the remembered dashboard figures of its company ([[DashboardFigureCache]]; speed work
 * 23 Nov 2026) — a ledger row (every posting, [[PostingEngine]]), a stock movement (the only door, [[StockService]]) or an
 * approval that moved. ⓘ [[DashboardFigureCache::bust()]] waits for the commit; a rolled-back write moves nothing.
 *
 * ⓘ The model carries the rule itself, so the core never has to name a module's model (BoundariesTest).
 */
trait BustsDashboardFigures
{
    public static function bootBustsDashboardFigures(): void
    {
        foreach (['saved', 'deleted'] as $event) {
            static::{$event}(static fn ($row) => DashboardFigureCache::bust(
                isset($row->company_id) ? (int) $row->company_id : CompanyContext::id()));
        }
    }
}
