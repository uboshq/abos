<?php

declare(strict_types=1);

/*
 * The four backup screens — policy, verification, restore, disaster (audit step 5.1).
 */
return [
    // ── Policy ───────────────────────────────────────────────────────
    'policy_title' => 'Backup policy',
    'policy_subtitle' => 'What actually runs — read from the server settings',
    'policy_why_read_only' => 'These values live in the server settings (ABOS_BACKUP_AT, ABOS_BACKUP_KEEP_DAYS, ABOS_BACKUP_MIRROR). There are no edit boxes here, because the nightly backup reads them from there — a box on this screen would say "saved" while nothing changed. To change them, ask whoever looks after the server.',
    'every_night_at' => 'Every night at',
    'kept_for' => 'Kept for',
    'folder' => 'Stored in',
    'checked_after' => 'After every backup',
    'checked_after_value' => 'it is restored and checked',
    'second_copy' => 'Second copy',
    'no_second_copy' => 'Not set — the backups sit on the same server as the books. If the server goes, both go.',
    'destinations' => 'Destinations',
    'no_destinations' => 'No destination set up.',
    'active' => 'on',
    'inactive' => 'off',

    // ── Verification ─────────────────────────────────────────────────
    'verify_title' => 'Verification',
    'verify_subtitle' => 'Every night — and whether it was restored and checked',
    'records_since' => 'Records start on :date — nights before that were only in the log, not in the books.',
    'no_records_yet' => 'No night has been recorded yet. Recording started on 19 September 2026 — the next nightly backup will appear here.',
    'col_when' => 'When',
    'col_how' => 'How',
    'col_result' => 'Result',
    'col_check' => 'Check',
    'col_file' => 'File',
    'trigger_schedule' => 'nightly',
    'trigger_manual' => 'by hand',
    'status_success' => 'Done — also reached other destinations',
    'status_partial' => 'Partly — some destinations missed',
    'status_local_only' => 'Taken, but on the same server — if the server goes, this goes too',
    'status_failed' => 'Failed',
    'status_running' => 'Running',
    'check_passed' => 'Fine — :tables tables came back',
    'check_failed' => 'Failed',
    'check_none' => 'Not checked',

    // ── Restore ──────────────────────────────────────────────────────
    'restore_title' => 'Restore',
    'restore_subtitle' => 'From which file, and whether it was checked',
    'restore_why_no_button' => 'Restoring wipes out every piece of work after that moment. So there is no button here — the command is run on the server. Before restoring, the command itself takes a dump of the current state, so a mistake can be undone.',
    'prefer_checked' => 'Pick one marked "checked" first — it has been restored and looked at once already.',
    'checked' => 'checked',
    'unchecked' => 'not checked',
    'check_broke' => 'check failed',
    'no_files' => 'There are no backup files.',
    'command' => 'Command',

    // ── Disaster ─────────────────────────────────────────────────────
    'dr_title' => 'Disaster recovery',
    'dr_subtitle' => 'What is left in hand if the server itself is gone',
    'newest_backup' => 'Newest backup',
    'last_good_check' => 'Last good check',
    'never' => 'never',
    'not_recorded_yet' => 'not in the books yet — checks are recorded from 19 September 2026',
    'no_mirror_warning' => 'No second destination is set (ABOS_BACKUP_MIRROR) — if the server goes, the backups go with it. Today this is the biggest risk.',
    'mirrored_at' => 'Last reached the second copy',
    'steps_title' => 'Steps back',
    'step_1' => 'Install the app on a new server — following the "cPanel shared hosting" guide, with an empty database.',
    'step_2' => 'Put the newest checked dump from the second copy or a destination into the new server\'s backup folder.',
    'step_3' => 'Run the command: php artisan abos:restore <file name>',
    'step_4' => 'Run php artisan migrate --force — any table added after the dump will be created.',
    'step_5' => 'Log in as the owner and check today\'s ledger and cash balance — transactions after the dump time must be entered again.',
    'days_old' => ':days days ago',
];
