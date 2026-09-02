<?php

declare(strict_types=1);

return [
    'format_needs_sequence' => 'The number format must contain {SEQ} — without it every document would get the same number.',
    'code_required' => 'A code is required.',
    'code_taken' => 'Another record already uses code :code.',
    'unknown_level' => 'That is not a valid location level.',
    'level_disabled' => 'The :level level is switched off for this company. Turn it on in settings.',
    'level_cannot_change' => 'A location cannot change level — everything under it would sit in the wrong place. Create a new one and deactivate the old.',
    'parent_required' => 'Choose the parent :level.',
    'parent_not_found' => 'That parent location was not found.',
    'wrong_parent_level' => 'The parent should be a :expected, not a :given.',
    'parent_cannot_be_own_descendant' => 'A location cannot sit under itself.',
    'default_cannot_deactivate' => 'The default cannot be deactivated — make another one default first.',
    'unit_cycle' => 'A unit cannot be its own base, directly or in a loop.',
    'factor_must_be_positive' => 'The conversion factor must be more than zero.',
    'rate_out_of_range' => 'A tax rate must be between 0 and 100.',
    'top_level_has_no_parent' => ':level is the top level — nothing sits above it.',
    'base_currency_has_no_rate' => 'The base currency has no rate — against itself it is always 1.',
    'rate_must_be_positive' => 'The rate must be greater than zero.',
    'in_use_cannot_delete' => 'Used in :where, so it cannot be deleted.',
    'default_cannot_delete' => 'This is the default, so it cannot be deleted. Make another one the default first.',
    /* WarehouseService asked for this one and it had never been written,
       so switching off a default warehouse showed the raw key instead of
       the reason (found 3 September 2026) */
    'default_cannot_be_deactivated' => 'This is the default, so it cannot be switched off. Make another one the default first.',
];
