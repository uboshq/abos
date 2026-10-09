<?php

declare(strict_types=1);

/**
 * ডকুমেন্ট ম্যানেজমেন্ট (DOC) — প্রথম ধাপ চালু, বাকিটা পরিকল্পনার পাতায়।
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
 * ── ⭐ প্রথম ধাপ, ৮ অক্টোবর ২০২৬ ────────────────────────────────────
 * চারটা সারি এখন আসল পর্দা খোলে: ডকুমেন্ট সেন্টার, আপলোড, আমার ডকুমেন্ট আর
 * সাম্প্রতিক ([[DocumentController]]); আর প্রতিটা কাগজের নিজের বিস্তারিত পাতা —
 * প্রিভিউ, বিবরণ, ভার্সন, অডিট। ভিত: `dms_documents` আর `dms_document_versions`,
 * ফাইল ABOS-এর সংযুক্তির খাতায় ([[AttachmentEngine]])। পরিকল্পনার §১, §৪, §৫, §৬,
 * §৯, §১২-র মেয়াদের তারিখ আর §১৩-১৪-র দেয়াল।
 *
 * ── ⛔ আজও কী নেই, আর ইচ্ছাকৃতভাবে ─────────────────────────────────
 * বাকি ষোলোটা সারি আগের মতোই পরিকল্পনার পাতা — স্ক্যান/OCR, ABE, অনুমোদন, সই,
 * শেয়ার, মনে করানো, রিসাইকেল বিন… ⚠️ কাজ-না-করা একটা বোতাম না-থাকা বোতামের
 * চেয়ে খারাপ — মানুষ চাপেন, কিছু হয় না, আর ভাবেন ব্যবস্থাটা নষ্ট।
 *
 * ── ⓘ কেন `planned` পতাকা নয় ─────────────────────────────────────────
 * `planned` সারি মেনুতে নিভে থাকে, ক্লিক করা যায় না ([[MenuBuilder]])।
 * ⭐ মালিক চান প্রতিটা সারি খুলুক আর পরিকল্পনাটা দেখাক — তাই সারিগুলো
 * আসল রুটে যায় ([[PlanController]]), ঠিক বিক্রয়ের "মেনুতে আগে, কোড পরে"
 * পাতার ছাঁচে ([[PlannedScreenController]])।
 *
 * ── ⓘ চাবি ─────────────────────────────────────────────────────────────
 * পরিকল্পনার পাতাগুলো এখনো একটাই চাবি চায় (`documents.view`)। ⭐ আসল পর্দার
 * প্রতিটা কাজের নিজের চাবি এসেছে (§১৩) — নিচের `permissions`-এ, কারণসহ।
 */
return [
    'code' => 'documents',

    'name' => [
        'en' => 'Documentation Management',
        'bn' => 'ডকুমেন্ট ম্যানেজমেন্ট',
    ],

    'version' => '0.2.0',

    /*
     * ⭐ সাইডবারে মাস্টার ডাটা (৫) আর সিস্টেম অ্যাডমিনের (১০) মাঝখানে — মালিক, ৩০ সেপ্টেম্বর ২০২৬:
     * *"DOC system r MDM er majkhane dibe"*।
     */
    'nav' => ['section' => 'system', 'order' => 7],

    /*
     * ⓘ যে ব্যবস্থাগুলোর কথা পাতায় লেখা (অনুমোদন, অডিট, নম্বর সিরিজ…) সবই কোরের।
     * ⭐ একটাই মডিউল — মাস্টার ডাটা, কাগজের "বিভাগ" ঘরের জন্য (§৬: Department)।
     * ⚠️ বিভাগের তালিকা দ্বিতীয়বার বানালে HR আর DOC-এ একই বিভাগ দুই নামে থাকত।
     */
    'depends_on' => ['master_data'],

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
            // ⭐ প্রথম ধাপের আসল পর্দা (৮ অক্টোবর ২০২৬) — সেন্টার, আপলোড, আমার, সাম্প্রতিক
            ['label' => 'documents::menu.center', 'icon' => 'building', 'route' => 'documents.index',
                'permission' => 'documents.view'],
            ['label' => 'documents::menu.inbox', 'icon' => 'inbox', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'inbox'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.upload', 'icon' => 'attachment', 'route' => 'documents.create',
                'permission' => 'documents.upload'],
            ['label' => 'documents::menu.scan', 'icon' => 'receipt', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'scan'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.intelligence', 'icon' => 'filter', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'intelligence'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.mine', 'icon' => 'list', 'route' => 'documents.mine',
                'permission' => 'documents.view'],
            ['label' => 'documents::menu.shared', 'icon' => 'share', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'shared'], 'permission' => 'documents.view'],
            ['label' => 'documents::menu.recent', 'icon' => 'clock', 'route' => 'documents.recent',
                'permission' => 'documents.view'],
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
         * দেখার চাবি — সেন্টার, তালিকা, বিস্তারিত আর প্রিভিউ; পরিকল্পনার পাতাগুলোও।
         *
         * ⓘ ডিপ্লয়ের `abos:sync-permissions` (deploy.sh আর `abos:optimise`
         * দুইটাই ডাকে) চাবিগুলো বানায় আর প্রতিটা কোম্পানির super_admin-এ বসায়
         * ([[PermissionSyncer::keepOwnerComplete()]])। ⚠️ বাকি চলমান ভূমিকায়
         * নিজে থেকে যায় না — কে DOC-এ কী করবেন সেটা মালিকের সিদ্ধান্ত। তাই
         * `role_templates`-ও নেই।
         */
        'documents.view',

        /*
         * ⭐ প্রতিটা কাজের নিজের চাবি — পরিকল্পনা §১৩ (৮ অক্টোবর ২০২৬)।
         * ⓘ কাগজের উপর প্রতিটা কাজ আগে "দেখা" চায় ([[DocumentPolicy]])।
         */
        'documents.upload',     // নতুন কাগজ তোলা
        'documents.edit',       // বিবরণ বদল আর নতুন ভার্সন
        'documents.delete',     // মোছা — নরম; ফেরানোর পর্দা রিসাইকেল বিনে (§১৯, পরের ধাপ)
        'documents.download',   // ফাইল নামানো, পুরনো ভার্সনসহ
        'documents.print',      // ছাপা — ফাইল নিজের ট্যাবে খোলে, অডিটে "ছাপা"
        'documents.archive',    // আর্কাইভ — সেন্টার থেকে সরে, মোছে না
        'documents.restore',    // আর্কাইভ থেকে ফেরানো, আর পুরনো ভার্সন ফেরানো

        /*
         * ⭐ গোপনীয়তার সিঁড়ি — পরিকল্পনা §১৪ (*"restricted-এ বাড়তি permission"*)।
         * ⓘ সবার জন্য আর অভ্যন্তরীণ — দেখার চাবিতেই; উপরের ধাপের চাবি নিচের সব ধাপ
         * খোলে ([[DocumentAccess]])। কাগজের মালিক আর যিনি তুলেছেন, তাঁরা নিজের কাগজ
         * সবসময় দেখেন।
         */
        'documents.confidential',
        'documents.highly_confidential',
        'documents.restricted',
    ],

    /*
     * ⭐ প্রতিটা কাগজের নিজের ফাঁকহীন নম্বর — DOC-0001 (পরিকল্পনা §২১: document_number)।
     */
    'doc_types' => [
        'DOC' => 'documents::doc.document_no',
    ],

    // ⓘ টাকা নড়ে না, অনুমোদনও চায় না (অনুমোদনের ধারা §১০ পরের ধাপে)
    'moves_money' => [],
];
