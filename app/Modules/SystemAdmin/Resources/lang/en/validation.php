<?php

declare(strict_types=1);

return [
    'screen_holds_records' => 'These screens already hold documents, so they cannot be hidden: :screens. '
        .'Finish or cancel those documents first — otherwise they would have no way in.',
    'cannot_deactivate_yourself' => 'You cannot deactivate yourself — nobody would be left who could undo it.',
    'cannot_drop_your_own_key' => 'You cannot take user management away from yourself — the only way back would be the command line.',
    'owner_role_is_fixed' => 'The owner role cannot be edited; every deploy puts those permissions back.',

    // Every refusal names the way out as well. "You cannot" alone leaves
    // the next question unanswered — "then how do I hand it over?" — and
    // an unanswered question is what sends people to the database.
    'owner_already_exists' => 'This company already has an owner — :name. There is only ever one, '
        .'because that role holds every permission: changing roles, deactivating users, opening a month, all of it. '
        .'To hand the responsibility over, use the “Transfer ownership” page — the key changes hands there, it never becomes two.',
    'last_owner_must_remain' => 'The last owner of this company cannot be removed — nobody would be able to get in, '
        .'and the only way back would be the command line on the server. To put someone else in charge, use the “Transfer ownership” page.',
    'owner_transfer_to_self' => 'Ownership cannot be transferred to yourself — choose the person who should hold the key.',
    'owner_transfer_not_owner' => 'Only the current owner can transfer ownership.',
    'owner_transfer_to_inactive' => 'The account for :name is deactivated, so ownership could not be handed over — '
        .'this company would have been left with no active owner. Activate the account first.',
    'owner_transfer_left_nobody' => 'The transfer was rolled back — it would have left this company with no active owner. The previous owner still holds it.',
    'owner_needs_company' => 'It is not clear which company this would own, so the role was not applied — '
        .'choose a company first. (Whether there is already an owner is counted per company.)',
    'role_name_shape' => 'A role name takes lowercase letters, digits and underscores (store_keeper).',
    // Names the name back: the rule is not about the field being
    // required, it is about this name yielding no ASCII code.
    'code_needs_latin' => 'Please type the code — no code could be made from “:name”, because codes are always written in Latin letters and go into every document number.',
];
