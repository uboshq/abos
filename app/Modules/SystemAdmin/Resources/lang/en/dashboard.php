<?php

declare(strict_types=1);

return [
    'title' => 'Dashboard',
    'subtitle' => 'How the system itself is doing',
    'last_backup' => 'Last backup',
    'last_backup_hint' => 'Read from the files, not a log — attempted and existing are not the same',
    'never' => 'Never',
    'days_ago' => ':days days ago',
    'users_hint' => 'People who can sign in',
    'roles_hint' => 'Who is allowed to do what',
    'companies_hint' => 'Companies on this install',
    'newest_users' => 'Recently added users',
    'name' => 'Name',
    'email' => 'Email',
    'joined' => 'Joined',
    'no_users' => 'No users yet.',
    'who_gets_in' => 'Users',
    'who_gets_in_hint' => 'Second step on for :count',
    'users_live' => 'Signed in within 30 days',
    'users_idle' => 'Not in for 30 days',
    'users_never' => 'Never signed in',
    'users_off' => 'Switched off',

    // Background jobs, second step, access changes, system errors (6 Oct 2026)
    'jobs_queued' => 'Background jobs — waiting',
    'jobs_queued_hint' => 'The whole server queue, shown only to the super admin — a long wait means the worker has stopped',
    'jobs_failed' => 'Background jobs — failed',
    'jobs_failed_hint' => 'The whole server, shown only to the super admin — anything above zero needs a look',
    'two_step' => 'Second step — who has it on',
    'two_step_hint' => 'Every user of this company; on means a code is set up and confirmed',
    'two_step_on' => 'On',
    'two_step_off' => 'Not on',
    'access_changes' => 'User and permission changes — this week',
    'access_changes_hint' => ':count changes this week — roles, companies, scopes, passwords, second step',
    'errors_week' => 'System errors — this week',
    'errors_week_hint' => 'The same error counted once however often it happened; the full log is on the audit error screen',
    'errors_unseen' => 'Nobody looked yet',
    'errors_seen' => 'Looked at',
];
