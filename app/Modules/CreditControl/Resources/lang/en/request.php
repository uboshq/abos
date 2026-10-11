<?php

declare(strict_types=1);

// Credit limit raise requests — Credit Control phase 2.5 (owner's answer 5)
return [
    'label' => 'Limit raise request :no — :customer',
    'no_key' => 'You do not have the key to write a limit raise request.',
    'owner_sets_directly' => 'You are the super admin — set the limit yourself from "Set directly" below instead of requesting it.',
    'approvals_off' => 'Limit raise approvals are off in this company (no active flow), so a request cannot be written. Only the super admin can set a limit, directly.',
    'not_a_raise' => 'A request is only for raising — the requested limit must be above the current limit (:limit). Lowering needs no request.',
    'one_pending' => 'This customer already has a request (:no) waiting for a signature — let that one be decided first.',
    'asks_limit' => 'Request to raise the credit limit from :from to :to — :reason',
    'asks_temporary' => 'Request for a temporary limit of :to until :until (now :from) — :reason',
    'owner_only' => 'Only the owner (super admin) can set a limit directly.',
    'bad_kind' => 'Unknown request kind — normal limit or temporary limit?',
    'bad_amount' => 'The limit amount is not valid.',
    'reason_needed' => 'Please give a reason.',

    'title' => 'Limit raise request',
    'direct_title' => 'Set directly (owner)',
    'kind' => 'Kind',
    'kind_limit' => 'Normal limit',
    'kind_temporary' => 'Temporary limit',
    'amount' => 'New limit',
    'until' => 'Ends on',
    'file' => 'Paper (optional)',
    'submit' => 'Send for the owner\'s signature',
    'direct_submit' => 'Set',
    'saved' => 'The request waits for the owner\'s signature — the current limit applies until then.',
    'direct_saved' => 'The limit was set and written to the history.',
    'list' => 'Requests',
    'none' => 'No requests',
    'status_pending' => 'Waiting for signature',
    'status_approved' => 'Signed by the owner',
    'status_rejected' => 'Rejected',
    'status_expired' => 'Expired',
    'sheet_customer' => 'Customer',
    'sheet_now' => 'Current limit',
    'sheet_asked' => 'Requested limit',
    'sheet_exposure' => 'Used now',
    'sheet_by' => 'Requested by',
];
