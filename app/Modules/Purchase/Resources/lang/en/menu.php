<?php

declare(strict_types=1);

return [
    'orders' => 'Purchase Orders',
    /*
     * ⭐ "Goods Receipt", not "Goods Received" — 25 Sep 2026.
     *
     * ⓘ The menu row lives in Inventory now and reads "Goods Receipt";
     * this key is what the screen itself is titled. ⛔ Two spellings for
     * one screen read as two screens, which is exactly the confusion the
     * owner asked to end on the Bangla side.
     *
     * ⚠️ "Received" is a verb; the list holds papers, not actions.
     */
    'receipts' => 'Goods Receipt',
    'bills' => 'Purchase Bills',
    'payments' => 'Payments',
    'returns' => 'Purchase Returns',
    'pending_orders' => 'Pending Purchase Orders',
    'supplier_performance' => 'Supplier performance',
    'price_history' => 'Purchase price history',
    'match_exceptions' => 'Bills that did not match',
    'uninvoiced' => 'Received, Not Invoiced',
    'by_supplier' => 'Purchases by Supplier',
    'direct' => 'Direct Purchase',
    'requisitions' => 'Purchase Requisitions',
    'rfqs' => 'Requests for Quotation',
    'contracts' => 'Purchase Contracts',
];
