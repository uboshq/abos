<?php

declare(strict_types=1);

return [
    'title' => 'Notification audit',
    'note' => 'Who did what to which notification, when, and what came of it. Nobody can change this log.',
    'empty' => 'Nothing recorded yet.',
    'action' => 'Action',
    'outcome' => 'Outcome',
    'actor' => 'Who',
    'target' => 'Target',
    'detail' => 'Detail',
    'when' => 'When',
    'from' => 'From',
    'to' => 'To',
    'system' => 'The system',
    'actions' => [
        'delivery_retry' => 'Retried by hand',
        'delivery_cancel' => 'Delivery cancelled',
        'channel_update' => 'Channel settings changed',
        'channel_vapid' => 'Web Push keys generated',
        'channel_test' => 'Channel connection test',
        'push_subscribe' => 'Browser push turned on',
        'push_unsubscribe' => 'Browser push turned off',
        'read_all' => 'Read all',
        'bulk_read' => 'Selected marked read',
        'bulk_unread' => 'Selected marked unread',
        'bulk_archive' => 'Selected archived',
        'bulk_restore' => 'Selected restored',
        'archive' => 'Archived',
        'restore' => 'Restored',
        'open_denied' => 'Opened without access',
        'center_archive' => 'Archived from the center',
        'center_restore' => 'Restored to the center',
    ],
    'outcomes' => [
        'done' => 'Done',
        'denied' => 'Denied',
        'failed' => 'Failed',
    ],
];
