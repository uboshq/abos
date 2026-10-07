<?php

declare(strict_types=1);

return [
    'title' => 'Backup dashboard',
    'subtitle' => 'Where the copies are, and whether they really come back',

    'last_backup' => 'Last backup',
    'last_backup_hint' => 'Anything done since then is in no copy yet.',

    'destinations' => 'Destinations',
    'destinations_hint' => 'How many places off this server get a copy. Zero means the backup goes with the machine.',

    'last_verified' => 'Comes back?',
    'last_verified_hint' => 'Whether the last backup was actually restored into a database and counted.',
    'verified_yes' => 'Yes, tested',
    'verified_no' => 'Not tested',
    'months_of_copies' => 'Backups month by month — last six months',
    'copies_safe' => 'Reached every destination',
    'copies_trouble' => 'Trouble (partial, this machine only, or failed)',

    // Size, the last 30 days of runs, destinations and the schedule (6 Oct 2026)
    'latest_size' => 'Size of the last good backup',
    'latest_size_hint' => 'The run of :date — a sudden drop means something was left out',
    'latest_size_none' => 'None yet',
    'latest_size_none_hint' => 'No run has reached every destination yet',
    'last_30_days' => 'Runs in the last 30 days',
    'last_30_days_hint' => ':count runs — running ones left out',
    'run_verified' => 'Verified',
    'run_success' => 'Succeeded, not verified',
    'run_partial' => 'Partial',
    'run_local_only' => 'This machine only',
    'run_failed' => 'Failed',
    'destination_list' => 'How the destinations are doing',
    'col_name' => 'Name',
    'col_kind' => 'Kind',
    'col_state' => 'State',
    'col_last_copy' => 'Last copy',
    'col_when' => 'When it runs',
    'state_off' => 'Off',
    'state_never' => 'Never reached',
    'state_failing' => 'Last try failed',
    'state_ok' => 'Fine',
    'schedule' => 'Backup schedule',
    'schedule_empty' => 'No policy is kept on screen — the nightly backup runs from the server settings, every day at :time.',
    'every_hourly' => 'Every hour',
    'every_daily' => 'Every day',
    'every_weekly' => 'Every week',
    'every_monthly' => 'Every month',
];
