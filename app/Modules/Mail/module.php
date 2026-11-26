<?php

declare(strict_types=1);

use App\Modules\Mail\Models\MailLink;
use App\Modules\Mail\Models\MailOutbox;
use App\Modules\Mail\Models\MailSend;

/*
 * ⭐ মেইল — একদম আলাদা মডিউল (মালিকের চূড়ান্ত, ২৬ নভেম্বর ২০২৬), নিজের ট্যাবে; নমুনা v4 "ok"।
 * ⓘ মেইল থাকে মেইল সার্ভারে (IMAP/SMTP); ABOS কেবল দেখায়, পাঠায়, কাগজ সংযুক্ত করে আর খাতা রাখে।
 */
return [
    'code' => 'mail',

    'name' => [
        'en' => 'Mail',
        'bn' => 'মেইল',
    ],

    'version' => '1.0.0',

    // ⓘ নিজের ঘর — বার্তার নিচে নয় (মালিক: "একদম আলাদা মডিউল")
    'nav' => ['section' => 'system', 'order' => 16],

    // ⓘ গ্রাহক/সরবরাহকারীর কার্ড ও ঘর, বিক্রয়-হিসাব-ক্রয়ের কাগজ সংযুক্ত, পয়েন্ট, পাসওয়ার্ড বার্তায়
    'depends_on' => ['customer', 'supplier', 'sales', 'accounts', 'purchase', 'promotion', 'messaging'],

    'menu' => [
        'transactions' => [
            // ⓘ নিজের ট্যাবে খোলে ([[counter-mode.js]] /mail)
            ['label' => 'mail::menu.mail', 'icon' => 'mail', 'route' => 'mail.index', 'permission' => 'mail.use'],
        ],
        'master' => [
            ['label' => 'mail::menu.templates', 'icon' => 'documents', 'route' => 'mail.templates', 'permission' => 'mail.use'],
            ['label' => 'mail::menu.signature', 'icon' => 'edit', 'route' => 'mail.signature', 'permission' => 'mail.use'],
        ],
        'settings' => [
            ['label' => 'mail::menu.addresses', 'icon' => 'settings', 'route' => 'mail.addresses', 'permission' => 'mail.manage'],
        ],
        'reports' => [
            ['label' => 'mail::menu.sent', 'icon' => 'outbox', 'route' => 'mail.sent', 'permission' => 'mail.manage'],
            ['label' => 'mail::menu.history', 'icon' => 'book', 'route' => 'mail.history', 'permission' => 'mail.manage'],
        ],
    ],

    'permissions' => [
        // নিজের ও দেওয়া দলের মেইলবক্স দেখা আর পাঠানো; নিজের সই আর নিজের টেমপ্লেট
        'mail.use',
        // কোম্পানির মেইলবক্স, নিজের ডোমেইনের ঠিকানা, কোম্পানির টেমপ্লেট, উৎসব, খাতা
        'mail.manage',
        // ব্যক্তিগত মেইলের অনুরোধে হ্যাঁ/না
        'mail.personal.approve',
        // ঠিকানা মোছা — মালিক নিজে পারেন; অন্য কাউকে দিতে হলে এই চাবি
        'mail.addresses.delete',
    ],

    'role_templates' => [
        'Manager' => ['mail.use'],
        'Accountant' => ['mail.use'],
    ],

    // ⓘ আসল সার্ভার; পরীক্ষায় FakeMailServer / FakeMailHost বসানো হয় (app()->instance)
    'bindings' => [
        \App\Modules\Mail\Services\MailServer::class => \App\Modules\Mail\Services\ImapMailServer::class,
        \App\Modules\Mail\Services\MailHost::class => \App\Modules\Mail\Services\CpanelMailHost::class,
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
        [
            // ⓘ 0 = যার যার পছন্দ; 1–20 = সবার নতুন মেইলে এই নকশা
            'key' => 'mail.signature_forced',
            'label' => 'mail::settings.signature_forced',
            'type' => 'integer',
            'default' => 0,
            'group' => 'general',
        ],
        [
            // ⓘ নিজে থেকে পাঠানো মেইল কোন মেইলবক্স থেকে (মেইলবক্সের public id); খালি = কিছুই যায় না
            'key' => 'mail.auto_from',
            'label' => 'mail::settings.auto_from',
            'type' => 'string',
            'default' => '',
            'group' => 'general',
        ],
    ],

    'audit_exempt' => [
        MailLink::class => 'a mail kept on a party page — the row is itself the record (who, when, which mail); no money, no state',
        MailOutbox::class => 'a waiting scheduled mail — encrypted, deleted the moment it is sent; the send itself is logged and audited',
        MailSend::class => 'the sent-mail log — the row is itself the record and never changes; each send also writes an audit line on its mailbox',
    ],
];
