<?php

declare(strict_types=1);

/**
 * ⭐ Purchase's own state words — 24 September 2026.
 *
 * ── ⓘ Why this file exists at all ────────────────────────────────────
 * Most purchase papers show their state through the shared badge, which
 * reads `DocumentStatus::label()`. ⚠️ That works while the generic words
 * carry the meaning: draft, confirmed, cancelled.
 *
 * ⛔ A requisition breaks that. Its `closed` means **"it became an
 * order"**, and "Closed" says nothing about the thing a reader wants to
 * know. ⓘ Inventory hit the same wall with transfers, where `confirmed`
 * really means "on the way", and solved it with a file like this one.
 */
return [
    'requisition_waiting' => 'Waiting',
    'requisition_approved' => 'Approved',
    'requisition_ordered' => 'Ordered',
    'requisition_cancelled' => 'Cancelled',
    'rfq_draft' => 'Not sent',
    'rfq_waiting' => 'Waiting',
    'rfq_closed' => 'Decided',
    'rfq_cancelled' => 'Cancelled',
    'contract_draft' => 'Draft',
    'contract_live' => 'Live',
    'contract_over' => 'Expired',
    'contract_closed' => 'Closed',
    'contract_cancelled' => 'Cancelled',
];
