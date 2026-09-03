<?php

declare(strict_types=1);

return [
    'stock_in' => ':no — goods into the warehouse',
    'awaiting_bill' => ':no — goods received, bill awaited',
    'bill_clears_pending' => ':no — clears the pending liability',
    'input_vat' => ':no — input VAT',
    'price_variance' => ':no — purchase price variance',
    'payable_to_supplier' => ':no — payable to the supplier',
    'order_created' => 'Sales order created.',
    'order_updated' => 'Order updated.',
    'order_confirmed' => 'Order confirmed — the stock is now held against it.',
    'order_cancelled' => 'Order cancelled and the held stock released.',
    'receipt_created' => 'Receipt created — confirming it puts the goods in the warehouse.',
    'receipt_updated' => 'Receipt updated.',
    'receipt_confirmed' => 'Goods are in the warehouse and the liability is in the books.',
    'receipt_cancelled' => 'Receipt cancelled — stock and books both reversed.',
    'bill_created' => 'Bill created.',
    'bill_updated' => 'Bill updated.',
    'bill_confirmed' => 'Bill posted — the liability is now in the supplier\'s name.',
    'bill_cancelled' => 'Bill cancelled.',
    'no_orders' => 'No sales orders yet.',
    'no_receipts' => 'Nothing received yet.',
    'no_bills' => 'No purchase bills yet.',
    'order_search' => 'Search by number or customer…',
    'receipt_search' => 'Search by number, challan no or supplier…',
    'bill_search' => 'Search by number, bill no or supplier…',
    'order_note' => 'Confirming an order holds the stock — it stays on the shelf, it just cannot be sold twice.',
    'receipt_note' => 'Confirming puts the goods in the warehouse and the liability in the books, together.',
    'bill_note' => 'A bill brings in no new goods — it moves the liability into the supplier\'s name.',
    // Return
    'return_created' => 'The return was created — confirming it brings the goods back in.',
    'return_updated' => 'The return was updated.',
    'return_confirmed' => 'The goods came back and the customer owes less, together.',
    'return_cancelled' => 'The return was cancelled — both the stock and the ledger went back.',
    'no_returns' => 'No sales returns yet.',
    'return_search' => 'Search by number or customer…',
    'return_note' => 'Against an invoice the rate comes from the invoice. Tick damaged goods — they come into the warehouse but cannot be sold again.',
    'return_lowers_sales' => ':no — sales return',
    'return_lowers_receivable' => ':no — customer owes less',
    'return_vat' => ':no — output VAT reversed',
    'return_stock_back' => ':no — goods back in the warehouse',
    'return_cost_back' => ':no — cost of goods sold reversed',

    'lines' => 'Lines',
    'cancel_reason' => 'Reason for cancelling',
    'pending_of_order' => ':count line(s) of this order are still to arrive.',
    'receivable' => ':no — receivable from the customer',
    'sale' => ':no — sale',
    'output_vat' => ':no — output VAT',
    'cost_of_goods' => ':no — cost of goods sold',
    'stock_out' => ':no — inventory reduced',
    'money_in' => ':no — money received',
    'against_receivable' => ':no — against the receivable',
    'challan_created' => 'Challan created — confirming it sends the goods out.',
    'challan_updated' => 'Challan updated.',
    'challan_confirmed' => 'Goods have left, and the order hold is released.',
    'challan_cancelled' => 'Challan cancelled — the goods are back in stock.',
    'invoice_created' => 'Invoice created.',
    'invoice_updated' => 'Invoice updated.',
    'invoice_confirmed' => 'Invoice posted — income, receivable and cost, all three.',
    'invoice_cancelled' => 'Invoice cancelled.',
    'collection_created' => 'Collection recorded.',
    'collection_updated' => 'Collection updated.',
    'collection_confirmed' => 'Money is in the books and the receivable is down.',
    'collection_cancelled' => 'Collection cancelled.',
    'no_challans' => 'No challans yet.',
    'no_invoices' => 'No invoices yet.',
    'no_collections' => 'No collections yet.',
    'challan_search' => 'Search by number, vehicle or customer…',
    'invoice_search' => 'Search by number or customer…',
    'collection_search' => 'Search by number, cheque no or customer…',
    'challan_note' => 'Confirming takes the goods off the shelf and releases the order hold.',
    'invoice_note' => 'The invoice posts income, the receivable and the cost of goods, all at once.',
    'collection_note' => 'Say which invoices the money is against and the chasing list stays right by itself.',
    'unallocated' => 'Unallocated',
    'pos_search' => 'Scan a barcode or type a name…',
    'pos_hint' => 'A scan goes straight into the basket. Enter adds the first product.',
    'pos_empty_cart' => 'Basket is empty — tap a product or scan a barcode.',
    'pos_today' => 'Your sales today',
    'pos_done' => ':no — sale complete. Change :change.',
    'pos_narration' => ':no — cash at the counter',

    // Bills held at the counter
    'pos_parked' => ':no is waiting at the counter. Nothing has been posted.',
    'pos_resumed' => ':no is back on the counter.',
    'pos_park' => 'Hold',
    'pos_parked_bills' => 'Waiting at the counter',
    'pos_parked_none' => 'Nothing is waiting.',
    'pos_parked_line' => ':no · :lines items · :total',
    'direct_note' => 'Goods go out without an order and the bill is raised there and then — challan, invoice and deposit in one press.',
    'direct_done' => ':challan and :invoice created. Change :change.',
    'direct_narration' => ':no — deposit against a direct sale',
    'not_for_sales' => 'Not for Sales',
    'pick_item_to_see_stock' => 'Pick an item to see its stock.',
    'nothing_added' => 'Nothing added yet. Pick an item above and press Add to Cart.',
    'gift_none' => 'No gifts.',
    'type_or_pick' => 'Type or pick an item…',
    'search_customer' => 'Search customer…',

    /*
     * An unfinished invoice, kept through a refresh or a power cut.
     *
     * ⚠️ A statement, not a question — the two buttons ask it. "Restore it?"
     * would suggest the system wants to; the decision is wholly the user's,
     * because the cost of the wrong one is a whole invoice.
     */
    'draft_found' => 'An unfinished invoice is waiting',
    'no_customer_match' => 'No customer matches that.',
    'pick_an_item' => 'Pick an item',
    'upcoming' => 'coming soon',
    // Recall — where did this lot go
    'trace_note' => 'Pick a lot to see what is still on the shelf and who received the rest.',
    'trace_on_hand' => 'Still on the shelf',
    'trace_on_hand_note' => 'This much can be stopped right now — take it off sale.',
    'trace_gone' => 'With customers',
    'trace_gone_note' => 'Went to :count customers — they need a phone call.',
    'trace_nobody' => 'None of this lot has gone out yet.',
    // Counter shift
    'shift_opened' => ':till is open. The closing count goes in here at the end of the day.',
    'shift_closed' => 'Shift closed — the figures are below.',
    'shift_note' => 'Someone answers for the drawer. Enter what you counted on opening, count again and close at the end.',

    // The queue screen — the paper did not come out, the sale did not wait
    'print_queue_note' => 'The sale finished; the paper did not come out. When the printer is working, press again from here.',
    'print_queue_empty' => 'Every paper came out',
    'print_queue_empty_note' => 'Nothing is waiting. If a printer jams, those papers collect here.',
    'print_job_settled' => ':no — taken off the waiting list.',
    'print_waiting' => 'Not printed',
    'print_failed' => 'Failed',
    'shift_none_open' => 'You have no shift open.',
    'shift_no_till' => 'Someone is at every drawer right now.',
    'shift_opening' => 'Counted at open',
    'shift_cash_in' => 'Came in',
    'shift_cash_out' => 'Went out',
    'shift_expected' => 'Should be in the drawer',
    'shift_counted' => 'Counted',
    'shift_difference' => 'Difference',
    'shift_short' => 'Short — and now there is someone to ask.',
    'shift_over' => 'Over — a collection somewhere was not written down.',
    'shift_matched' => 'It matches.',
    'shift_bills' => 'Bills this shift',
    'shift_today' => 'Closed today',
    'pos_keys' => 'F2 payment · F4 hold · F8 search · Esc clear · Enter in payment completes the sale',
    'pos_not_found' => 'No product for this code:',
    'no_brand' => 'No brand set',
    'pos_return_narration' => 'Counter return against :no',
    'pos_refund_narration' => 'Cash refunded against :no',
    'pos_returned' => ':no — :amount taken back.',
    'pos_keys' => 'Keyboard shortcuts',
    'key_help' => 'This list',
    'key_paid' => 'Jump to the amount',
    'key_return' => 'Take back against a bill',
    'key_hold' => 'Hold the cart',
    'key_split' => 'Split the payment',
    'key_customer' => 'Pick the customer',
    'key_search' => 'Find a product',
    'key_exact' => 'Exact amount',
    'key_checkout' => 'Complete the sale',
    'key_close' => 'Close',

    /*
     * Direct Sales shortcuts — deliberately the POS keys.
     *
     * ⚠️ Somebody who learned F8 = search on POS should not have to learn a
     * second set here. At a counter the habit is the speed.
     *
     * ⓘ F4 is left free on purpose: on POS it parks a bill, and that action
     * is coming to this screen too. Spending it now would mean taking it
     * back later.
     */
    /*
     * Out of stock — the centre-screen notice.
     *
     * ⚠️ The heading is three words on purpose: the customer is standing
     * there and nobody reads a paragraph at a counter. What failed goes in
     * the heading, what to do about it goes underneath.
     */
    'no_stock_title' => 'No stock',
    'no_stock_hint' => 'This product cannot be sold right now. Receive stock and try again, or pick another product.',

    'key_chart' => 'Chart Entry',
    'key_add_line' => 'Add to cart',
    'direct_keys' => 'Keyboard shortcuts',
    'pos_return' => 'Take back against a bill',
    'pos_refund_cash' => 'Refund the cash from the drawer',
    'pos_bill_not_found' => 'No confirmed bill with that number was found.',
    'collected_amount' => ':amount collected',
    'returned_amount' => ':amount returned',
    'shipment_created' => 'Trip sheet created — press Dispatch when the van leaves.',
    'shipment_updated' => 'Trip sheet updated.',
    'shipment_dispatched' => 'The van has left.',
    'shipment_line_settled' => 'That row is settled.',
    'shipment_closed' => 'Trip finished — every challan is accounted for.',
    'shipment_cancelled' => 'The trip was cancelled; its challans are free again.',
    'targets_saved' => 'Targets saved.',

    'pos_needs_approval' => 'This discount needs the manager. If they are beside you, let them type their own email and password — the sale then goes through at once.',

    // ডিলারের কমিশন — কোম্পানির কাছে দাবি
    'commission_claim_on' => 'Commission for :name',
    'commission_saved' => 'Commission recorded — :no.',
    'commission_settled' => 'Adjusted against the principal.',
    'commission_rejected' => 'The claim has been written off.',

    // ডিলারের কমিশন
    'commission_note' => 'Commission given to a dealer is claimed back from the principal — it is not your discount, so it never touches your margin.',
    'no_commissions' => 'No commission has been recorded yet.',
    'commission_nothing_charged' => 'Nothing was charged on this invoice.',
    'commission_no_scheme' => 'No scheme was live on :date.',
    'commission_no_rule_matched' => 'A scheme is live, but no band covers this amount — check that the top band is left open.',
    'scheme_note' => 'Who earns what — the rule is written here, not carried in someone’s head.',
    'scheme_created' => 'Scheme :code created. Now add its bands.',
    'scheme_rule_added' => 'Band added.',
    'scheme_activated' => 'Scheme is live — invoices will earn from now on.',
    'scheme_cancelled' => 'Scheme stopped.',
    'scheme_no_band' => 'No band has been set — a scheme with no band pays nothing.',
    'no_scheme' => 'No schemes yet.',
    'scheme_lapsed_note' => 'Past its end date — this scheme no longer earns on any invoice, even though it still reads as live.',
    'scheme_basis_hint' => 'On money or on quantity — the bands are counted in the same unit.',
    'leave_top_band_open' => 'Leave this empty on the highest band, or the biggest sale of the year earns nothing.',
    'fixed_beats_rate' => 'A fixed amount wins; the rate is then ignored.',
];
