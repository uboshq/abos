<?php

declare(strict_types=1);

/**
 * ডকুমেন্ট ম্যানেজমেন্ট (DOC) — আজ কেবল কাঠামো, কোড পরে।
 *
 * ⭐ মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬: *"ei plan soho live e ekta module
 * baniye rakho but code pore korbo"*। পরিকল্পনা:
 * `docs/ডকুমেন্ট-ম্যানেজমেন্ট-পরিকল্পনা.md`।
 *
 * ── ⓘ আজ কী আছে ──────────────────────────────────────────────────────
 * সাইডবারে মালিকের ২১টা মেনু (মালিকের বাছাই: *"২১টা মেনুই দেখাবে"*), আর
 * প্রতিটা সারি একটা **সত্যিকারের পাতা** খোলে — পাতাটা পরিকল্পনা থেকে বলে
 * পর্দাটা কী করবে, কীসের উপর দাঁড়াবে, আর উপরে "আসছে"।
 *
 * ── ⛔ আজ কী নেই, আর ইচ্ছাকৃতভাবে ───────────────────────────────────
 * কোনো টেবিল, মাইগ্রেশন, মডেল, ফর্ম বা বোতাম নেই। ⚠️ কাজ-না-করা একটা
 * বোতাম না-থাকা বোতামের চেয়ে খারাপ — মানুষ চাপেন, কিছু হয় না, আর ভাবেন
 * ব্যবস্থাটা নষ্ট।
 *
 * ── ⓘ কেন `planned` পতাকা নয় ─────────────────────────────────────────
 * `planned` সারি মেনুতে নিভে থাকে, ক্লিক করা যায় না ([[MenuBuilder]])।
 * ⭐ মালিক চান প্রতিটা সারি খুলুক আর পরিকল্পনাটা দেখাক — তাই সারিগুলো
 * আসল রুটে যায় ([[PlanController]]), ঠিক বিক্রয়ের "মেনুতে আগে, কোড পরে"
 * পাতার ছাঁচে ([[PlannedScreenController]])।
 *
 * ── ⓘ কেন একটাই চাবি ─────────────────────────────────────────────────
 * আজ পাতাগুলো কেবল পড়ার, আর সবগুলো একই কথা বলে (পরিকল্পনা)। বিশটা চাবি
 * বানালে ভূমিকার পর্দায় বিশটা টিক বসত যারা কিছুই আটকায় না। ⭐ পর্দা
 * তৈরির দিন তার নিজের চাবি আসবে (§১৩: VIEW, UPLOAD, EDIT, …)।
 */
return [
    'code' => 'documents',

    'name' => [
        'en' => 'Documentation Management',
        'bn' => 'ডকুমেন্ট ম্যানেজমেন্ট',
    ],

    'version' => '0.1.0',

    /*
     * ⭐ সাইডবারে মাস্টার ডাটা (৫) আর সিস্টেম অ্যাডমিনের (১০) মাঝখানে — মালিক, ৩০ সেপ্টেম্বর ২০২৬:
     * *"DOC system r MDM er majkhane dibe"*।
     */
    'nav' => ['section' => 'system', 'order' => 7],

    /*
     * ⓘ কারও উপর নির্ভর করে না: যে ব্যবস্থাগুলোর কথা পাতায় লেখা
     * (অনুমোদন, অডিট, নম্বর সিরিজ…) সবই কোরের, কোনো মডিউলের নয়।
     */
    'depends_on' => [],

    /*
     * ⭐ আসল ড্যাশবোর্ড — ERP-তে জোড়া নথি, ভাগ করা লিংক আর পাঠানো কাগজ থেকে
     * (মালিকের ড্যাশবোর্ড নকশা §১৩, ৬ অক্টোবর ২০২৬)। আগে এখানে কোনো সংখ্যা ছিল না,
     * তাই মেনু পরিকল্পনার পাতায় যেত; সেই পাতা ([[PlanController::dashboard()]])
     * এখনো আছে, প্রতিটা ডকুমেন্ট পর্দার নিচের "গোটা পরিকল্পনা দেখুন" লিংকে।
     */
    'dashboard' =>\App\Modules\Documents\Dashboard\DocumentsDashboard::class,

    'menu' => [
        'dashboard' => [
            ['label' => 'documents::menu.dashboard', 'icon' => 'dashboard', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'documents'], 'permission' => 'documents.view'],
        ],

        /*
         * ⓘ মালিকের ক্রমে। ⚠️ অনুমোদনের সারি আর সই কেন্দ্র ABOS-এর নিয়মে
         * "অনুমোদন" ভাগে বসে, রিপোর্ট ও অডিট "রিপোর্ট" ভাগে, প্রশাসন
         * "সেটিংস"-এ — প্রতিটা মডিউলে একই ছয়-ভাগ ক্রম ([[MenuBuilder::inFixedOrder()]])।
         */
        'transactions' => [
            ['label' => 'documents::menu.center', 'icon' => 'building', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'center'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.inbox', 'icon' => 'inbox', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'inbox'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.upload', 'icon' => 'attachment', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'upload'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.scan', 'icon' => 'receipt', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'scan'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.intelligence', 'icon' => 'filter', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'intelligence'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.mine', 'icon' => 'list', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'mine'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.shared', 'icon' => 'share', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'shared'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.recent', 'icon' => 'clock', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'recent'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.favourite', 'icon' => 'star', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'favourite'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.templates', 'icon' => 'columns', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'templates'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.editor', 'icon' => 'edit', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'editor'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.expiry', 'icon' => 'calendar', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'expiry'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.archive', 'icon' => 'drawer', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'archive'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.recycle', 'icon' => 'trash', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'recycle'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.search', 'icon' => 'search', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'search'], 'permission' => 'documents.view'],
        ],

        'approval' => [
            ['label' => 'documents::menu.approval', 'icon' => 'check_circle', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'approval'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.signature', 'icon' => 'handover', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'signature'], 'permission' => 'documents.view'],
        ],

        'reports' => [
            ['label' => 'documents::menu.reports', 'icon' => 'reports', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'reports'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.audit', 'icon' => 'eye', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'audit'], 'permission' => 'documents.view'],
        ],

        'settings' => [
            ['label' => 'documents::menu.admin', 'icon' => 'settings', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'admin'], 'permission' => 'documents.view'],
        ],
    ],

    'permissions' => [
        /*
         * পরিকল্পনা পড়ার চাবি — আজ একটাই।
         *
         * ⓘ ডিপ্লয়ের `abos:sync-permissions` (deploy.sh আর `abos:optimise`
         * দুইটাই ডাকে) চাবিটা বানায় আর প্রতিটা কোম্পানির super_admin-এ বসায়
         * ([[PermissionSyncer::keepOwnerComplete()]])। ⚠️ বাকি চলমান ভূমিকায়
         * নিজে থেকে যায় না — কে DOC দেখবেন সেটা মালিকের সিদ্ধান্ত।
         */
        'documents.view',
    ],

    // ⓘ টাকা নড়ে না, অনুমোদনও চায় না — আজ কোনো কাজই নেই
    'moves_money' => [],
];
