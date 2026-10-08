<?php

declare(strict_types=1);

return [
    'title' => 'Expense claims & advances',
    'new' => 'New claim or advance',
    'send' => 'Send',
    'list_note' => 'Your expense claims and cash advance requests — once signed, the cashier pays.',
    'tab_mine' => 'Mine',
    'tab_all' => 'Everyone',
    'none' => 'No claims yet.',
    'open_advance' => 'Your open advance',
    'open_advance_note' => 'An expense claim is settled from this advance first; the rest in cash.',

    'kind' => 'Kind',
    'kind_expense' => 'Expense claim',
    'kind_advance' => 'Cash advance request',
    'employee' => 'Employee',
    'head' => 'Expense head',
    'head_pick' => 'Pick a head',
    'amount' => 'Amount',
    'spent_on' => 'Spent on',
    'reason' => 'Reason',
    'receipt' => 'Receipt photo',
    'receipt_hint' => 'Image or PDF, up to 5 MB — the signer sees it on an expense claim.',
    'status' => 'Status',
    'number' => 'Number',
    'from_advance' => 'From advance',
    'cash' => 'In cash',
    'payment_voucher' => 'Payment voucher',
    'settle_voucher' => 'Settlement from advance',
    'requested_by' => 'Sent by',
    'submitted_at' => 'Sent',
    'decided_at' => 'Decided',
    'paid_at' => 'Paid',

    'state_submitted' => 'Awaiting signature',
    'state_approved' => 'Approved — awaiting payment',
    'state_paid' => 'Paid',
    'state_rejected' => 'Rejected',

    'sent_submitted' => ':no sent — awaiting signature.',
    'sent_approved' => ':no approved — the cashier will pay.',
    'sent_paid' => ':no was settled in full from your advance.',
    'sent_rejected' => ':no was rejected.',

    'cashier_note' => 'Cashier: put your own till on the draft voucher and post it — cash only leaves your own till.',

    'narration_expense' => ':who — expense claim :no',
    'narration_advance' => ':who — cash advance :no',
    'narration_from_advance' => ':who — expense claim :no, from advance',

    'no_employee' => 'There is no employee record in your name. HR or the owner: HR → Employees → your name → Edit → pick you in "System User" and save (if you are not an employee yet, add one first).',
    'kind_bad' => 'Pick expense claim or cash advance request.',
    'amount_bad' => 'The amount must be above zero.',
    'reason_required' => 'Write the reason.',
    'head_bad' => 'Pick an expense head — a postable, active expense account.',
    'spent_in_future' => 'The spending date cannot be after today.',
    'own_claim_signed' => ':no — signed by the same person who sent it.',
    'no_owner_to_sign' => 'This company does not have exactly one owner (super_admin) role, so nobody can be set to sign the claim. Tell the owner.',
    'head_missing' => 'Account :code is missing from the chart.',
];
