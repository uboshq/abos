<?php

declare(strict_types=1);

/** Governance integrity checks. Every key must also exist in bn — rule 9. */
return [
    'permissions' => 'Declared permissions are installed',
    'permissions_q' => 'Does every permission the modules declare exist in this database, and does the owner role hold it?',
    'permissions_broken' => 'A missing permission closes the screen for everyone: the link answers "Forbidden" and nothing anywhere records a fault. This is exactly what a deploy without `abos:sync-permissions` leaves behind, and it is found only when somebody is stopped mid-task.',
    'permission_missing' => 'Declared by the :module module but absent here — run `abos:sync-permissions`.',
    'permission_not_with_owner' => 'The permission exists but is not on the owner role, so in practice nobody has it.',
];
