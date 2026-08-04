<?php

declare(strict_types=1);

return [
    'code_taken' => 'Another account already uses code :code.',
    'unknown_type' => 'That is not a valid account type.',
    'parent_not_found' => 'That parent account was not found.',
    'parent_must_be_group' => 'An account that takes entries cannot hold other accounts. '
        .'Make the parent a group first.',
    'parent_cannot_be_own_descendant' => 'An account cannot sit under itself.',
    'has_entries_cannot_group' => 'This account has transactions, so it cannot become a group — '
        .'groups take no entries of their own, and the existing ones would stop being counted.',
    'has_children_must_stay_group' => 'This account holds other accounts, so it must stay a group.',
    'has_entries_cannot_retype' => 'This account has transactions, so its type cannot change — '
        .'the existing entries would move to a different report.',
    'system_account_locked' => '":name" is a system account. Sales, purchases and other modules '
        .'look it up by code, so it cannot be changed or removed.',
    'group_cannot_take_entries' => 'A group account takes no entries. Pick one of the accounts under it.',
    'cash_or_bank_not_both' => 'An account cannot be both cash and bank.',
    'group_is_not_money' => 'A group holds no money, so it cannot be marked as cash or bank.',
    'till_code_taken' => 'Another cash counter already uses code :code.',
    'till_has_money' => 'This counter still holds :amount. Deposit or transfer it first, then close.',
    'primary_till_cannot_close' => 'The main cash counter cannot be closed — end-of-day deposits need somewhere '
        .'defined to go. Make another one primary first.',
    'cash_group_missing' => 'The chart has no ":code Cash in Hand" account. Install the standard chart first.',
];
