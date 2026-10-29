<?php

declare(strict_types=1);

/*
 * Limit-raise request — owner's answer 5, 11 Oct 2026 (credit control phase 2.5; [[CreditLimitRequests]]).
 */
return [
    'not_a_raise' => 'A request is only for raising — the asked limit must be above today\'s :limit. To lower it, change it on the customer page.',
    'until_past' => 'The temporary limit must end today or later.',
    'approvals_off' => 'Approvals are off in this company — only the owner (super admin) can raise a limit, directly.',
    'owner_not_in_flow' => 'The owner is not on the credit-limit approval flow — no limit rises without the owner\'s approval. Put the owner on the flow first.',
    'already_waiting' => 'A request for this customer is already waiting for an approval — a new one after it is decided.',
    'no_owner_signature' => 'Approved, but not by the owner — the limit was not set.',
    'limit_moved' => 'Approved, but the limit changed another way after the request — the old paper set nothing. Raise a new request if needed.',
    'already_higher' => 'Approved, but the limit had meanwhile reached or passed the amount asked — it was not lowered.',
    'word_limit' => ':customer — credit limit :from to :to. Reason: :reason',
    'word_temporary' => ':customer — temporary limit :to until :until (now :from). Reason: :reason',
    'saved' => 'The request went to the owner for approval — the limit does not change before it is approved.',
    'saved_direct' => 'The limit is set (the owner\'s direct change in a company with approvals off).',
    'customer' => 'Customer',
    'title' => 'Ask for a higher limit',
    'kind' => 'What is asked',
    'kind_limit' => 'Raise the normal limit',
    'kind_temporary' => 'Temporary limit',
    'amount' => 'Asked limit',
    'temp_until' => 'Temporary limit ends on',
    'reason' => 'Reason',
    'attachment' => 'Attachment',
    'send' => 'Send for approval',
    'help' => 'It takes effect only when the owner approves; until then the old limit stands. A temporary limit falls away by itself the day after its last day.',
    'list' => 'Requests',
    'none' => 'No requests',
    'asked_on' => 'When',
    'asked_by' => 'Asked by',
    'from_to' => 'Before → asked',
    'status' => 'Status',
    'status_pending' => 'Waiting for approval',
    'status_approved' => 'Approved — set',
    'status_rejected' => 'Rejected',
    'status_cancelled' => 'Withdrawn',
    'status_expired' => 'Expired',
    'status_void' => 'No owner approval — not set',
    'waiting_title' => ':customer — a credit-limit raise waits for your approval',
];
