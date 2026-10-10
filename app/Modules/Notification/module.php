<?php

declare(strict_types=1);

use App\Models\NotificationAuditLog;
use App\Models\NotificationEvent;

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
        ],
        'reports' => [
            // ⭐ কে কখন কোন খবরে কী করলেন (স্পেক §১৩)
            ['label' => 'notification::menu.audit', 'icon' => 'book', 'route' => 'notification.audit.index', 'permission' => 'notification.audit'],
        ],
    ],

    'permissions' => [
        'notification.center',  // বিজ্ঞপ্তি কেন্দ্র দেখা — কোম্পানির খবর, নাগালের শাখার
        'notification.manage',  // কেন্দ্র থেকে আর্কাইভ বা ফেরত
        'notification.audit',   // নিরীক্ষার খাতা দেখা
    ],

    'role_templates' => [
        'Notification Watcher' => ['notification.center'],
        'Notification Manager' => ['notification.center', 'notification.manage', 'notification.audit'],
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
    ],

    'audit_exempt' => [
        NotificationEvent::class => 'খবর একটা ঘটনার প্রতিধ্বনি — আসল ঘটনা নিজের জায়গায় নিরীক্ষিত, আর খবরের ওপর মানুষের কাজ notification_audit_logs-এ',
        NotificationAuditLog::class => 'নিজেই নিরীক্ষার খাতা, আর কখনো বদলায় না — এর নিরীক্ষা রাখলে প্রতিটা সারি দুইবার লেখা হত',
    ],
];
