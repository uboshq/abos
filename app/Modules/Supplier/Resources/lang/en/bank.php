<?php

declare(strict_types=1);

/**
 * Supplier bank details — two hands (design item #6, 21 Oct 2026; [[SupplierBank]]).
 */
return [
    'title' => 'Bank details',
    'bank_name' => 'Bank',
    'branch_name' => 'Branch',
    'account_name' => 'Account name',
    'account_no' => 'Account number',
    'routing_no' => 'Routing number',
    // পরিশোধের পর্দার এক-লাইনের বাক্সে ছোট নাম (মিল-তালিকা #১১)
    'account_short' => 'A/C',
    'routing_short' => 'Routing',
    'reason' => 'Reason for the change',
    'note' => 'Reason for refusing',

    'none' => 'No approved bank details.',
    'pending_badge' => 'Awaiting approval',
    'pending_line' => 'Requested by :who — needs another person\'s approval.',
    'verified' => '✓ Verified by two approvals',
    'signed_by_line' => 'Requested by :maker · approved by :signer',
    'request_title' => 'Request a bank details change',
    'request_hint' => 'The current details stay in use until another person approves.',

    'request' => 'Send request',
    'sign' => 'Approve',
    'reject' => 'Refuse',
    'withdraw' => 'Withdraw',

    'requested' => 'The bank details change awaits a second approval — the current details stay in use.',
    'signed' => 'Approved — the new bank details are now in use.',
    'rejected' => 'The change was refused — the current details stay in use.',
    'withdrawn' => 'The change was withdrawn.',

    'maker_cannot_sign' => 'You cannot approve a change you requested — the owner neither. Another person must approve.',
    'no_sign_key' => 'You do not have permission to approve this change.',
    'only_maker_withdraws' => 'Only the person who requested it can withdraw it.',
    'not_pending' => 'This change is no longer awaiting an approval.',
    'account_no_invalid' => 'The account number must be 6 to 34 characters — digits or English letters only.',

    'unsigned_blocks_payment' => 'The payee\'s bank details have no second approval yet — a bank payment cannot be made. Next: enter the bank details on the supplier\'s page → a second person approves them. Cash, MFS or cheque can be paid now.',
    'unsigned_blocks_voucher' => ':supplier\'s bank details have no second approval yet — nothing can be paid into the bank (transfer or deposit). Next: enter the bank details on the supplier\'s page; once a second person approves them, bank payments open. Page: :url — cash, MFS or cheque can be paid now.',
    'payee_none' => 'No payee bank details',
    'waiting_title' => ':supplier — bank details change awaits approval',
];
