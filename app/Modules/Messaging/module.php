<?php

declare(strict_types=1);

use App\Modules\Messaging\Models\Call;
use App\Modules\Messaging\Models\HiddenMessage;
use App\Modules\Messaging\Models\Member;
use App\Modules\Messaging\Models\MemberMark;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageFile;

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
 * ⓘ Ways in: the top-bar chat icon (web sample), the app's own tab, and — since 23 Nov 2026 — ONE menu row "বার্তা ও কল",
 * first in "যোগাযোগ" (owner: যোগাযোগ = নোটিফিকেশন + বার্তা ও কল). The row opens the same page with the same key
 * (`messaging.use`) the chat icon asks.
 */
return [
    'code' => 'messaging',

    'name' => [
        'en' => 'Messenger',
        'bn' => 'বার্তা',
    ],

    'version' => '0.1.0',

    /*
     * ⭐ এক করা মডিউল (মালিক, ২৩ নভেম্বর ২০২৬: "যোগাযোগ = নোটিফিকেশন + বার্তা ও কল") — বিজ্ঞপ্তির ভিতরে বসে, প্রথম সারি হয়ে।
     * ⛔ `merged_only` — SAP আর Dynamic-এ মেনুতে আসেই না, ওই দুই রূপ আগের মতো ১০০% (চ্যাট চিহ্নও ওখানে আঁকা হয় না, [[MessengerChrome]])।
     */
    'nav' => ['section' => 'system', 'order' => 90, 'under' => 'notification', 'merged_only' => true],

    'depends_on' => [],

    'menu' => [
        'transactions' => [
            // ⓘ উপরের বারের চ্যাট চিহ্নের একই পাতা, একই চাবি; সুইচ বন্ধ হলে সারি নেই (`messaging.enabled`, [[MenuSwitches]])
            ['label' => 'messaging::menu.chat', 'icon' => 'chat', 'route' => 'messaging.index', 'permission' => 'messaging.use'],
        ],
    ],

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
            // ⓘ one switch only — the core "general" group, already named in core.settings_group
            'group' => 'general',
        ],
    ],

    /*
     * ⛔ Not audited, each on purpose — the full reasons sit in each model's docblock and in
     * EveryChangeableRowRemembersWhoChangedItTest. The thread itself IS audited ([[Thread]]), and so is every audit reading.
     */
    'audit_exempt' => [
        Message::class => 'privacy — the trail would copy every message; the messages of another person are read only through the written-reason audit door',
        MessageFile::class => 'part of its message, same privacy; never changes after it lands',
        Member::class => 'the delivered/read marks move on every poll — the trail would fill with ticks',
        MemberMark::class => 'written once when a mark moves; the row is itself the history',
        HiddenMessage::class => '"delete for me" — one person tidying their own chat; the message is untouched',
        Call::class => 'the row is the call log itself; only the steps of the call itself move it',
    ],
];
