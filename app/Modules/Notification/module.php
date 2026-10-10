<?php

declare(strict_types=1);

/**
 * ⭐ বিজ্ঞপ্তি ব্যবস্থাপনা — মালিকের স্পেক, ১০ অক্টোবর ২০২৬ (`docs/cloud-tasks/notification-management-spec-bn.md`)।
 *
 * ── এই মডিউল কী, আর কী নয় ───────────────────────────────────────────
 * খবর পাঠানোর ইঞ্জিন কোরে ([[NotificationService]]) — প্রতিটা মডিউল নিজের ঘটনায় সেটাই ডাকে, আর কোর কোনো মডিউলের নাম
 * জানে না। এই মডিউল সেই ইঞ্জিনের **ব্যবস্থাপনার পর্দাগুলো**: বিজ্ঞপ্তি কেন্দ্র, নিরীক্ষার খাতা, সুইচ — ধাপে ধাপে আরও
 * (মাধ্যম, নিয়ম, টেমপ্লেট, রিপোর্ট)। ⛔ কোনো AI নেই; নিয়ম, সূচি আর শর্ত ছাড়া কিছু নয়। খবর কেবল জানায় — কোনো কাগজ
 * বদলায় না।
 *
 * ⓘ "আমার বিজ্ঞপ্তি" এই মেনুতে নেই: নিজের খবর দেখতে কারও চাবি লাগা উচিত নয়, তাই পাতাটা কোরে (`notifications.index`),
 * প্রতিটা পাতার ঘণ্টা থেকে খোলে।
 */
return [
    'code' => 'notification',
    'name' => ['en' => 'Notifications', 'bn' => 'বিজ্ঞপ্তি'],
    'version' => '1.0.0',
    'nav' => ['section' => 'system', 'order' => 25],
    'depends_on' => [],

    'menu' => [
        'transactions' => [
            // ⭐ কোম্পানির সব খবর — কে পেলেন, কে পড়লেন (স্পেক §২, §৪)
            ['label' => 'notification::menu.center', 'icon' => 'bell', 'route' => 'notification.center.index', 'permission' => 'notification.center'],
            // ⭐ ধাপ ২ — ডেলিভারির কিউ আর ব্যর্থ-তালিকা
            ['label' => 'notification::menu.queue', 'icon' => 'outbox', 'route' => 'notification.deliveries.queue', 'permission' => 'notification.deliveries'],
            ['label' => 'notification::menu.failed', 'icon' => 'alert-triangle', 'route' => 'notification.deliveries.failed', 'permission' => 'notification.deliveries'],
            // ⭐ ধাপ ৩ — সূচিমতো খবর
            ['label' => 'notification::menu.schedules', 'icon' => 'calendar', 'route' => 'notification.schedules.index', 'permission' => 'notification.schedules'],
        ],
        'reports' => [
            // ⭐ কে কখন কোন খবরে কী করলেন (স্পেক §১৩)
            ['label' => 'notification::menu.audit', 'icon' => 'book', 'route' => 'notification.audit.index', 'permission' => 'notification.audit'],
            // ⭐ ধাপ ২ — প্রতিটা চেষ্টা, আর মাধ্যমের স্বাস্থ্য
            ['label' => 'notification::menu.logs', 'icon' => 'list', 'route' => 'notification.deliveries.logs', 'permission' => 'notification.deliveries'],
            ['label' => 'notification::menu.health', 'icon' => 'check-circle', 'route' => 'notification.deliveries.health', 'permission' => 'notification.deliveries'],
        ],
        'settings' => [
            // ⭐ ধাপ ২ — মাধ্যমের সাজানো আর সংযোগ পরীক্ষা
            ['label' => 'notification::menu.channels', 'icon' => 'settings', 'route' => 'notification.channels.index', 'permission' => 'notification.channels'],
            // ⭐ ধাপ ৩ — নিয়ম, টেমপ্লেট, প্রাপক-দল, নীরব সময় ও সারসংক্ষেপ
            ['label' => 'notification::menu.rules', 'icon' => 'filter', 'route' => 'notification.rules.index', 'permission' => 'notification.rules'],
            ['label' => 'notification::menu.templates', 'icon' => 'documents', 'route' => 'notification.templates.index', 'permission' => 'notification.templates'],
            ['label' => 'notification::menu.groups', 'icon' => 'people', 'route' => 'notification.groups.index', 'permission' => 'notification.recipients'],
            ['label' => 'notification::menu.quiet', 'icon' => 'moon', 'route' => 'notification.quiet.index', 'permission' => 'notification.preferences'],
        ],
    ],

    'permissions' => [
        'notification.center',  // বিজ্ঞপ্তি কেন্দ্র দেখা — কোম্পানির খবর, নাগালের শাখার
        'notification.manage',  // কেন্দ্র থেকে আর্কাইভ বা ফেরত
        'notification.audit',   // নিরীক্ষার খাতা দেখা
        'notification.channels',   // মাধ্যম সাজানো, চাবি, সংযোগ পরীক্ষা (ধাপ ২)
        'notification.deliveries', // কিউ, লগ, ব্যর্থ-তালিকা, স্বাস্থ্য দেখা
        'notification.retry',      // হাতে আবার চেষ্টা বা বাতিল
        'notification.rules',      // নিয়ম লেখা ও পরীক্ষা (ধাপ ৩)
        'notification.templates',  // টেমপ্লেট লেখা, পূর্বরূপ, পরীক্ষার পাঠানো
        'notification.templates.publish', // টেমপ্লেট প্রকাশ বা আগের সংস্করণে ফেরা
        'notification.recipients', // প্রাপক-দল
        'notification.schedules',  // সূচিমতো খবর
        'notification.preferences', // কোম্পানির স্বাভাবিক নীরব সময়, কে কী বেছেছেন
    ],

    'role_templates' => [
        'Notification Watcher' => ['notification.center'],
        'Notification Manager' => ['notification.center', 'notification.manage', 'notification.audit',
            'notification.channels', 'notification.deliveries', 'notification.retry',
            'notification.rules', 'notification.templates', 'notification.templates.publish', 'notification.recipients',
            'notification.schedules', 'notification.preferences'],
        // ⭐ ধাপ ৩ — লেখেন, প্রকাশ করেন না (প্রকাশ আরেকজন)
        'Notification Writer' => ['notification.rules', 'notification.templates', 'notification.recipients', 'notification.schedules'],
    ],

    'settings' => [
        [
            /*
             * ⛔ ঘণ্টার polling — ডিফল্টে বন্ধ। মালিক চলমান polling ২১ অক্টোবর ২০২৬-এর পরে পর্যন্ত স্থগিত রেখেছেন (cloud task)।
             * ⓘ বন্ধ থাকলে ঘণ্টা পাতা খোলার সময়ের সংখ্যাই দেখায়, আগের মতো।
             */
            'key' => 'notification.bell_polling',
            'label' => 'notification::settings.bell_polling',
            'type' => 'boolean',
            'default' => false,
            'group' => 'bell',
        ],
        [
            // ⓘ কত সেকেন্ড পর পর — ১৫-এর নিচে নয় (সার্ভারেও মিনিটে ৩০ বারের সীমা)
            'key' => 'notification.bell_poll_seconds',
            'label' => 'notification::settings.bell_poll_seconds',
            'type' => 'integer',
            'default' => 60,
            'group' => 'bell',
        ],
        [
            // ⭐ ধাপ ২ — একটা খবর একটা মাধ্যমে সর্বোচ্চ কতবার (১–১০); পেরোলে ব্যর্থ-তালিকা
            'key' => 'notification.max_attempts',
            'label' => 'notification::settings.max_attempts',
            'type' => 'integer',
            'default' => 5,
            'group' => 'delivery',
        ],
        [
            // ⭐ ধাপ ৩ — কোম্পানির স্বাভাবিক নীরব সময় (HH:MM, ঢাকার নয় — কোম্পানির সময় অঞ্চলে); ফাঁকা = নীরব সময় নেই।
            // ⓘ মালিকের প্রশ্ন খোলা: কোন সময়টা স্বাভাবিক হবে — তাই ডিফল্টে ফাঁকা
            'key' => 'notification.quiet_start',
            'label' => 'notification::settings.quiet_start',
            'type' => 'string',
            'default' => '',
            'group' => 'quiet',
        ],
        [
            'key' => 'notification.quiet_end',
            'label' => 'notification::settings.quiet_end',
            'type' => 'string',
            'default' => '',
            'group' => 'quiet',
        ],
    ],

    // ⓘ নতুন দুই মডেল (NotificationEvent, NotificationAuditLog) কোরের — তাদের অডিট-ছাড় EveryChangeableRowRemembersWhoChangedItTest-এ
];
