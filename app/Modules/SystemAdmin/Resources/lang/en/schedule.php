<?php

declare(strict_types=1);

return [
    // Validation — the permission rules live here
    'unknown_report' => 'That is not a report this system knows.',
    'bad_frequency' => 'Choose daily, weekly or monthly.',
    'bad_format' => 'That is not a file format this system can produce.',
    // A report with no declared permission cannot be sent to anyone but its own creator
    'report_not_shareable' => 'This report has no declared audience, so it can only be scheduled for yourself — not sent to others.',
    'you_cannot_see_this' => 'You do not have permission to see this report, so you cannot schedule it.',
    'recipient_cannot_see' => ':name is not allowed to see this report — remove them, or they would receive figures they cannot open on screen.',

    'notify' => [
        'title' => 'A scheduled report is ready',
        'body' => ':report has been generated — open it to download.',
    ],

    // Screen
    'title' => 'Scheduled Reports',
    'subtitle' => 'Reports that build themselves and reach the right people',
    'add' => 'New schedule',
    'none' => 'No reports are scheduled yet.',
    'edit' => 'Edit schedule',
    'saved' => 'The schedule was saved.',

    'report' => 'Report',
    'format' => 'File format',
    'frequency' => 'How often',
    'at_time' => 'At',
    'timezone' => 'Time zone',
    'day_of_week' => 'Day of the week',
    'day_of_month' => 'Day of the month',
    'on_month_end' => 'Last day of the month',
    'recipients' => 'Who receives it',
    'recipients_hint' => 'Only people who are allowed to see this report. Leave empty to keep it for yourself.',
    'status_col' => 'Status',
    'active' => 'Active',
    'inactive' => 'Paused',
    'next_run' => 'Next',
    'last_run' => 'Last run',
    'never' => 'Never',
    'activate' => 'Resume',
    'deactivate' => 'Pause',

    'freq' => [
        'daily' => 'Every day',
        'weekly' => 'Every week',
        'monthly' => 'Every month',
    ],

    'runs_heading' => 'Recent files',
    'download' => 'Download',
    'run_status' => [
        'ok' => 'Ready',
        'empty' => 'No rows',
        'error' => 'Failed',
        'owner_gone' => 'Creator gone',
        'owner_no_permission' => 'Creator lost access',
        'unknown_report' => 'Report removed',
    ],

    'weekday' => [
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
    ],
];
