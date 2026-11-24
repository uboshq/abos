<?php

declare(strict_types=1);

/*
 * Notes and stock-free vouchers — form and page (owner's design v5, 23 Nov 2026). Grid, summary and ledger texts shared by both;
 * the note's own texts are in note_form.php.
 */

return [
    // head
    'cancel' => 'Cancel',
    'save_draft' => 'Save draft',
    'send' => 'Send for approval',
    'approver' => 'Approval: :who',
    'approver_levels' => ':n steps',
    'approver_none' => 'No approval needed',
    'chip_sale' => 'Mushak-6.3',
    'party_customer' => 'Customer',
    'party_supplier' => 'Supplier',
    'date_time' => 'Date · time',
    'due_date' => 'Due date',
    'days' => 'days',
    'ref_sale' => 'Customer ref / PO no.',
    'ref_purchase' => 'Supplier bill no.',
    'mushak_no' => 'Mushak-6.3 no.',
    'mushak_date' => 'Mushak-6.3 date',
    'total' => 'Bill total',

    // lines grid
    'col_no' => '#',
    'col_type' => 'Type',
    'col_item' => 'Product / service / account',
    'col_desc' => 'Description',
    'col_billed' => 'On bill',
    'col_qty' => 'Qty',
    'col_unit' => 'Unit',
    'col_rate' => 'Rate',
    'col_amount' => 'Amount',
    'col_vat' => 'VAT',
    'col_vat_amount' => 'VAT amount',
    'col_total' => 'Total',
    'col_goods' => 'Goods',
    'type_product' => 'Product',
    'type_service' => 'Service',
    'type_account' => 'Account',
    'pick_product' => '— Pick a product —',
    'pick_bill_line' => '— Pick a bill line —',
    'pick_account' => '— Pick an account —',
    'service_name' => 'Service name',
    'account_of' => 'Account:',
    'vat_none' => 'VAT-exempt',
    'vat_prefix' => 'VAT ',
    'add_row' => '＋ Row',
    'remove_row' => 'Remove row',
    'narration' => 'Narration',

    // summary
    'summary' => 'Summary',
    'sum_amount' => 'Amount',
    'sum_vat' => 'VAT',
    'sum_gross' => 'Bill total',
    'withheld' => 'What we withhold',
    'tds' => 'Tax deducted at source (TDS)',
    'bottom_sale' => 'Customer owes',
    'bottom_purchase' => 'Supplier gets',

    // ledger
    'post_title' => 'What goes to the books',
    'posted_title' => 'What went to the books',
    'debit' => 'Debit',
    'credit' => 'Credit',
    'balanced' => '✓ Balanced',
    'unbalanced' => 'Not balanced',
    'col_account' => 'Account',
    'col_party' => 'Party',
    'acc_1110' => '1110 · Accounts receivable',
    'acc_1120' => '1120 · Inventory',
    'acc_1139' => '1139 · Recoverable input VAT',
    'acc_2111' => '2111 · Goods suppliers',
    'acc_2118' => '2118 · Vendors and services payable',
    'acc_2120' => '2120 · VAT payable',
    'acc_2121' => '2121 · Tax deducted at source',
    'acc_4100' => '4100 · Sales',
    'acc_4110' => '4110 · Sales returns',
    'acc_5150' => '5150 · Purchase price variance',
    'acc_party' => 'Party account',

    // page
    'party' => 'Party',
    'collect' => 'Collect money',
    'confirm' => 'Send for approval',
    'cancel_paper' => 'Cancel',
    'cancel_prompt' => 'Why cancel?',
    'to_list' => 'List',
    'lines' => 'Lines',
];
