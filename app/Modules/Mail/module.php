<?php

declare(strict_types=1);

use App\Modules\Mail\Models\MailLink;

/*
 * ⭐ মেইল — কোম্পানির মেইল ABOS-এর ভিতরে, নিজের ট্যাবে (মালিক, ২৬ নভেম্বর ২০২৬, নমুনা v3 "ok")।
 * ⓘ মেইল থাকে মেইল সার্ভারে (IMAP/SMTP); ABOS কেবল দেখায়, পাঠায় আর কাগজের সাথে বাঁধে।
 */
return [
    'code' => 'mail',

    'name' => [
        'en' => 'Mail',
        'bn' => 'মেইল',
    ],

    'version' => '0.1.0',

    'nav' => ['section' => 'system', 'order' => 91, 'under' => 'notification', 'merged_only' => true],

    'depends_on' => [],

    'menu' => [
        'transactions' => [
            ['label' => 'mail::menu.mail', 'icon' => 'mail', 'route' => 'mail.index', 'permission' => 'mail.use'],
        ],
    ],

    'permissions' => [
        // নিজের ও দেওয়া দলের মেইলবক্স দেখা আর পাঠানো
        'mail.use',
        // কোম্পানির মেইলবক্স যোগ, দলের সদস্য ঠিক করা, নতুন কোম্পানি ঠিকানা খোলা
        'mail.manage',
        // ব্যক্তিগত মেইলের অনুরোধে হ্যাঁ/না
        'mail.personal.approve',
    ],

    'role_templates' => [
        'Manager' => ['mail.use'],
        'Accountant' => ['mail.use'],
    ],

    // ⓘ আসল সার্ভার; পরীক্ষায় FakeMailServer বসানো হয় (app()->instance)
    'bindings' => [
        \App\Modules\Mail\Services\MailServer::class => \App\Modules\Mail\Services\ImapMailServer::class,
    ],

    'moves_money' => [],

    'settings' => [
        [
            'key' => 'mail.personal_allowed',
            'label' => 'mail::settings.personal_allowed',
            'type' => 'boolean',
            'default' => true,
            'group' => 'general',
        ],
        [
            'key' => 'mail.personal_months',
            'label' => 'mail::settings.personal_months',
            'type' => 'integer',
            'default' => 6,
            'group' => 'general',
        ],
    ],

    'audit_exempt' => [
        MailLink::class => 'a mail tied to a paper — the row is itself the record (who, when, which mail); no money, no state',
    ],
];
