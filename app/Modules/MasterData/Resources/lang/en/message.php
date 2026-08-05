<?php

declare(strict_types=1);

return [
    'none_yet' => 'Nothing here yet.',
    'created' => 'Added.',
    'updated' => 'Updated.',
    'deactivated' => 'Deactivated.',
    'is_default_now' => '":name" is now the default.',
    'defaults_installed' => 'Standard lists installed.',
    'bangladesh_installed' => 'Bangladesh and its eight divisions are in.',
    'empty_locations' => 'No locations yet.',
    'empty_locations_note' => 'Start with Bangladesh and its eight divisions. Everything from area downwards is your own trading structure, so you build that.',
    'empty_lists' => 'These lists are empty.',
    'empty_lists_note' => 'Without units, taxes, terms and reason codes the first invoice cannot be written. Install the standard lists, then remove what you do not need.',
    'levels_off' => 'Region and territory can be switched off in settings — small businesses do not need them.',
    'deactivate_confirm' => 'This and everything under it will be deactivated. Past records stay. Continue?',
    'series_placeholders' => 'These markers can be used in the format. A format without {SEQ} is refused — without it every document would get the same number.',

    'series_note' => 'Every document number comes from here. Changing a prefix applies to new numbers only — numbers already issued never change.',
    'default_hint' => 'New transactions pick this automatically. Only one can be the default.',
    'too_many' => ':count locations — more than render as a tree at once. Search above.',
    'count' => '{0} None|{1} 1|[2,*] :count',
    'rate_saved' => 'Rate saved.',
    'no_rate_yet' => 'No rate has been entered yet.',
    'no_base_currency' => 'Make one currency the default first — that is the base your books are kept in, and every other rate is measured against it.',
    'base_currency_rate_is_one' => ':code is the company\'s own currency, so its rate is always 1 — there is nothing to enter.',
    'rate_meaning' => 'The rate reads: 1 :code equals this many :base. The date is when the rate starts applying — until the next one is entered.',
];
