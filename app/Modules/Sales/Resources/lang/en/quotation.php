<?php

declare(strict_types=1);

/**
 * Sales quotation — NEXUS §8.
 */
return [
    'doc' => 'Sales Quotation',
    'menu' => 'Quotations',
    'approval' => 'Sales quotation',
    'list_note' => 'Prices quoted to dealers — with validity, one click to an order once accepted',
    'search' => 'Search number, customer or remarks',
    'empty' => 'No quotations yet.',
    'state_all' => 'All states',
    'lines' => 'Products and prices',
    'form_note' => 'If an approval rule is set, the quotation needs a signature before it reaches the dealer.',
    'credit_note' => 'The credit limit is not checked on a quotation — it applies at the challan and the bill.',
    'expired_banner' => 'This quotation expired on :date. To order from it, take it back to draft and extend the validity.',
    'converted_banner' => 'This quotation became the order:',

    'status' => [
        'draft' => 'Draft',
        'submitted' => 'Awaiting approval',
        'approved' => 'Approved',
        'sent' => 'Sent',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
        'converted' => 'Converted',
    ],

    'field' => [
        'valid_until' => 'Valid until',
        'price_list' => 'Price list',
        'payment_term' => 'Payment terms',
        'delivery_terms' => 'Delivery terms',
        'header_discount' => 'Overall discount',
        'answer_note' => "Dealer's answer",
        'order' => 'Sales order',
    ],

    'action' => [
        'new' => 'New quotation',
        'submit' => 'Submit',
        'approve' => 'Check approval',
        'send' => 'Mark as sent',
        'accept' => 'Dealer accepted',
        'reject' => 'Dealer declined',
        'revise' => 'Back to draft',
        'convert' => 'Create order',
        'show_cancelled' => 'Show cancelled too',
    ],

    'message' => [
        'created' => 'Quotation saved.',
        'updated' => 'Quotation updated.',
        'approved' => 'Quotation approved — it can now be sent to the dealer.',
        'sent' => 'Marked as sent to the dealer.',
        'accepted' => 'Dealer accepted — an order can now be created.',
        'rejected' => "The dealer's refusal was recorded.",
        'revised' => 'Quotation is a draft again — change it and submit again.',
        'cancelled' => 'Quotation cancelled.',
        'converted' => 'Sales order created from quotation :no — review and confirm it.',
        'reject_note' => 'Why the dealer declined',
        'accept_note' => "Dealer's remarks (optional)",
    ],

    'error' => [
        'only_draft_edits' => ':no is not a draft — take it back to draft before changing it.',
        'quotation_not_draft' => ':no is not a draft, so it cannot be submitted.',
        'quotation_not_submitted' => ':no is not awaiting approval.',
        'quotation_not_approved' => ':no is not approved yet — it cannot be sent to the dealer before approval.',
        'quotation_not_open' => ':no is not waiting for the dealer\'s answer.',
        'quotation_not_revisable' => ':no cannot go back to draft from this state.',
        'quotation_converted' => 'An order was already made from :no — cancel the order instead.',
        'quotation_not_accepted' => 'The dealer has not accepted :no — only an accepted quotation becomes an order.',
        'already_converted' => 'An order was already made from :no — one quotation cannot become two orders.',
        'expired' => ':no expired on :date — an expired price cannot move forward.',
        'total_moved' => 'The quotation totals :quoted but the order would total :now today — a product\'s VAT or price changed meanwhile. Take the quotation back to draft and review it.',
        'header_discount_over_total' => 'The overall discount cannot exceed the total of the lines.',
        'valid_before_date' => 'The validity cannot end before the quotation date.',
    ],

    'print' => [
        'not_approved' => 'Not approved — not a final price',
        'expired' => 'Expired — this price is no longer valid',
    ],

    'settings' => [
        'screen' => 'Quotation screen',
        'valid_days' => 'Default quotation validity (days)',
    ],
];
