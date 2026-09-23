<?php

declare(strict_types=1);

return [
    'notice' => 'Company notice (shown in the bottom bar)',
    'default_paper' => 'Default paper for printing',
    'print_format' => 'Paper format',
    'print_parts' => 'What appears on the paper',
    'print_columns' => 'Table columns and their order',
    'print_title' => 'Print control',
    'print_note' => 'Each paper has its own switches - a bill and a counter receipt are not the same thing.',
    'print_target' => [
        'invoice' => 'Sales invoice',
        'pos' => 'Counter receipt (thermal)',
        'challan' => 'Delivery challan',
        'order' => 'Sales order',
        'receipt' => 'Collection receipt',
        'voucher' => 'Voucher (receipt, payment, expense, journal)',
    ],
    'print_pick_format' => 'Pick a ready-made format',
    'print_pick_format_note' => 'Picking one fills the switches below; each can then be changed on its own.',
    'print_parts_note' => 'Which parts of the paper get printed.',
    'print_columns_note' => 'Which columns, and in what order. The number is the position - smaller comes first.',
    'print_column_off' => 'Off',
    'print_reset' => 'Back to the chosen format',
    'print_papers' => 'Papers',
    'print_sample' => 'Sample',
    'print_sample_note' => 'Every format is drawn below on a made-up paper. Look first, then pick one and save.',
    'print_sample_of' => 'Sample of :format',
    'print_sample_open' => 'Open this sample full size',
    'print_saved' => 'Print switches saved',
    'auto_logout_minutes' => 'Minutes of inactivity before automatic logout',
    'date_format' => 'How dates are written',
    'time_format' => 'How the clock is written',

    'title' => 'Company settings',
    'note' => 'Every rule the modules declare — in one place, per company.',
    'screens_live_in_control_panel' => 'Which screens are visible — that lives in the Control Panel',
    'saved' => '{0}Nothing changed|{1}One setting saved|[2,*]:count settings saved',

    'notice_bar_max' => 'Most notices on the bottom bar',
    'notice_remind_after' => 'First reminder (hours)',
    'notice_remind_again' => 'Second reminder (hours)',
    'notice_escalate_after' => 'Escalate after (hours)',
    'notice_creator_cannot_approve' => 'The writer of a critical notice cannot approve it',
];
