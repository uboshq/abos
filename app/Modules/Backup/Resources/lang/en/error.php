<?php

declare(strict_types=1);

return [
    'no_path' => 'The destination has no path set.',
    'cannot_create' => 'The folder could not be made: :path',
    'copy_failed' => 'The copy failed: :path',
    'rename_failed' => 'The copy could not be finished: :path',
    'read_failed' => 'The file could not be read: :file',
    'unknown_driver' => 'That kind of destination is not built yet: :driver',
    'no_proc_open' => 'This server cannot run external programs from PHP (proc_open is disabled), so mysqldump is unavailable. ABOS will take the dump in PHP instead. Disabled functions: :disabled',
    'dumper_too_simple' => 'The database holds things the PHP dumper cannot capture, so no backup was taken — an incomplete backup is worse than none, because it makes people feel safe. Found: :found. Fix: enable proc_open, or take the dump from the shell (infra/backup-abos.sh).',
    'dump_was_empty' => 'The dump wrote no tables at all — the file is effectively empty, so it was not kept as a backup.',
    'stale' => 'The last successful backup is :hours hours old; the limit is :limit. Look now — if the disk goes today there is nothing to restore from.',
    'never' => 'No successful backup was ever found. There is nothing to restore from right now.',
];
