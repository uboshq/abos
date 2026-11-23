<?php

declare(strict_types=1);

/*
 * ⭐ Note and stock-free voucher lists — owner's design v5, 23 Nov 2026 ([[NoteListController]], [[TradeVoucherListController]]).
 * ⛔ Totals count posted papers only; waiting and drafts on their own lines.
 */

return [
    'noun' => 'notes',
    'noun_voucher' => 'vouchers',
    'search' => 'Number, bill, party, SR/PR number…',
    'search_voucher' => 'Number, ref, Mushak number, party…',
    'none_voucher' => 'No vouchers yet',

    'stage_posted' => 'Posted',
    'stage_waiting' => 'Awaiting approval',
    'stage_draft' => 'Draft',
    'stage_cancelled' => 'Cancelled',

    'filter_stage' => 'Status',
    'filter_date' => 'Date',
    'filter_party_cn' => 'Party',
    'filter_party_cdn' => 'Customer',
    'filter_party_dn' => 'Supplier',
    'filter_party_sale' => 'Customer',
    'filter_party_purchase' => 'Supplier',
    'filter_reason' => 'Reason',
    'filter_due' => 'Due date',
    'due_overdue' => 'Past the due date',
    'due_upcoming' => 'Today or later',
    'sort_due' => 'Due date first',

    'col_bill_cn' => 'Original sales bill (Mushak-6.3)',
    'col_bill_cdn' => 'Original sales bill (Mushak-6.3)',
    'col_bill_dn' => 'Original purchase bill',
    'col_supplier_note' => 'Supplier\'s Mushak-6.7',
    'col_amount' => 'Amount',
    'col_returns' => 'Linked returns',

    'party_sale' => 'Customer',
    'party_purchase' => 'Supplier',
    'col_ref_sale' => 'Ref / PO no.',
    'col_ref_purchase' => 'Supplier bill no.',
    'col_mushak_63' => 'Mushak-6.3 no.',
    'col_due' => 'Due date',
    'col_total' => 'Total',
    'col_tds' => 'TDS',
    'col_party_total_sale' => 'Receivable',
    'col_party_total_purchase' => 'Payable',

    'total_posted' => 'Grand total — posted',
    'total_waiting' => 'Waiting — awaiting approval',
    'total_drafts' => 'Drafts',
    'count_money' => ':count · ৳:money',
];
