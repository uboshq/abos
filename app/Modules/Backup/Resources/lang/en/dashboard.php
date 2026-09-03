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
];
