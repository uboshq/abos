<?php

declare(strict_types=1);

return [
    'stock_in' => ':no — goods into the warehouse',
    'awaiting_bill' => ':no — goods received, bill awaited',
    'bill_clears_pending' => ':no — clears the pending liability',
    'input_vat' => ':no — input VAT',
    'price_variance' => ':no — purchase price variance',
    'payable_to_supplier' => ':no — payable to the supplier',
    'order_created' => 'Purchase order created.',
    'order_updated' => 'Purchase order updated.',
    'order_confirmed' => 'Order confirmed — goods can now be received against it.',
    'order_cancelled' => 'Order cancelled.',
    'receipt_created' => 'Receipt created — confirming it puts the goods in the warehouse.',
    'receipt_updated' => 'Receipt updated.',
    'receipt_confirmed' => 'Goods are in the warehouse and the liability is in the books.',
    'receipt_cancelled' => 'Receipt cancelled — stock and books both reversed.',
    'bill_created' => 'Bill created.',
    'bill_updated' => 'Bill updated.',
    'bill_confirmed' => 'Bill posted — the liability is now in the supplier\'s name.',
    'bill_cancelled' => 'Bill cancelled.',
    'no_orders' => 'No purchase orders yet.',
    'no_receipts' => 'Nothing received yet.',
    'no_bills' => 'No purchase bills yet.',
    'order_search' => 'Search by number or supplier…',
    'receipt_search' => 'Search by number, challan no or supplier…',
    'bill_search' => 'Search by number, bill no or supplier…',
    'order_note' => 'An order moves nothing — not stock, not the books. That happens when goods arrive.',
    'receipt_note' => 'Confirming puts the goods in the warehouse and the liability in the books, together.',
    'bill_note' => 'A bill brings in no new goods — it moves the liability into the supplier\'s name.',
    // Payment
    'payment_created' => 'The payment was created.',
    'payment_updated' => 'The payment was updated.',
    'payment_confirmed' => 'The payment is posted.',
    'payment_cancelled' => 'The payment was cancelled.',
    'no_payments' => 'No payments yet.',
    'payment_search' => 'Search by number, cheque number or supplier',
    'payment_note' => 'Split it across the bills it settles — otherwise nobody can say later which bill is still owed.',
    'payment_lines' => 'Against which bills',
    'payment_unallocated' => 'Not split across any bill — this sits as an advance on the supplier account.',
    'money_out' => ':no — money paid out',
    'against_payable' => ':no — against payable',

    // Return
    'return_created' => 'The return was created — confirming it takes the goods out.',
    'return_updated' => 'The return was updated.',
    'return_confirmed' => 'The goods left the warehouse and the payable came down, together.',
    'return_cancelled' => 'The return was cancelled — both the stock and the ledger went back.',
    'no_returns' => 'No purchase returns yet.',
    'return_search' => 'Search by number or supplier…',
    'return_note' => 'Against a bill the rate comes from the bill, and no more can go back than was bought.',
    'return_lowers_payable' => ':no — payable reduced by return',
    'stock_out' => ':no — goods left the warehouse',
    'return_vat' => ':no — input VAT reversed',

    'lines' => 'Lines',
    'cancel_reason' => 'Reason for cancelling',
    'pending_of_order' => ':count line(s) of this order are still to arrive.',
    'paid_against' => 'Paid against :no',
    /*
     * Said once, at the moment it matters.
     *
     * The screen used to carry this as a standing blue notice above the
     * item box. It was read on the first day and furniture by the
     * fiftieth — and a place where nothing is ever read is where the
     * real warnings go to die.
     *
     * It belongs here because here it is news: the goods have just
     * landed, and the next thing to do is put them on a shelf.
     */
    'direct_done' => ':no — the goods are in and the bill is on the books. :qty are waiting to be placed.',
    'search_product' => 'Type a product name or code',
    'on_hand' => 'In stock',
    'last_rate' => 'Last rate',

    'last_from_supplier' => 'Last from this supplier',
    'first_from_supplier' => 'First time from this supplier',
    'no_lines_yet' => 'No products added yet.',
    'cart_empty_hint' => 'Nothing added yet. Pick an item above and press Add to cart.',
    'paid_more_confirm' => 'This pays more than the invoice. The extra stays as an advance with the supplier. Continue?',
    'order_not_confirmed' => ':no is still a draft — no bill can be raised against it yet. Open the order, confirm it, then come back.',
    'order_not_found' => 'That order could not be found. The blank form below is yours to fill by hand if you want.',

    'draft_found' => 'An unfinished purchase is waiting',

    'no_account_for_method' => 'No account of this kind is set up yet. Add one under the chart of accounts, then it will show here.',

    /*
     * The carrier list is empty on day one: no supplier is marked as a
     * transport party yet. Saying so — and saying where to fix it — is
     * the difference between a screen that looks broken and one that
     * asks for something.
     */
    'no_carrier_party' => 'No transporter is on the party list yet. Mark a supplier as a transport party, or just type the name below.',
    'transport_needs_carrier' => 'Freight is entered, but not who is owed it. Pick a transporter or type the name.',
    'transport_not_in_cost_yet' => 'Recorded on the bill. It does not go into the item cost yet.',

    /*
     * The counter screen (the owner's pictures, 4 September 2026).
     *
     * 'goods_wait_for_placement' is the one that has to be there. The
     * warehouse is picked here, but the goods land as *not yet placed*
     * — in the building, not on a shelf, and not sellable. Without a
     * word saying so, the person at the counter would read the green
     * confirmation as "it is on the shelf" and sell it the same hour.
     */
    'pick_item_hint' => 'Pick an item to see what is already in stock.',
    'as_printed' => 'as printed',
    'on_confirm' => 'on confirm',
    'credit_back_to_terms' => 'Back to the terms list',
    'bill_no_editable' => 'The next number in the series. Change it if you are entering an older paper.',
    'search_supplier' => 'Search supplier…',
    'no_default_warehouse' => 'No default warehouse is set, so the box is empty. Pick a default under Inventory ▸ Warehouses, or choose one here every time.',
    'sales_rate_hint' => 'leave blank to keep the current price',
    'goods_wait_for_placement' => 'Goods land as not yet placed. Put them on a shelf under Inventory ▸ Stock placement — until then they are in the warehouse but not sellable.',
    'costing_no_transport' => 'Freight is not inside this figure. It is recorded on the bill, but it does not reach the item cost yet.',
    'rate_chart_empty' => 'Nothing has been bought from this supplier before, so there is no rate to show.',
    'rate_chart_needs_supplier' => 'Pick the supplier first — the chart is their rates, not everybody\'s.',
    'discount_over_line_screen' => 'The discount is larger than the line itself.',
    'shipment_not_in_cost_yet' => 'Held on the bill so the bank and customs can be answered later. Duty and port charges do not reach the item cost yet.',
];
