<?php

declare(strict_types=1);

return [
    'title' => 'Quiet hours & digest',
    'note' => 'During quiet hours, normal notifications do not go to outside channels (e-mail, push) — they go when the quiet hours end; the bell has them at once. Critical notifications go even during quiet hours. Anyone can choose to get normal e-mails once a day or once a week, together.',
    'defaults' => 'Company default quiet hours',
    'defaults_note' => 'For anyone who has not chosen their own. Leave both empty for no default quiet hours. Overnight windows work too (for example 22:00 to 07:00).',
    'start' => 'Start',
    'end' => 'End',
    'save' => 'Save',
    'saved' => 'Default quiet hours saved.',
    'deferred' => 'Deferred by quiet hours in the last 7 days',
    'held' => 'Held for a digest in the last 7 days',
    'people' => 'People who chose their own quiet hours or a digest',
    'empty' => 'Nobody has chosen yet.',
    'window' => 'Quiet hours',
    'frequency' => 'How often',
    'timezone' => 'Time zone',
];
