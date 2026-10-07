<?php

declare(strict_types=1);

/*
 * Editing a posted paper — messages and the history panel (3 Oct 2026).
 * See App\Core\Services\PostedEdit, App\Core\Services\RevisionKeeper, x-ui.revisions.
 */
return [
    // ── refusals ─────────────────────────────────────────────────────────
    'switched_off' => ':no is posted — editing posted papers is switched off in this company. Issue a reversing paper, or switch editing on in the settings.',
    'not_posted' => ':no is not posted yet — a draft is changed through the ordinary edit.',
    'other_company' => ':no belongs to another company — only papers of the company you are working in can be revised.',
    'not_super_admin' => 'Only this company\'s super admin can revise the posted paper :no.',
    'month_closed' => 'The month of :no (:month) is closed — it cannot be revised. Open the month first, revise, then close it again.',
    'year_closed' => 'The financial year of :no (:year) is closed — reopen the year before revising.',
    'no_date' => ':no has no date — a paper cannot be revised without knowing its month.',
    'reason_required' => 'Write why you are revising — the posted paper :no cannot be changed without a reason.',
    'number_changed' => 'A revision never changes the number — :no stays :no. Nothing was kept.',
    'nothing_changed' => 'Nothing changed on :no — no revision was kept.',
    'reversal_narration' => ':no revised — :reason',

    // ── history panel ────────────────────────────────────────────────────
    'title' => 'Revision history',
    'revision_no' => 'Revision #:n',
    'by' => 'By',
    'when' => 'When',
    'reason' => 'Reason',
    'before' => 'Before',
    'after' => 'After',
    'row' => 'Row :n',
    'unchanged' => 'Unchanged fields (:n)',
    'nothing_here' => 'Nothing changed in this part.',
    'section' => [
        'header' => 'Main details',
        'lines' => 'Paper rows',
        'ledger' => 'Ledger entries',
        'stock' => 'Stock movement',
    ],
    'state' => [
        'same' => 'Unchanged',
        'changed' => 'Changed',
        'added' => 'New row',
        'removed' => 'Removed row',
    ],
    'printed_before' => 'This paper went out before the revision (:times times) — last :how, :when, :who. The version that went out is the "Before" below.',
    'printed_link' => 'Where this paper went',

    // ── field names — an unknown field shows its own name ────────────────
    'field' => [
        'trx_date' => 'Date',
        'ref_date' => 'Reference date',
        'document_no' => 'Number',
        'narration' => 'Narration',
        'amount' => 'Amount',
        'total' => 'Total',
        'status' => 'Status',
        'account' => 'Account',
        'account_id' => 'Account (id)',
        'party' => 'Party',
        'party_type' => 'Party type',
        'party_id' => 'Party (id)',
        'debit' => 'Debit',
        'credit' => 'Credit',
        'branch_id' => 'Branch (id)',
        'cost_center_id' => 'Cost centre (id)',
        'instrument' => 'Way',
        'instrument_no' => 'Transaction no.',
        'instrument_date' => 'Way date',
        'product' => 'Product',
        'warehouse_id' => 'Warehouse (id)',
        'batch_id' => 'Lot (id)',
        'qty' => 'Quantity',
        'rate' => 'Rate',
        'discount' => 'Discount',
        'floor_change' => 'On the shelf',
        'reserved_change' => 'Reserved',
        'hold_change' => 'On hold',
        'free_change' => 'Free goods',
        'free_reserved_change' => 'Reserved free',
        'unplaced_change' => 'Waiting to be placed',
        'unplaced_free_change' => 'Waiting to be placed (free)',
    ],
];
