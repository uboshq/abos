<?php

declare(strict_types=1);

return [
    'no_lines' => 'At least one line is needed — a document with no lines does nothing.',
    'only_draft_confirms' => ':no is not a draft, so it cannot be confirmed again.',
    'only_draft_edits' => ':no is not a draft — cancel it and make a new one to change a posted document.',
    'already_cancelled' => ':no was already cancelled.',
    'unknown_product' => 'That product is not in this company\'s list.',
    'unknown_order' => 'That purchase order is not in this company\'s list.',
    'unknown_warehouse' => 'That warehouse is not in this company\'s list.',
    'unknown_receipt_line' => 'That receipt line is not in this company\'s list.',
    'no_financial_year' => ':date does not fall in any open financial year.',
    'discount_over_line' => 'A discount cannot exceed the line amount.',
    'not_a_number' => 'Please enter a number.',
    'negative_amount' => 'An amount cannot be negative.',
    'quantity_must_be_positive' => 'Quantity must be greater than zero (:field).',
    'zero_value_receipt' => 'A receipt worth nothing does not reach the books — enter a rate.',
    'zero_value_bill' => 'A bill worth nothing does not reach the books.',
    'line_not_in_order' => 'That line does not belong to this order.',
    'line_product_mismatch' => 'The line product does not match.',
    'over_receipt' => 'The order was for :ordered but :total would be received in total. Raise the allowance in the Control Panel to take more.',
    'order_required' => 'Receiving without an order is switched off — change it in the Control Panel.',
    'order_not_open' => ':no is not confirmed, so goods cannot be received against it.',
    'receipt_already_billed' => ':no has already been billed — cancel the bill first, then the receipt.',
    'receipt_not_confirmed' => ':no is not confirmed yet, so it cannot be billed.',
    'receipt_other_supplier' => 'That receipt belongs to another supplier.',
    'over_billed' => ':no received :received — no more than that can be billed.',
    'price_mismatch' => ':no differs from the receipt by :difference. Reconcile it, or switch this check off in the Control Panel.',
    'duplicate_bill_no' => 'Bill :no already exists for this supplier — the same bill would be paid twice.',
    // Payment
    'payment_must_be_positive' => 'A payment must be more than zero.',
    'unknown_bill' => 'That bill is not in this company list.',
    'bill_other_supplier' => 'That bill belongs to another supplier.',
    'bill_not_confirmed' => ':no is not posted yet.',
    'over_allocated' => ':no has :due owing — no more than that can be put against it.',
    'allocation_over_amount' => ':allocated has been allocated but the payment is :amount.',
    'unknown_account' => 'That account is not in this company chart.',
    'not_a_money_account' => ':name is not a cash or bank account — money does not leave from there.',

    // Return
    'zero_value_return' => 'A return worth nothing does not go in the books.',
    'unknown_bill_line' => 'That bill line is not in this company list.',
    'over_returned' => 'Only :room more can go back against :no.',
    'not_enough_to_return' => ':available of :product is in the warehouse — no more than that can go back.',

    'missing_account' => 'Account :code is missing from the chart — the chart has not been installed.',
    'bill_needs_warehouse' => 'This bill brings goods in without a receipt, but no warehouse is named and there is no default one. Pick a warehouse.',
    'unknown_order_line' => 'That order line was not found.',
    'order_other_supplier' => 'The order belongs to another supplier — one bill cannot mix suppliers.',
    'order_not_confirmed' => 'Order :no is not confirmed yet, so nothing can be billed against it.',
];
