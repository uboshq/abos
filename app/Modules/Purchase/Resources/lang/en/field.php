<?php

declare(strict_types=1);

return [
    'supplier' => 'Supplier',
    'product' => 'Product',
    'gift' => 'Gift',
    'gift_against' => 'Came with',
    'warehouse' => 'Warehouse',
    'branch' => 'Branch',
    'ordered' => 'Ordered',
    'received' => 'Received',
    'pending' => 'Pending',
    'unbilled' => 'Not billed',
    'unbilled_value' => 'Value not billed',
    'bill_count' => 'Bills',
    'subtotal' => 'Subtotal',
    'discount' => 'Discount',
    'tax' => 'VAT',
    'total' => 'Total',
    'rate' => 'Rate',
    'quantity' => 'Quantity',
    'amount' => 'Amount',
    'document_no' => 'Number',
    'date' => 'Date',
    'expected_on' => 'Expected on',
    'due_on' => 'Due on',
    'supplier_challan_no' => 'Supplier\'s challan no',
    'supplier_bill_no' => 'Supplier\'s bill no',
    'narration' => 'Narration',
    'status' => 'Status',
    'order' => 'Purchase order',
    'receipt' => 'Receipt',
    'unit' => 'Unit',
    'line_no' => 'No',

    // Payment fields
    'bill' => 'Bill',
    'account' => 'Paid from',
    'instrument' => 'Method',
    'ref_date' => 'Ref. date',
    'instrument_no' => 'Cheque / reference no',
    'instrument_date' => 'Cheque date',

    // Return fields
    'reason' => 'Reason',
    'state' => 'State',

    // Sales price — on the purchase paper, because the rate is what sets it
    'sales_price' => 'Sales price',
    'markup' => 'Markup %',
    'margin' => 'Margin %',
    'trx_date' => 'Invoice date',
    'qty' => 'Qty',
    'free_qty' => 'Free',
    'line_total' => 'Line total',
    'sub_total' => 'Sub total',
    'net_payable' => 'Net payable',
    'paid_now' => 'Paid now',
    'paid_from' => 'Paid from',
    'balance_due' => 'Balance due',

    // The owner's totals card — three separate rows, not one
    //
    // The next two are not on the screen yet: the fields were pulled
    // because nothing carries them to the ledger (see the totals card
    // in direct/index.blade). The words stay so the work returns whole.
    'expense' => 'Expense',
    'rounding' => 'Rounding',
    'invoice_due' => 'Invoice Due',
    'previous_due' => 'Previous Due',
    'previous_advance' => 'Previous Advance',
    'total_due' => 'DUE',
    'total_item' => 'Total items',
    'total_qty' => 'Total qty',

    /*
     * The four counts at the foot of the totals card.
     *
     * 'total_qty' used to carry both bought and free in one number,
     * which answered neither question: what did we buy, and what came
     * free. The free quantity is what decides the real purchase rate,
     * so it gets a line of its own.
     */
    'total_bought_qty' => 'Total purchase qnty',
    'total_free_plus_bought' => 'Total free + purchase qty',
    'bill_total' => 'Bill total',

    /*
     * The import shipment — five boxes that simply hold what the bank
     * and customs will ask for later.
     *
     * They are free-text boxes, not lists: ports and vessels differ
     * from one buyer to the next, and a list that differs per customer
     * belongs in settings rows, never in code. Until those rows exist,
     * an empty box is more honest than a wrong dropdown.
     */
    'shipment' => 'Import shipment',
    'lc_no' => 'LC no.',
    'be_no' => 'Bill of entry no.',
    'be_date' => 'Bill of entry date',
    'vessel' => 'Vessel / flight',
    'port_of_entry' => 'Port of entry',

    /*
     * The counter screen the owner drew (4 September 2026).
     *
     * Two cards: goods on the left, the supplier and the receipt on the
     * right. These are the words on his pictures, kept as they were
     * written — the people at the counter will read them out loud.
     */
    'search_item' => 'Search item',
    /*
     * Two dates, and they are not the same day.
     *
     * 'billing_date' is what the supplier printed on their paper, and
     * the ledger goes by it — the liability is born on the bill's date.
     * 'received_on' is the day the lorry actually reached the gate, and
     * the stock goes by that one.
     *
     * A mill bills on the 2nd and the truck arrives on the 5th. One box
     * for both meant one of the two was always a lie, and which one
     * depended on who was filling it in.
     */
    'billing_date' => 'Billing date',
    'received_on' => 'Received on',

    /*
     * Our own number for this purchase, not theirs.
     *
     * The box opens already filled with the next number in the series,
     * and it can be edited. What is shown is a forecast, not a promise:
     * two people opening the counter at once see the same number, and
     * whoever saves first gets it.
     */
    'pur_inv_no' => 'Pur. INV no.',

    /*
     * 'credit_period' asks how long we have to pay — the buying side of
     * the words on his picture. 'credit_cash' is the empty option: no
     * term at all, which is what paying on the spot means.
     */
    /*
     * The third box on the paper's head — the owner's `Payment Terms`.
     *
     * Seven options, and every one of them ends at a date. Four are
     * behaviours (cash, COD, month end, a date of your own); the rest
     * are day counts, and those come from settings rows because one
     * buyer says five days and the next says forty-five.
     *
     * 'Date Range' is deliberately absent: one payable has one due
     * date. A range would leave the ageing report with nowhere to put
     * the bill.
     */
    'terms' => 'Terms',
    'term_cash' => 'Cash',
    'term_credit' => ':count days Cr',
    'term_month_end' => 'Cr. upto closing date',
    'term_fixed' => 'A fixed date',

    'credit_period' => 'Credit period',
    'credit_cash' => 'Cash',
    'credit_fixed_date' => 'A fixed date',

    /*
     * The company's own default, when no payment term carries that many
     * days yet. A term is a policy with a name; this is just the number
     * of days the company set, wearing the same clothes.
     */
    'credit_days' => ':count days',

    // The panel on the left of the header card — his own words
    'supplier_details' => 'Supplier details',
    'their_invoice' => 'Their invoice no.',
    'purchase_rate' => 'Purchase rate',
    'sales_rate' => 'Sales rate',
    'free_unit' => 'Free unit',
    'line_qty_total' => 'Total qty.',
    'this_line' => 'This line',
    'total_amount' => 'Total amount',
    /*
     * Just "Discount" — the owner's word, 6 September 2026.
     *
     * The panel is titled THIS LINE, so "on this line" repeated the
     * heading in every row it sat in. It also outgrew the narrow panel
     * and squeezed the figures beside it.
     *
     * A label earns its length only when it says something the box
     * around it does not.
     */
    'discount_on_line' => 'Discount',
    'net_value' => 'Net value',
    'in_cart' => 'In cart',
    'running_total' => 'Running total',
    'items_count' => ':count items',

    // The supplier card
    'mobile' => 'Mobile',
    'address' => 'Address',
    'proprietor' => 'Proprietor',
    'received_by' => 'Received by',
    'remarks' => 'Remarks',

    /*
     * The receipt block.
     *
     * 'vat_part_of_cost' is a sentence, not a label, and that is the
     * point: the words themselves say the VAT is inside the cost, not
     * something added on top. A bare 'VAT' left people adding it twice.
     *
     * Three of these are not on the screen: 'this_receipt',
     * 'free_received' and 'to_pay_supplier'. They came from the first
     * two pictures, where the right card carried its own headings; the
     * screenshot of 5 September replaced that card with INV TOTAL, and
     * the free quantity moved down into the four counts.
     *
     * They stay written because the pictures are still the spec, and
     * the day one of those headings comes back nobody should have to
     * word it twice — in two languages, agreeing.
     */
    'this_receipt' => 'This receipt',
    'sub_total_goods' => 'Sub total (goods)',
    'vat_part_of_cost' => 'VAT — part of the cost',
    'free_received' => 'Free received',
    'to_pay_supplier' => 'To pay this supplier',

    /*
     * How the VAT on a line is decided.
     *
     * The three modes are not new powers — they are the three paths
     * CalculatesLineTotals already walks, given a name. Until now the
     * screen had one empty box, and leaving it empty did something
     * quite different from typing 0, with nothing to say so.
     */
    'vat_mode' => 'VAT',
    'vat_mode_product' => 'Per product',
    'vat_mode_amount' => 'Enter amount',
    'vat_mode_none' => 'No VAT',

    /*
     * The costing panel — what the goods really cost, free counted in.
     *
     * Not on the screen: the owner said on 5 September that the Costing
     * button does not belong in the line box ("ekhane lagbe na"), so the
     * button and the panel came off together — an unreachable panel is
     * worse than none.
     *
     * The words stay because the figure itself is still worth having:
     * ten cartons with two free means the real rate is the line net over
     * twelve, not ten, and nobody can see that number anywhere today. If
     * it comes back it belongs beside the six buttons under the totals
     * card, where a bill-wide reading fits.
     */
    'cost_bought' => 'Bought',
    'cost_free' => 'Free',
    'cost_in_hand' => 'In hand',
    'cost_line_net' => 'Line net',
    'cost_per_unit' => 'Real cost per unit',
    'cost_against_rate' => 'Against the written rate',

    // The remarks box on the supplier card — a placeholder, not a label
    'optional' => 'optional',

    /*
     * The payment panel — one bill, several ways of paying it.
     *
     * 'paid_how' is the empty option on the method list. It reads as a
     * question because the list is not a filter: nothing can be added
     * until it is answered.
     */
    'paid_how' => 'How was it paid',
    'paid_total' => 'Paid now',
    'reference' => 'Cheque / transaction no.',

    /*
     * Who brought the goods, and what the ride cost.
     *
     * The same four names as the sales side uses on a challan, on
     * purpose: one word should mean one thing in both directions.
     */
    'carrier' => 'Who brought it',
    'carrier_name' => 'Carrier name',
    'transport_cost' => 'Freight',
    'vehicle_no' => 'Vehicle no.',
    'driver_name' => 'Driver',
];
