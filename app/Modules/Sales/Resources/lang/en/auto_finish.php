<?php

declare(strict_types=1);

// A held counter sale finishes itself after the last signature — 28 September 2026.
return [
    'not_held' => 'Not a counter sale waiting for approvals',
    'no_signature' => 'No approval was asked for — finish it by hand',
    'waiting' => 'More approvals are still due',
    'rejected' => 'An approval was rejected — correct or cancel the sale',
    'maker_gone' => 'The person who made the sale is inactive or no longer in this company — finish it by hand',
    'audit' => 'Automatic — after the last approval (approved by: :signer; run in the name of the maker :maker)',
    'challan_audit' => 'Confirmed automatically after the last approval (approved by :signer; run as the maker, :maker)',
    'challan_stuck_title' => 'Approved, but challan :no was not confirmed',
    'challan_stuck_body' => ':reason - open the challan, put it right and press "Confirm" again; the approval stands.',
    'by_command' => 'one-off command',
    'tab' => 'Approved, not finished',
    'sale_stuck_title' => ':no was approved but the sale did not finish',
    'sale_stuck_body' => 'Reason: :reason. Retry it or return it to draft from the drafts list, "Approved, not finished".',
    'retry' => 'Retry',
    'return' => 'Return to draft',
    'retried' => ':no is finished.',
    'retry_stopped' => ':no did not finish yet: :reason',
    'returned' => ':no is back in draft. Change it and send it again.',
    'returned_reason' => 'Not finished after approving; returned to draft',
    'not_stuck' => 'This sale is no longer approved-but-not-finished. Reopen the list.',
    'return_only_maker' => 'Only whoever made the sale, or the owner, can return it to draft.',
];
