<?php

declare(strict_types=1);

return [
    'title' => 'What is kept, and for how long',
    'note' => 'What is kept, for how long, and what enforces it. Tax and VAT papers must be kept :years years by law.',
    'backup_gap' => 'The books are kept forever, but backups only :days days. Lose the database and you can go back :days days, not :years years — so keep older backups of your own as well.',
    'what' => 'What',
    'how_long' => 'How long',
    'enforced_by' => 'Enforced by',
    'rows_now' => 'Rows now',
    'forever' => 'Forever',
    'days' => '{1} 1 day|[2,*] :count days',
    'by_nobody' => 'Nothing deletes these — no job touches them',
    'by_schedule' => 'The nightly schedule',
    'footer' => 'This page sets no policy. It shows what the code actually does, because a written policy that differs from the behaviour is the most dangerous paper in the building.',
    'kind' => [
        'ledger' => 'The ledger',
        'vouchers' => 'Vouchers and documents',
        'audit' => 'Audit trail — who changed what',
        'exports' => 'Export log — who downloaded what',
        'logins' => 'Login history',
        'errors' => 'Error log',
        'notifications' => 'Notifications',
        'backups' => 'Backup files',
        'reports' => 'Generated report files',
        'form_marks' => 'Form submission marks — to stop double posting',
    ],
];
