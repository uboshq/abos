<?php

declare(strict_types=1);

// ⭐ Automatic sends — due reminders, payment confirmations, parties left out (owner "ok", 23 Nov 2026; sample HUXqpfhxng8823eEFrvH54 tab ঙ)
return [
    'title' => 'Automatic messages',
    'tabs' => [
        'rules' => 'Rules',
        'today' => 'Going out today',
        'optout' => 'Parties left out',
    ],
    'save' => 'Save',
    'saved' => 'Saved.',
    'bad_time' => 'Write the time in 24-hour form, like 10:00.',
    'template_gone' => 'That text is no longer active, or is not for this rule.',

    'due' => [
        'title' => 'Due reminder',
        'switch' => 'Due reminder on',
        'after' => 'Days after a bill falls due',
        'every' => 'Repeat every',
        'min' => 'Due at least (Tk)',
        'time' => 'At',
        'days' => 'Which days',
        'days_options' => [
            'sat_thu' => 'Saturday–Thursday (not Friday)',
            'every' => 'Every day',
        ],
        'template' => 'Text',
        'line' => 'Going at :time today: :n parties',
        'list' => 'See the list',
    ],

    'paid' => [
        'title' => 'Payment received',
        'switch' => 'Payment received on',
        'when' => 'When',
        'when_options' => [
            'posted' => 'As soon as the receipt (RCV) is posted',
            'approved' => 'After approval',
        ],
        'min' => 'Amount at least (Tk)',
        'template' => 'Text',
        'link' => 'With the receipt link',
        'yes' => 'Yes',
        'no' => 'No',
        'line' => 'Sent today: :sent · failed :failed',
        'log' => 'See in the log',
    ],

    'days' => ':n days',
    'preview' => 'What goes out — Bangla',
    'no_example' => 'No party to show yet.',
    'no_template' => 'This rule has no active text — make one in "Message texts".',

    'shared' => [
        'title' => 'Rules for every automatic message',
        'quiet' => 'Nothing goes between 9:00 at night and 9:00 in the morning — it goes in the morning',
        'quiet_label' => 'No sending at night',
        'once_a_day' => 'A party gets at most one reminder a day',
        'once_a_day_label' => 'Once a day',
        'optout' => 'A party marked "do not message" gets nothing',
        'optout_label' => 'Parties left out',
        'per_branch' => 'Each branch has its own rules (off: one set for the company)',
        'per_branch_label' => 'Separate per branch',
    ],

    'today' => [
        'party' => 'Party',
        'phone' => 'Number',
        'due' => 'Due',
        'oldest' => 'Oldest bill',
        'at' => 'At',
        'road' => 'Road',
        'empty' => 'No reminder goes out today.',
    ],

    'roads' => [
        'cloud' => 'WhatsApp — automatic',
        'phone' => 'SMS — office phone',
        'none' => 'No road open',
    ],

    'optout' => [
        'party' => 'Party',
        'reason' => 'Reason',
        'by' => 'Marked by',
        'when' => 'When',
        'action' => 'Action',
        'add' => 'Add',
        'pick' => 'Party',
        'reason_hint' => 'Why (optional)',
        'remove' => 'Take off the list',
        'remove_confirm' => 'This party will get automatic messages again. Take them off?',
        'empty' => 'No party is left out.',
    ],
    'optout_added' => 'Party left out — nothing automatic goes to them any more.',
    'optout_removed' => 'Taken off the list.',
    'party_gone' => 'That party was not found.',
    'settings' => [
        'due_enabled' => 'Due reminder — on',
        'due_after_days' => 'Due reminder — days after the due date',
        'due_every_days' => 'Due reminder — repeat every (days)',
        'due_min_due' => 'Due reminder — minimum due (Tk)',
        'due_time' => 'Due reminder — time',
        'due_days' => 'Due reminder — which days',
        'due_template_id' => 'Due reminder — text',
        'paid_enabled' => 'Payment received — on',
        'paid_when' => 'Payment received — when',
        'paid_min_amount' => 'Payment received — minimum amount (Tk)',
        'paid_template_id' => 'Payment received — text',
        'paid_with_link' => 'Payment received — include the receipt link',
        'quiet' => 'Nothing goes at night (9:00 pm to 9:00 am)',
        'once_a_day' => 'A party gets at most one reminder a day',
        'optout' => 'Nothing goes to parties on the do-not-message list',
        'per_branch' => 'Each branch has its own rules',
        'branch_rules' => 'Branch rules',
    ],
];
