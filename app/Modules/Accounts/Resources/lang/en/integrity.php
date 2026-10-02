<?php

declare(strict_types=1);

return [
    'trial_balance' => 'The trial balance balances',
    'trial_balance_q' => 'Whether total debits equal total credits across the whole ledger.',
    'trial_balance_broken' => 'If it does not, the trial balance, the balance sheet and the P&L are all wrong, and none of them says so.',

    'each_document' => 'Every document balances on its own',
    'each_document_q' => 'Whether each posting has equal debits and credits.',
    'each_document_broken' => 'Two broken postings can cover for each other — the grand total balances perfectly while both documents are wrong.',

    'orphan_entries' => 'Every entry has an account',
    'orphan_entries_q' => 'Whether any ledger row points at an account that no longer exists.',
    'orphan_entries_broken' => 'Money on an account-less row is counted in no balance, yet sits in the trial balance total — it is invisible.',

    'the_whole_ledger' => 'The whole ledger',
    'dr_cr_detail' => 'Debit :debit · credit :credit · difference :diff',
    'orphan_detail' => 'Account #:account is missing',
    'paper_gone' => 'Every entry still has its paper',
    'paper_gone_q' => 'Are there ledger entries whose paper was deleted while the entries were never reversed?',
    'paper_gone_broken' => 'The money is still in the books but its paper can no longer be opened — restore the paper or reverse the entries.',
    'paper_gone_detail' => ':type #:id — the paper is gone',
    'and_more_what' => 'More',
    'and_more' => 'The first hundred are shown; :more more (:total in all).',
];
