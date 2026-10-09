<?php

declare(strict_types=1);
use App\Core\Contracts\LinkedDocuments;
use App\Core\Events\ApprovalDecided;
use App\Modules\Documents\Dashboard\DocumentsDashboard;
use App\Modules\Documents\Listeners\MoveTheDocumentOnItsSignature;
use App\Modules\Documents\Models\DocumentCategory;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Models\MetadataField;
use App\Modules\Documents\Reports\DocumentReports;
use App\Modules\Documents\Services\DocumentAccess;
use App\Modules\Documents\Services\DocumentLinks;
use App\Modules\Documents\Services\DocumentSignatures;
use App\Modules\Documents\Services\DocumentWorkflow;
use App\Modules\MasterData\Models\Department;

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

    'version' => '1.0.0',

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
    'dashboard' => DocumentsDashboard::class,

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
            // ⭐ স্ক্যান ও OCR (পঞ্চম ধাপ) — লেখা পড়া ব্যবহারকারীর ব্রাউজারে, নিজের সার্ভারের ফাইলে
            ['label' => 'documents::menu.scan', 'icon' => 'receipt', 'route' => 'documents.scan',
                'permission' => 'documents.upload'],
            // ⭐ Document Intelligence (ABE) — নিয়মে, নিজের সার্ভারে (ষষ্ঠ ধাপ)
            ['label' => 'documents::menu.intelligence', 'icon' => 'filter', 'route' => 'documents.intelligence',
                'permission' => 'documents.view'],
            ['label' => 'documents::menu.mine', 'icon' => 'list', 'route' => 'documents.mine',
                'permission' => 'documents.view'],
            ['label' => 'documents::menu.shared', 'icon' => 'share', 'route' => 'documents.shared',
                'permission' => 'documents.view'],
            ['label' => 'documents::menu.recent', 'icon' => 'clock', 'route' => 'documents.recent',
                'permission' => 'documents.view'],
            ['label' => 'documents::menu.favourite', 'icon' => 'star', 'route' => 'documents.screen',
                'route_params' => ['screen' => 'favourite'], 'permission' => 'documents.view'],
            // ⭐ সপ্তম ধাপ — ছাঁচ আর সম্পাদক (ABOS-এর ছাপার যন্ত্রে PDF)
            ['label' => 'documents::menu.templates', 'icon' => 'columns', 'route' => 'documents.templates',
                'permission' => 'documents.view'],
            ['label' => 'documents::menu.editor', 'icon' => 'edit', 'route' => 'documents.templates.create',
                'permission' => 'documents.templates'],
            ['label' => 'documents::menu.expiry', 'icon' => 'calendar', 'route' => 'documents.expiry',
                'permission' => 'documents.view'],
            // ⭐ দ্বিতীয় ধাপের আসল পর্দা (৯ অক্টোবর ২০২৬) — আর্কাইভ, রিসাইকেল বিন, বিস্তারিত খোঁজ
            ['label' => 'documents::menu.archive', 'icon' => 'drawer', 'route' => 'documents.archived',
                'permission' => 'documents.view'],
            ['label' => 'documents::menu.recycle', 'icon' => 'trash', 'route' => 'documents.bin',
                'permission' => 'documents.view'],
            ['label' => 'documents::menu.search', 'icon' => 'search', 'route' => 'documents.search',
                'permission' => 'documents.view'],
        ],

        'approval' => [
            // ⭐ অনুমোদনের সারি — ABOS-এর সইয়ের ইনবক্স, কেবল ডকুমেন্ট ছেঁকে (তৃতীয় ধাপ)
            ['label' => 'documents::menu.approval', 'icon' => 'check_circle', 'route' => 'documents.approval',
                'permission' => 'documents.view'],
            ['label' => 'documents::menu.signature', 'icon' => 'handover', 'route' => 'documents.signatures',
                'permission' => 'documents.view'],
        ],

        'reports' => [
            // ⭐ সপ্তম ধাপ — ABOS-এর রিপোর্ট ইঞ্জিনে চৌদ্দটা রিপোর্ট, আর অডিট ট্রেইল
            ['label' => 'documents::menu.reports', 'icon' => 'reports', 'route' => 'documents.reports',
                'permission' => 'documents.report'],
            ['label' => 'documents::menu.audit', 'icon' => 'eye', 'route' => 'documents.audit',
                'permission' => 'documents.audit'],
        ],

        'settings' => [
            ['label' => 'documents::menu.admin', 'icon' => 'settings', 'route' => 'documents.admin',
                'permission' => 'documents.admin'],
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
        'documents.restore',    // আর্কাইভ থেকে ফেরানো, পুরনো ভার্সন ফেরানো, আর রিসাইকেল বিন থেকে ফেরানো

        /*
         * ⭐ দ্বিতীয় ধাপ (৯ অক্টোবর ২০২৬)।
         * ⛔ চিরতরে মোছা নিজের চাবিতে — পরিকল্পনা §১৯: *"permission অনুযায়ী permanent delete"*।
         */
        'documents.purge',
        'documents.permissions', // একটা কাগজে কাউকে দেখা/নামানো/ছাপা/বদলের অধিকার দেওয়া (§১৩)
        'documents.admin',       // প্রশাসন — ধরন, ফোল্ডার, ট্যাগ, বাড়তি ঘর (§২০)

        /*
         * ⭐ তৃতীয় ধাপ (৯ অক্টোবর ২০২৬) — অনুমোদনের ধারা (§১০)।
         * ⓘ সই দেওয়ার চাবি ABOS-এর অনুমোদনের নিজের (`approval.decide`); এখানে কেবল কাগজের দিক।
         */
        'documents.submit',     // অনুমোদনে পাঠানো আর ফেরত নেওয়া
        'documents.publish',    // অনুমোদিত কাগজ প্রকাশ করা

        /*
         * ⭐ চতুর্থ ধাপ (৯ অক্টোবর ২০২৬) — শেয়ার (§১৪), সই চাওয়া (§১১)।
         * ⓘ সই দেওয়া নিজে ABOS-এর অনুমোদনের চাবিতে (`approval.decide`), কাগজ জোড়া বদলের চাবিতে।
         */
        'documents.share',
        'documents.signature_request',

        /*
         * ⭐ সপ্তম ধাপ (৯ অক্টোবর ২০২৬) — রিপোর্ট (§১৭), অডিট ট্রেইল (§১৮), ছাঁচ লেখা (§২)।
         */
        'documents.report',
        'documents.audit',
        'documents.templates',

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

    /*
     * ⭐ বিভাগের সীমা (দেয়ালের তৃতীয় ধাপ: কোম্পানি → শাখা → **বিভাগ** → কর্মী → কাগজ)।
     * ⓘ ব্যবহারকারীর পর্দায় শাখা আর গুদামের পাশে "বিভাগ" ঘর বসে ([[UserController::scopeKinds()]]);
     * সীমা দিলে তিনি কেবল ঐ বিভাগগুলোর কাগজ (আর বিভাগহীন কাগজ) দেখেন ([[DocumentAccess]])।
     * ⓘ বিভাগের তালিকা মাস্টার ডাটার — দ্বিতীয়বার বানানো নয়।
     */
    'data_scopes' => [
        DocumentAccess::DEPARTMENT_SCOPE => [
            'model' => Department::class,
            'label' => 'documents::field.department',
        ],
    ],

    /*
     * ⓘ কোম্পানির নিজের ধরন, ফোল্ডার আর বাড়তি ঘর — একই নামে দুইবার নয় ([[DuplicateGuard]])।
     */
    'duplicates' => [
        ['model' => DocumentType::class, 'name' => ['name_en', 'name_bn']],
        ['model' => DocumentCategory::class, 'name' => ['name_en', 'name_bn']],
        ['model' => MetadataField::class, 'name' => ['name_en', 'name_bn']],
    ],

    /*
     * ⭐ ফাইলের সীমা — পরিকল্পনা §২০ "Storage Settings" (দ্বিতীয় ধাপ)।
     * ⓘ PDF সবসময় চলে; বাকি তিন পরিবার কোম্পানি বন্ধ করতে পারে ([[DocumentFiles::allowed()]])।
     * ⛔ মাপ ১ থেকে ১০ MB — ১০-এর বেশি লিখলেও ১০ (শেয়ার্ড সার্ভারের সীমা)।
     */
    'settings' => [
        ['key' => 'documents.max_upload_mb', 'label' => 'documents::settings.max_upload_mb',
            'type' => 'integer', 'default' => 10, 'group' => 'limits'],
        ['key' => 'documents.allow_images', 'label' => 'documents::settings.allow_images',
            'type' => 'boolean', 'default' => true, 'group' => 'limits'],
        ['key' => 'documents.allow_office', 'label' => 'documents::settings.allow_office',
            'type' => 'boolean', 'default' => true, 'group' => 'limits'],
        ['key' => 'documents.allow_text', 'label' => 'documents::settings.allow_text',
            'type' => 'boolean', 'default' => true, 'group' => 'limits'],

        /*
         * ⭐ OCR (§২০ OCR Settings; পঞ্চম ধাপ) — ব্রাউজারে লেখা পড়া চালু কি না, আর কোন ভাষায়।
         * ⓘ বাংলা+ইংরেজি একসাথে ধীর কিন্তু মিশ্র কাগজে ঠিক; কেবল একটা ভাষার কাগজে সেটা বাছলে দ্রুত।
         */
        ['key' => 'documents.ocr_enabled', 'label' => 'documents::settings.ocr_enabled',
            'type' => 'boolean', 'default' => true, 'group' => 'entry'],
        ['key' => 'documents.ocr_languages', 'label' => 'documents::settings.ocr_languages',
            'type' => 'choice', 'options' => ['ben+eng', 'ben', 'eng'], 'option_label' => 'documents::settings.language_',
            'default' => 'ben+eng', 'group' => 'entry'],

        /*
         * ⭐ ABE (§২০ Intelligence Settings; ষষ্ঠ ধাপ) — চালু কি না, আর সারাংশে কয়টা লাইন।
         */
        ['key' => 'documents.abe_enabled', 'label' => 'documents::settings.abe_enabled',
            'type' => 'boolean', 'default' => true, 'group' => 'entry'],
        ['key' => 'documents.abe_summary_lines', 'label' => 'documents::settings.abe_summary_lines',
            'type' => 'integer', 'default' => 5, 'group' => 'entry'],
    ],

    /*
     * ⭐ অনুমোদন (§১০; তৃতীয় ধাপ) — ধারা ঠিক হয় ABOS-এর অনুমোদনের ধারার পর্দায়, নতুন যন্ত্র নয়।
     * ⓘ শেষ সইয়ে কাগজ নিজে নড়ে ([[MoveTheDocumentOnItsSignature]])।
     */
    'approvals' => [
        DocumentWorkflow::ACTION => 'documents::approval.document',
        // ⭐ সই (§১১; চতুর্থ ধাপ) — একজন, অনেকে বা পরপর: ধারার পর্দায় স্তর আর "সবাই/অন্তত N জন"
        DocumentSignatures::ACTION => 'documents::approval.signature',
    ],

    // ⓘ টাকা নড়ে না — তাই একসাথে অনেক অনুমোদনেও চলে
    'moves_money' => [],

    /*
     * ⭐ রেকর্ডের পাতায় জোড়া কাগজ (§১৫; চতুর্থ ধাপ) — কোরের চুক্তি, যাতে গ্রাহক বা ক্রয়ের পাতা DOC-এর
     * কোনো ক্লাস না চেনে ([[x-ui.linked-documents]])।
     */
    /*
     * ⭐ রিপোর্ট (§১৭; সপ্তম ধাপ) — ABOS-এর রিপোর্ট ইঞ্জিনে; পাতা ABOS-এর এক রিপোর্টের পাতা।
     */
    'reports' => [DocumentReports::class],

    'bindings' => [
        LinkedDocuments::class => DocumentLinks::class,
    ],

    'listeners' => [
        ApprovalDecided::class => [MoveTheDocumentOnItsSignature::class],
    ],
];
