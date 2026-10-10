<?php

declare(strict_types=1);

return [
    'bell_polling' => 'The bell checks for new notifications by itself (polling)',
    'bell_poll_seconds' => 'Check every how many seconds (at least 15)',
    'max_attempts' => 'Most attempts per notification per channel (1–10)',
    'quiet_start' => 'Company default quiet hours start (e.g. 22:00; empty = none)',
    'quiet_end' => 'Company default quiet hours end (e.g. 07:00)',
    'archive_after_days' => 'Archive read notifications older than this many days (0 = never)',
    'retention_days' => 'Remove delivery records and the archive older than this many days (0 = nothing; at least 90)',
    'daily_limit' => 'Most notifications one person gets by e-mail or push per day (0 = no limit; critical is never held back)',
    'templates_four_eyes' => 'Whoever wrote a template version cannot publish it — a second person does',
];
