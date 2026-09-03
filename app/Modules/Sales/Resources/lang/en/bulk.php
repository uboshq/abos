<?php

declare(strict_types=1);

/** Chart / Bulk DO — the whole catalogue as one sheet. Keys must match bn. */
return [
    /*
     * ── The button's name (3 Sep 2026, the owner's call) ─────────────────
     *
     * In a Bangladeshi depot the sheet an SR carries to a dealer's shop is
     * literally called a **চার্ট** — the whole catalogue on one page, with a
     * quantity written beside each line. Everyone at the counter knows the
     * word, so dropping it would mean explaining a thing they already name.
     *
     * But "Chart" alone reads as a graph, and says nothing about what the
     * button does. "Chart Entry" keeps the trade word and adds the verb, and
     * it works in both languages — which a name should, when it can.
     *
     * ⚠️ Do not shorten it back to "Chart". The screen it opens is where a
     * hundred lines get typed at once; a name that sounds like a report
     * would send people looking for it under Reports.
     */
    'open' => 'Chart Entry',
    'title' => 'The whole list as one sheet',
    'search' => 'Search products…',
    'filter_all' => 'All products',
    'filter_in_stock' => 'In stock',
    'filter_typed' => 'Typed so far',
    'sort_name' => 'By name',
    'sort_available' => 'By stock',
    'sort_typed' => 'Typed first',
    'available' => 'Available',
    'floor' => 'On the shelf',
    'reserved' => 'Reserved',
    'hold' => 'Hold',
    'free' => 'Free',
    'total_amount' => 'Total amount',
    'total_items' => 'Items',
    'total_free' => 'Free in this sheet',
    'nothing_until_apply' => 'Nothing reaches the document until you press Apply.',
];
