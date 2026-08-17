<?php

declare(strict_types=1);

return [
    'screen_holds_records' => 'These screens already hold documents, so they cannot be hidden: :screens. '
        .'Finish or cancel those documents first — otherwise they would have no way in.',
    'cannot_deactivate_yourself' => 'You cannot deactivate yourself — nobody would be left who could undo it.',
    'cannot_drop_your_own_key' => 'You cannot take user management away from yourself — the only way back would be the command line.',
    'owner_role_is_fixed' => 'The owner role cannot be edited; every deploy puts those permissions back.',
    'role_name_shape' => 'A role name takes lowercase letters, digits and underscores (store_keeper).',
];
