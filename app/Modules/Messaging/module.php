<?php

declare(strict_types=1);

/**
 * Messenger — messages and 1:1 calls inside ABOS, phase 1 (owner "ok", 21 Nov 2026).
 *
 * Plan: মালিকের-ফাইল/বার্তা ও কল — প্রথম ধাপ, কাজের নকশা (২১ নভেম্বর ২০২৬).md
 * Samples: phone 3nuwRUdn v5; web M1UddRxK (v6). Contract: docs/Contract — বার্তা ও কল API (প্রথম ধাপ).md
 *
 * ⭐ Messages stay on the company's own server; calls run on the office LiveKit (Mac mini) — nothing goes to a foreign
 * service. ⛔ Normally nobody reads another person's messages; the owner/admin only through the audit door, with a
 * written reason, recorded in the audit trail (`messaging.audit`).
 *
 * ⓘ No menu row: the way in is the top-bar chat icon (web sample) and the app's own tab. The page is still a module
 * page, behind the module's own key.
 */
return [
    'code' => 'messaging',

    'name' => [
        'en' => 'Messenger',
        'bn' => 'বার্তা',
    ],

    'version' => '0.1.0',

    'nav' => ['section' => 'system', 'order' => 90],

    'depends_on' => [],

    'menu' => [],

    'permissions' => [
        /* ⭐ send and read one's own messages, make 1:1 calls — every door asks it */
        'messaging.use',
        /* ⓘ a group is a broadcast to many — its own key ([[MessengerService::createGroup()]]) */
        'messaging.group.create',
        /* ⛔ read someone else's thread, with a written reason, recorded ([[MessengerAuditController]]) */
        'messaging.audit',
    ],

    'role_templates' => [
        'Manager' => ['messaging.use', 'messaging.group.create'],
        'Accountant' => ['messaging.use'],
        'Field Sales' => ['messaging.use'],
        'Auditor' => ['messaging.use'],
    ],

    'moves_money' => [],

    'settings' => [
        [
            /*
             * ⭐ "কে কাকে বার্তা/কল দিতে পারবে — রোল দিয়ে" — `{ "<sender role>": ["<receiver role>", "*"] }`.
             * ⓘ Empty = everyone in the company (the plan's default) — [[MessengerAccess]].
             */
            'key' => 'messaging.who_may_talk',
            'label' => 'messaging::settings.who_may_talk',
            'type' => 'json',
            'default' => null,
            'group' => 'messaging',
        ],
    ],
];
