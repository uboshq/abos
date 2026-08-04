<?php

declare(strict_types=1);

return [
    'chart_empty' => 'The chart of accounts is empty.',
    'chart_empty_note' => 'Install the standard chart to get going — it is built for Bangladeshi '
        .'distribution and retail. Deactivate whatever you do not need afterwards.',
    'chart_installed' => 'Standard chart installed — :count accounts.',
    'created' => 'Account added.',
    'updated' => 'Account updated.',
    'deactivated' => 'Account deactivated.',
    'activated' => 'Account activated.',
    'deactivate_confirm' => 'This account and everything under it will be deactivated. Past transactions stay. Continue?',
    'group_hint' => 'A group is only a heading — it takes no entries of its own and shows the total of what sits under it.',
    'parent_sets_type' => 'Pick a parent and the type comes from it.',
    'opening_note' => 'What the balance was before this system. Can only be set now — to change it later, post a journal voucher.',
    'no_entries' => 'No transactions on this account yet.',
    'system_account' => 'System account',
    'too_many_to_tree' => 'The chart has :count accounts — more than the :limit that render as a tree at once. Search by code or name above.',
    'no_tills' => 'No cash counters yet.',
    'till_total' => ':amount in hand across the company',
    'till_created' => 'Cash counter added.',
    'till_updated' => 'Counter updated.',
    'till_closed' => 'Counter closed.',
    'till_is_primary' => '":name" is now the main counter.',
    'till_code_hint' => 'For example CASH, CASH01, RIDER-A. The chart account takes this code.',
    'holder_note' => 'Whose hands the money sits in. Leave blank and the counter belongs to the company, not a person.',
    'limit_hint' => '0 means no limit. Going over is flagged, never blocked.',
    'primary_hint' => 'End-of-day deposits land here. Only one counter can be the main one.',
    'till_opening_note' => 'What is in this counter right now. Can only be set when creating it.',
    'over_limit_deposit' => 'Over the limit — deposit it.',
    'close_till_confirm' => 'This counter will be closed. Past transactions stay. Continue?',
    'count' => '{0} No accounts|{1} 1 account|[2,*] :count accounts',
];
