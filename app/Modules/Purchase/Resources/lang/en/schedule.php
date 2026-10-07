<?php

declare(strict_types=1);

/*
 * Payment schedule — finance map §6.
 */
return [
    'title' => 'Payment schedule',
    'subtitle' => 'Which supplier to pay how much, and when — by bill due date',
    'all' => 'All unpaid',
    'overdue' => 'Overdue',
    'week' => 'Within 7 days',
    'month' => 'Within 30 days',
    'later' => 'Later',
    'due_on' => 'Due',
    'days_late' => ':days days late',
    'days_left' => ':days days left',
    'today' => 'Today',
    'bill' => 'Bill',
    'supplier' => 'Supplier',
    'total' => 'Bill total',
    'due' => 'Due',
    'pay' => 'Pay',
    'none' => 'No bill to pay in this group.',
    'propose' => 'Propose',
    'make_proposal' => 'Make the proposal',
    'proposal_hint' => 'Only the bills you give an amount; one draft payment per supplier. Approve and pay from the payments list.',
    'proposed' => ':count draft payments made: :numbers. Confirm them after approval.',
    'proposal_title' => 'Payment proposal',
    'proposal_in_list' => 'In the payments list',
    'proposed_by' => 'Proposed by :name, :date',
    'proposal_state' => 'State',
    'state_draft' => 'Draft; confirm asks for the signature',
    'state_awaiting_signature' => 'Awaiting signature',
    'state_signed_awaiting_payment' => 'Signed; not paid yet',
    'state_refused' => 'Signature refused',
    'state_paid' => 'Paid',
    'state_cancelled' => 'Cancelled',
];
