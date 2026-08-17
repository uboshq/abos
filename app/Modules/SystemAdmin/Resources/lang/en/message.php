<?php

declare(strict_types=1);

return [
    'control_panel_note' => 'Every module switch in one place — for the day a company is set up.',
    'no_switches' => 'No module declares any switches yet.',
    'switches_saved' => '{0} Nothing changed.|{1} 1 switch changed.|[2,*] :count switches changed.',

    // Companies and branches
    'company_created' => ':name is ready — branch, financial year, number series, chart of accounts and master lists are all in place.',
    'company_updated' => 'The company was updated.',
    'company_enabled' => 'The company is active again.',
    'company_disabled' => 'Switched off — it leaves the switcher, and every paper it already holds stays.',
    'cannot_disable_current' => 'You cannot switch off the company you are working in. Move to another one first.',
    'branch_created' => 'The branch was added.',
    'branch_code_taken' => 'A branch with that code already exists.',
    'no_companies' => 'No companies yet.',
    'company_note' => 'A new company arrives with its branch, financial year, number series, standard chart of accounts and master lists already set up — nothing to do afterwards.',
    'main_branch_note' => 'At least one branch is needed — without it there is nowhere for a transaction to sit. More can be added later.',
    'financial_year_note' => 'In Bangladesh the year runs July to June. The dates are filled in already, because a calendar year would put every report out of step with the tax office.',
    'users_note' => 'Who can sign in, to which company, and what they may do. Users are never deleted — deactivating keeps their name on old paperwork.',
    'roles_note' => 'A named set of permissions. Every depot splits the work differently, so roles are rows here rather than names in code.',
    'roles_are_global' => 'A role belongs to the person, not to a company — the same rights apply in every company they can enter.',
    'company_access_note' => 'At least one company is required, or signing in would show an empty screen. Choosing a branch decides where they land.',
    'owner_role_fixed' => 'The owner role cannot be edited — by definition it can do everything',
    'user_created' => ':name was added.',
    'user_updated' => ':name was updated.',
    'role_created' => 'The role :name was created.',
    'role_updated' => 'The role :name was updated.',
];
