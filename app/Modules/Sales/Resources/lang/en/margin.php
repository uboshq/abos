<?php

declare(strict_types=1);

/*
 * The margin wall — NEXUS §32 ([[MarginGuard]]).
 *
 * The `_plain` messages are for people without the cost key: no numbers,
 * because price and margin % together give the cost away.
 */
return [
    'below_line' => ':product — margin :margin%, floor :floor%. This price or discount sells below the floor.',
    'below_line_plain' => ':product — this price or discount sells below the margin floor.',
    'below_document' => 'The whole document margin is :margin%, floor :floor% — with the document discount it sells below the floor.',
    'below_document_plain' => 'With the document discount the whole sale is below the margin floor.',
    'cost_unknown' => ':product — no cost found in the stock layers, so its margin could not be measured.',
    'no_flow' => 'Sales below the margin floor need approval, but no approval flow is set up — so the sale was stopped. Set up a "Sales · Margin" flow under Approval → Flows.',
    'reason' => 'Below the margin floor (:floor%): :products',
    'whole_document' => 'the whole document (document discount)',
    'sale_held' => ':invoice kept as a draft — it sells below the margin floor, so it waits for approval. Once signed, confirm the sale from this page; the goods go out and the invoice can be printed then.',

    'setting_floor' => 'Margin floor (%)',
    'setting_action' => 'When a sale is below the floor',
    'action_warn' => 'Warn, let it through',
    'action_approval' => 'Send for approval',
    'action_block' => 'Block',

    'approval' => 'Sales · below the margin floor',
    'report_title' => 'Margin report',
    'col_product' => 'Product',
    'col_customer' => 'Customer',
    'col_qty' => 'Qty',
    'col_rate' => 'Rate',
    'col_discount' => 'Discount',
    'col_net' => 'Sales (ex VAT)',
    'col_cost' => 'Cost',
    'col_margin' => 'Margin',
    'col_margin_percent' => 'Margin %',
    'col_below' => 'Below floor',
    'yes' => 'Yes',

    'screen_margin' => 'Margin',
    'screen_below' => 'Below floor',
    'screen_cost_unknown' => 'Cost unknown',
    'warnings_title' => 'Margin warnings',
];
