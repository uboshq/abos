<?php

declare(strict_types=1);

// Expected credit loss (IFRS 9) and bad-debt write-off (accounts gap #18)
return [
    'title' => 'Expected credit loss and write-off',
    'subtitle' => 'IFRS 9: rates by age; the provision journal goes for approval. Historical rate = the share of the dues of a year ago written off in the following year (a suggestion only).',
    'as_of' => 'As at',
    'bucket' => 'Age bucket',
    'due' => 'Due',
    'rate' => 'Rate',
    'loss' => 'Expected loss',
    'history' => 'Historical rate (suggestion)',
    'bucket_current' => '0-30 days',
    'bucket_30' => '31-60 days',
    'bucket_60' => '61-90 days',
    'bucket_90' => 'Over 90 days',
    'target' => 'Allowance target',
    'held' => 'Allowance held now',
    'adjustment' => 'Difference (raise + / release -)',
    'draft_provision' => 'Send the provision journal for approval',
    'write_off_title' => 'Write off a bad debt',
    'customer' => 'Customer',
    'amount' => 'Amount to write off',
    'reason' => 'Reason',
    'draft_write_off' => 'Draft the write-off voucher',
    'drafted' => 'A draft voucher was written. Someone else checks and posts it.',
    'sent_for_approval' => ':no has gone for approval. It reaches the books once approved.',
    'nothing_to_adjust' => 'The allowance equals the target. No provision is needed.',
    'amount_positive' => 'The amount must be above zero.',
    'reason_required' => 'Write why it is written off. The audit needs it.',
    'more_than_due' => 'The customer owes only :due. More than that cannot be written off.',
    'account_missing' => 'Account :code is not in the chart. Install the chart first.',
    'whole_company_only' => 'This is the whole company. Someone limited to a branch cannot run it.',
    'provision_narration' => 'IFRS 9 loss allowance on :date: target :target, held :held',
    'write_off_narration' => 'Bad debt written off: :name: :reason',
];
