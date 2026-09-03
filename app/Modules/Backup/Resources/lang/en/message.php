<?php

declare(strict_types=1);

return [
    'taken' => 'Backup taken: :name (:size) — copied to :copies destination(s).',
    'taken_nowhere' => 'Backup taken: :name (:size) — ⚠️ but it reached no destination, so the file is only on this server.',

    'destination_added' => 'The destination was added. Test it once now.',
    'destination_ok' => ':name — reached, and written to.',
    'destination_removed' => 'The destination was removed. Past runs keep the name.',

    'no_destination' => 'No destination is set — backups stay on this server only.',
    'no_destination_why' => 'A backup on the same disk goes with the disk. Give it at least one second place — a pendrive, an external drive, or another computer.',

    'nothing_yet' => 'No backup has been taken yet.',
    'download_warning' => 'This one file holds everything the company has — prices, salaries, dues. Keep it somewhere safe.',
];
