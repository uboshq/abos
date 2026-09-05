<?php

declare(strict_types=1);

return [
    'supplier' => 'Supplier',
    'product' => 'Product',
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

    /* Values that sit outside a rule — written on the line itself. */
    'off_rule' => 'Outside the rule',
    'off_standard_price' => 'rate :pct%',
    'off_standard_tax' => 'VAT :amount',
    'quantity' => 'Quantity',
    'amount' => 'Amount',
    'document_no' => 'Number',
    'date' => 'Date',
    'expected_on' => 'Expected on',
    'due_on' => 'Due on',
    'supplier_challan_no' => 'Supplier\'s challan no',
    'supplier_bill_no' => 'Supplier\'s bill no',
    'narration' => 'Narration',
    'notes' => 'Notes',
    'status' => 'Status',
    'order' => 'Purchase order',
    'receipt' => 'Receipt',
    'unit' => 'Unit',
    'line_no' => 'No',
    'state' => 'State',
    'customer' => 'Customer',
    'delivered' => 'Delivered',
    'uninvoiced' => 'Not invoiced',
    'uninvoiced_value' => 'Value not invoiced',
    'invoice_count' => 'Invoices',
    'gross_profit' => 'Gross profit',
    'revenue' => 'Revenue',
    'cost' => 'Cost of goods',
    'margin_percent' => 'Margin %',
    'product_count' => 'Products',
    'deliver_on' => 'Deliver on',
    'vehicle_no' => 'Vehicle no',
    'driver_name' => 'Driver',
    'challan' => 'Challan',
    'invoice' => 'Invoice',
    'collected' => 'Collected',
    'due' => 'Due',
    /*
     * ⚠️ "Money went to" ছিল, "Received into" হলো — মালিকের নির্দেশ
     * (৪ সেপ্টেম্বর ২০২৬)।
     *
     * ⭐ চাবিটা ছয় জায়গায় ব্যবহার হয়, আর ছয়টাই **টাকা আসার** কাগজ:
     * আদায়ের ফর্ম, তালিকা, রেকর্ড, ছাপা, আর সরাসরি বিক্রয়ের জমা।
     * তাই একটাই নাম যথেষ্ট, আর সেটাই ঠিক — এক জিনিসের দুইটা নাম হলে
     * রিপোর্টে ওরা মেলে না।
     *
     * ⓘ "went to" শব্দটা দিক বলত না — টাকা কোথাও গেলে সেটা প্রদানও
     * হতে পারে। "Received into" বলে দেয় টাকাটা **এসেছে**, আর কোন
     * খাতে এসেছে।
     */
    'account' => 'Received into',
    'instrument' => 'Instrument',
    'instrument_no' => 'Cheque / reference no',
    'instrument_date' => 'Cheque date',
    'cost_of_goods' => 'Cost of goods sold',
    'paid' => 'Paid',
    'change' => 'Change',
    /*
     * ⚠️ Was "have", now "Available" (3 Sep 2026).
     *
     * "have" read as what is sitting in the warehouse, which this number is
     * not: it is what is left after reserved and held are taken off — what
     * can actually be sold right now. "Main Stock" sits beside it, and with
     * two numbers so close nobody could tell which was which.
     */
    /*
     * The previous figure and the total change their name with their sign
     * (3 Sep 2026).
     *
     * ⚠️ "Balance" is a neutral word for a number that runs both ways. At a
     * counter, "500" tells nobody whether the party owes it or is owed it —
     * and getting that backwards costs money.
     *
     * So the figure is always shown positive and the direction is the label.
     */
    'previous_due' => 'Previous Due',
    'previous_advance' => 'Previous Advance',
    'due' => 'Due',
    'advance' => 'Advance',
    /* More paid than the bill — the rest stays to the customer's credit. */
    'kept_as_advance' => 'Kept as advance',

    'available_short' => 'Available',
    'main_stock' => 'Main Stock',
    'hold_short' => 'Hold',
    'free_stock' => 'Free Stock',
    /* ⚠️ One word — the owner's instruction (3 Sep 2026): *"Free
       Available = Free likho sudu"*. The figure sits in a row of five
       stock numbers where every character costs width, and "Available"
       was already the label of the number two places to its left —
       repeating it made the eye compare two labels instead of reading
       one. Free stock is only ever the free stock that is available. */
    'free_available' => 'Free',
    'in_cart' => 'In cart',
    'do_no' => 'DO No.',
    'terms' => 'Terms',

    /*
     * The five kinds a counter can put on a sale.
     *
     * Four are behaviours; the day counts come from settings rows,
     * because one buyer says seven days and the next says forty-five.
     *
     * Cash and COD both end on today's date, and they are still two
     * different things: cash means the money is in the drawer, COD
     * means the goods went out on the van and the money comes back
     * with the delivery man. One is collected, the other is owed.
     *
     * The same word is meaningless on a direct purchase — that paper
     * is written after the goods have already arrived — and it was
     * taken off that screen on the owner's word, 5 September 2026.
     */
    'term_cash' => 'Cash',
    'term_cod' => 'COD',
    'term_credit' => ':count days Cr',
    'term_month_end' => 'Cr. upto closing date',
    'term_fixed' => 'A fixed date',
    'free_qty' => 'Free Qty',
    'gift_item' => 'Gift Item',
    'gift_for' => 'Gift Item For',
    'remarks' => 'Remarks',
    'expense' => 'Expense',
    'rounding' => 'Rounding',
    'deposit' => 'Deposit',
    'net_payable' => 'Net Payable Amount',
    'invoice_due' => 'Invoice Due',
    'previous_balance' => 'Previous Balance',
    'outstanding' => 'Outstanding Amount',
    'total_item' => 'Total Item',
    'total_sales_qty' => 'Total Sales Qnty',
    'total_free_qty' => 'Free Qty.',
    'discount_amount' => 'Discount',
    'proprietor' => 'Proprietor',
    'credit_limit' => 'Credit Limit',

    /*
     * ⚠️ What the counter actually asks (owner, 3 Sep 2026):
     * *"Credit Limit 75,000.00 bad ... address line ekdom dane thakbe
     * Avelable Cr. Limit"*.
     *
     * The limit is a number from a contract; this is the number from the
     * counter — the limit less everything the party already owes, this
     * invoice included.
     *
     * 'Cr.' is kept short on purpose: the label sits at the far right of
     * the address line and must not push the address into a second row.
     */
    'available_credit' => 'Available Cr. Limit :',

    /* A limit of zero stops credit, not goods — so it is said in words,
       never as "0", which reads as "nothing may be sold". */
    'cash_only' => 'cash / advance',
    'reserved_short' => 'Reserved',
    'total_qty' => 'Total Qty',
    'challan_date' => 'Invoice Date',
    'inv_number' => 'INV Number',
    'on_confirm' => 'on confirm',
    'days' => 'days',
    'due_on' => 'or a fixed date',
    'invoice_no_editable' => 'change it if you need to',
    'optional' => 'optional',
    'qty' => 'Qty.',
    /* One-word labels for the picker rows — the number is the point,
       the word only says which number it is. */
    /* ⚠️ Both capitalised — the owner's correction (6 Sep 2026):
       *"Free eivabe liko"*.

       ⓘ His first note wrote it as *"Stock 8979 ctn, free 675 ctn"*, so
       the small `f` shipped. ⛔ On the row they sit side by side, and one
       capital beside one lowercase reads as a mistake, not a distinction —
       they are two labels of the same kind. */
    'stock_short' => 'Stock',
    'free_short' => 'Free',
    'uom' => 'UoM',
    /*
     * ⚠️ One word, not two — the owner's instruction (3 Sep 2026):
     * *"Sales Price/Rate — zekono ekta likho, hoy 'rate' noy 'sales price'"*.
     *
     * A slash between two words is a label that could not make up its mind,
     * and it costs twice the width for no extra meaning.
     *
     * ⭐ Which of the two, he settled on 6 Sep 2026: *"Direct Sales e
     * 'Sales Price' change kore 'Sales Rate' likho"*.
     *
     * ⓘ It also puts the English back in step with the Bangla, which has
     * read "বিক্রয় দর" — literally *sales rate* — all along. ⚠️ The two
     * languages had been naming the same box differently, and nobody
     * comparing one screen in one language would ever have seen it.
     *
     * ⛔ It does cost something, and the note is left here on purpose:
     * the cart column beside it still reads "Unit Price", so the pair
     * now says *rate* in one place and *price* in the other. ⓘ Raised
     * with him; his call, not a thing to fix quietly.
     */
    'sales_rate' => 'Sales Rate',
    'total_amount' => 'Total Amount',
    /* The line's discount and the paper's discount were both just
       "Discount", on the same screen. The field-switch guard caught it —
       turning the line one off left the other behind — but the real
       problem was that a reader could not tell them apart either.

       ⭐ Short again, the owner's instruction (6 Sep 2026): *"this line
       box e 'Discount on this line' poriborte sudu 'Discount' likho"*.

       ⓘ What changed since is the box around it: the field now sits
       inside a panel headed **"This line"**, so the container already
       says which discount this is. ⚠️ The label was repeating its own
       heading — five words to say what the box said above them.

       ⛔ The old ambiguity is only handled while that heading stays. If
       this field is ever moved out of the "This line" panel, the two
       discounts read the same again — and that is exactly the bug the
       note above records. */
    'line_discount' => 'Discount',
    'discount_pct' => 'Discount %',
    'net_value' => 'Net Value',
    'vat' => 'VAT',

    /*
     * Whole-document VAT override (3 Sep 2026). "Per product" is the default
     * and the ordinary case; the override is for the invoice that genuinely
     * is one rate — an export, an exempt customer, a rate agreed in writing.
     */
    'vat_per_product' => 'Per product',
    'vat_per_product_hint' => 'Each line takes the rate its own product declares. Change this only when the whole invoice really is one rate.',
    'vat_exclusive' => 'VAT added',
    'vat_inclusive' => 'VAT included',
    'vat_exempt' => 'No VAT',
    'vat_rate_for_every_line' => 'This rate applies to every line on this invoice.',
    'this_line' => 'This Line',
    'gift' => 'Gift',
    'costing' => 'Costing',
    'items' => 'items',
    'running_total' => 'Running total',
    'sl' => 'SL#',
    'item_name' => 'Item Name',
    'unit_price' => 'Unit Price',
    'free_unit' => 'Free Unit',
    'dis' => 'Dis.',
    'this_challan' => 'This Challan',
    /*
     * ⚠️ Short on purpose — the owner's instruction (3 Sep 2026):
     * *"Invoice Total Amount → poriborton kore 'INV Total' likho"*.
     *
     * The label sits beside a large figure in a narrow panel, and three words
     * cost a line break there. "Amount" said nothing the ৳ sign was not
     * already saying, and "INV" is the same abbreviation the number field
     * above it already uses.
     */
    'invoice_total' => 'INV Total',
    'sub_total_no_vat' => 'Sub Total (without VAT)',
    'amount_or_pct' => 'amount or %',
    'to_pay_on_this' => 'To pay on this challan',
    'received_deposit' => 'Received Deposit',
    'what_party_owes' => 'What this party owes',
    'quantities' => 'Quantities',
    'total_free_plus_sales' => 'Total Free+Sales Qty',
    'vehicle' => 'Fleet Vehicle',
    'vehicle_not_in_fleet' => 'Not in the fleet',

    // Return fields
    'reason' => 'Reason',
    'not_sellable' => 'Not sellable again',

    // What sales has to say about a customer — shown on the customer's page,
    // contributed from here (see SalesFacts)
    'last_purchase' => 'Last purchase',

    /* A tile on the customer's page — a smart button in Odoo, a fact row elsewhere. */
    'invoice_count' => 'Invoices',
    'user' => 'User',
    'time' => 'Time',
    'till' => 'Drawer',
    'paper' => 'Paper',
    'print_failure' => 'What went wrong',
    'cash' => 'Cash',

    // কাউন্টারে ছাড়ের অনুমোদন — ম্যানেজারের নিজের লগইন
    'approver_email' => "Manager's email",
    'approver_password' => "Manager's password",

    // ডিলারের কমিশন
    'commission_base' => 'Base',
    'commission_percent' => 'Rate %',
    'commission_flat' => 'Flat amount',
    'commission_rate' => 'Rate',
    'commission_pending' => 'Pending',
    'commission_settled' => 'Settled',
    'commission_rejected' => 'Refused',
    'commission_pending_total' => 'Claimed and still outstanding',
    'commission_reject_reason' => 'Why it was refused',
    'expense_for' => 'What the expense is for',
    'expense_for_hint' => 'Fare · loading · tea',

    /*
     * ⚠️ The question and the examples in one box (owner, 3 Sep 2026).
     *
     * They used to be split: the question in a label, the examples inside
     * the field. The field now sits on its own row with no label, and the
     * examples alone would say what it might look like without saying what
     * is being asked for.
     */
    'expense_for_placeholder' => 'What the expense is for — fare, loading, tea',
    'carrier' => 'Carrier',
    /* A lorry hired once — no running account, so a name is enough. */
    'carrier_not_listed' => 'Not listed — I will type it',
    'carrier_name' => "Carrier's name",
    'transport_cost' => 'Transport cost',
    'ship_to' => 'Ship to',
    'ship_to_hint' => 'Shop, store or market',
    'ship_date' => 'Ship date',
    'deposit_method' => 'Method',
    /* ⓘ Not the cheque date — the reference's date. A bKash transaction
       sits here too, and it is not a cheque. It may be yesterday's, which
       is why it is not the collection date; that one is always today. */
    'ref_date' => 'Ref. Date',
    'deposit_ref' => 'Reference',
    'cheque' => 'Cheque',
    'mfs' => 'Mobile banking',
    'bank' => 'Bank',
    'note' => 'Note',
    'slab_above' => ':from and above',
    'slab_between' => ':from — :to',
    'scheme_code' => 'Code',
    'scheme_basis' => 'Counted on',
    'scheme_applies_to' => 'Aimed at',
    'scheme_valid' => 'Runs',
    'scheme_bands' => 'Bands',
    'scheme_search' => 'Search by code or name',
    'valid_from' => 'From',
    'valid_to' => 'To',
    'earner_role' => 'Who earns',
    'band' => 'Band',
    'band_open' => '(open)',
    'level_order' => 'Level',
    'fixed_amount' => 'Fixed amount',
    'slab_from' => 'Band from',
    'slab_to' => 'Band to',
    'choose' => 'Choose',
];
