<?php

declare(strict_types=1);

/**
 * স্থায়ী সম্পদ ও অবচয়ের ভাষা।
 */
return [
    'title' => 'Fixed assets',
    'subtitle' => 'A van wears out whether the books notice it or not.',

    'register_title' => 'Add an asset',
    'name' => 'What it is',
    'tag_no' => 'Tag number',
    'account' => 'Kind',
    'cost' => 'What it cost',
    'salvage' => 'Worth at the end',
    'acquired_on' => 'Bought on',
    'method' => 'How it wears out',
    'straight' => 'Same every month',
    'reducing' => 'On what is left',
    'life_months' => 'Life in months',
    'rate' => 'Rate a year (%)',
    'narration' => 'Note',
    'register_action' => 'Add',

    'empty' => 'No assets on the books yet.',
    'empty_entries' => 'No depreciation has been posted for this asset.',

    'status' => 'Status',
    'active' => 'In use',
    'disposed' => 'Gone',

    'book_value' => 'On the books now',
    'accumulated' => 'Worn out so far',
    'left_to_write' => 'Still to write off',
    'next_month' => 'Next month would be',

    'run_title' => 'Month-end run',
    'run_month' => 'Month',
    'run_action' => 'Post depreciation',
    'run_done' => 'Posted for :posted asset(s); :skipped skipped.',

    'period' => 'Month',
    'amount' => 'Amount',

    'dispose_title' => 'Sell or scrap',
    'disposal_amount' => 'What we got for it',
    'disposed_on' => 'Gone on',
    'into_account' => 'Money went into',
    'dispose_action' => 'Record disposal',

    'registered' => 'Asset added.',
    'disposed_message' => 'Disposal recorded.',

    'life_required' => 'A life in months is needed when it wears out evenly.',
    'rate_required' => 'A rate is needed when it wears out on what is left.',
    'salvage_over_cost' => 'What it is worth at the end cannot be more than what it cost.',
    'not_active' => 'This asset is no longer in use.',
    'before_acquisition' => 'Nothing wears out before it is bought.',
    'funded_by' => 'Where did the money come from?',
    'funded_by_note' => 'The ledger entry follows this answer: the asset account is debited, and whatever it came from is credited.',
    'funded_capital' => 'An owner or investor provided it',
    'funded_capital_note' => 'Goods count as well as money — it appears under their name on the capital page.',
    'funded_money' => 'Paid from bank or cash',
    'funded_money_note' => 'The account the money left goes down.',
    'funded_credit' => 'Bought on credit',
    'funded_credit_note' => 'Sits in what is owed to that supplier; paying later brings it down.',
    'funded_opening' => 'Carried from the old books',
    'funded_opening_note' => 'The business already owned it; today it is only being written into ABOS.',
    'funded_already' => 'Already recorded in the books',
    'funded_already_note' => 'For a purchase already entered as a voucher or bill — nothing is posted here, or it would be counted twice.',
    'funding_person' => 'Who provided it',
    'funding_account' => 'From which account',
    'funding_supplier' => 'Which supplier',
    'funding_account_missing' => 'Account :code is not in the chart — install the chart and try again.',
    'opening_accumulated' => 'Depreciation charged so far',
    'opening_accumulated_hint' => 'Fill this in for a used asset — zero for a new one. It does not go to expense; it sits in retained earnings.',
    'opening_needs_the_chart' => 'Install the standard chart first — there is no retained earnings account.',
    'transfer' => 'Move to another branch',
    'transfer_hint' => 'If the thing has moved to another branch, record it here — the books of both branches follow.',
    'to_branch' => 'To which branch',
    'moved_on' => 'Moved on',
    'moved' => 'Moved — the books of both branches now agree',
    'already_there' => 'It is already at that branch',
    'move_history' => 'Where it has been',
];
