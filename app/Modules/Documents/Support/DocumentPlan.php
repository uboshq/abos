<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Audit\AuditEngine;
use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Engines\Image\ImageEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Search\SearchEngine;
use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Core\Services\NotificationService;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Models\ApprovalFlow;

/**
 * ডকুমেন্ট ম্যানেজমেন্টের (DOC) পরিকল্পনা — কোড হিসেবে, কাজ হিসেবে নয়।
 *
 * ⭐ মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬: *"ei plan soho live e ekta module
 * baniye rakho but code pore korbo"*। ⓘ মালিকের বাছাই: সাইডবারে ২১টা মেনুই
 * দেখাবে, প্রতিটা পাতায় পরিকল্পনার অংশ আর "আসছে"।
 *
 * ── ⓘ এখানে কী থাকে ─────────────────────────────────────────────────
 * তিনটা তালিকা: পর্দাগুলো (মেনুর সারি), পরিকল্পনার ২৫টা অংশ, আর ABOS-এর
 * যে ব্যবস্থাগুলোর উপর DOC দাঁড়াবে। ⚠️ লেখাগুলো lang ফাইলে; এখানে কেবল
 * গড়ন — কোন পর্দা কোন অংশের, কোন অংশ কোন ব্যবস্থার উপর।
 *
 * ── ⛔ কেন ব্যবস্থার নাম `::class`, লেখা নয় ──────────────────────────
 * *"যা আছে তা আবার বানানো নয়"* — পরিকল্পনার দ্বিতীয় বাঁধন। ⚠️ পাতায়
 * একটা বানানো নাম লিখলে সেটা পড়ে মনে হত ভিত তৈরি, অথচ ক্লাসটাই নেই।
 * ⭐ `::class` আর তার পরীক্ষা ([[TheDocumentModuleShowsItsPlanTest]]) মিলে
 * নিশ্চিত করে পাতায় যে নাম ছাপা হয়, সেটা রিপোতে সত্যিই আছে।
 *
 * ⓘ প্রথম ধাপের আসল কাজ এখানে নয় — মডেল, সেবা আর পর্দা নিজেদের জায়গায়
 * ([[Document]], [[DocumentLibrary]], [[DocumentController]])। এই ক্লাস কেবল পরিকল্পনা।
 */
final class DocumentPlan
{
    /** অংশের অবস্থা — আজ কাঠামোটুকু লাইভে (মেনু ও এই পাতাগুলো) */
    public const SHELL = 'shell';

    /** অংশের অবস্থা — পরিকল্পিত, কোড এখনো লেখা হয়নি */
    public const PLANNED = 'planned';

    /** অংশের অবস্থা — কোনো পর্দা নয়, কোড লেখার দিন মানার নিয়ম */
    public const RULE = 'rule';

    /** অংশের অবস্থা — চালু, পুরোটা (প্রথম ধাপ, ৮ অক্টোবর ২০২৬) */
    public const LIVE = 'live';

    /** অংশের অবস্থা — চালু, কিন্তু কেবল একটা অংশ; বাকিটা পরের ধাপে */
    public const PARTLY = 'partly';

    /**
     * ABOS-এর যে ব্যবস্থাগুলোর উপর DOC দাঁড়াবে — চাবি => আসল ক্লাস বা trait।
     *
     * ⓘ চাবিটা lang-এর `documents::system.<চাবি>`-এ বাংলা/ইংরেজি বর্ণনা পায়।
     *
     * @var array<string, class-string>
     */
    public const SYSTEMS = [
        'approval' => ApprovalEngine::class,
        'approval_flow' => ApprovalFlow::class,
        'notification' => NotificationService::class,
        'number_series' => NumberSeriesEngine::class,
        'audit_engine' => AuditEngine::class,
        'audit_trait' => IsAudited::class,
        'attachment' => AttachmentEngine::class,
        'image' => ImageEngine::class,
        'report' => ReportEngine::class,
        'dashboard' => DashboardEngine::class,
        'data_scope' => DataScope::class,
        'branch_wall' => ScopedToUserBranch::class,
        'company_wall' => BelongsToCompany::class,
        'search' => SearchEngine::class,
        'print' => PrintEngine::class,
        'settings' => SettingsService::class,
        'permissions' => PermissionSyncer::class,
        'drill' => DrillResolver::class,
        'menu' => MenuBuilder::class,
    ];

    /**
     * মেনুর যে পর্দাগুলো এখনো পরিকল্পনার পাতা খোলে, মালিকের দেওয়া ক্রমে।
     *
     * ⭐ ৮ অক্টোবর ২০২৬, প্রথম ধাপ: সেন্টার, আপলোড, আমার আর সাম্প্রতিক নিজের আসল রুটে
     * সরেছে ([[DocumentController]]) আর এখান থেকে মুছেছে।
     * ⭐ ৯ অক্টোবর ২০২৬, দ্বিতীয় ধাপ: আর্কাইভ, রিসাইকেল বিন, বিস্তারিত খোঁজ আর প্রশাসনও — বাকি বারোটা আগের মতোই।
     * ⭐ তৃতীয় ধাপ: অনুমোদনের সারি আর মেয়াদ ও নবায়নও — বাকি দশটা আগের মতোই।
     * ⭐ চতুর্থ ধাপ: শেয়ার করা ডকুমেন্ট আর সই কেন্দ্রও — বাকি আটটা আগের মতোই।
     * ⭐ পঞ্চম ধাপ: স্ক্যান ও OCR-ও — বাকি সাতটা আগের মতোই।
     * ⭐ ষষ্ঠ ধাপ: Document Intelligence (ABE)-ও — বাকি ছয়টা আগের মতোই।
     *
     * ⓘ `group` — ABOS-এর ছয়-ভাগ মেনুর কোন ভাগে ([[ModuleDefinition::MENU_GROUPS]])।
     * ⓘ `sections` — পরিকল্পনার কোন অংশগুলো এই পর্দার কথা বলে।
     * ⓘ `systems` — উপরের [[SYSTEMS]]-এর চাবি।
     * ⓘ `new` — এমন কিছু লাগবে যা ABOS-এ আজ একেবারেই নেই (lang-এ `new` লেখা)।
     *
     * @var array<string, array{icon: string, group: string, sections: list<int>, systems: list<string>, new: bool}>
     */
    public const SCREENS = [
        'inbox' => ['icon' => 'inbox', 'group' => 'transactions', 'sections' => [2, 10, 11, 23],
            'systems' => ['notification', 'approval'], 'new' => false],
        'favourite' => ['icon' => 'star', 'group' => 'transactions', 'sections' => [2],
            'systems' => ['data_scope'], 'new' => true],
        'templates' => ['icon' => 'columns', 'group' => 'transactions', 'sections' => [2, 21],
            'systems' => ['print', 'number_series'], 'new' => false],
        'editor' => ['icon' => 'edit', 'group' => 'transactions', 'sections' => [9],
            'systems' => ['audit_trait', 'audit_engine', 'attachment'], 'new' => true],
        'reports' => ['icon' => 'reports', 'group' => 'reports', 'sections' => [17],
            'systems' => ['report', 'data_scope'], 'new' => false],
        'audit' => ['icon' => 'eye', 'group' => 'reports', 'sections' => [18],
            'systems' => ['audit_engine', 'audit_trait'], 'new' => false],
    ];

    /**
     * পরিকল্পনার ২৫টা অংশ — অবস্থা, আর কীসের উপর দাঁড়াবে।
     *
     * ⚠️ অবস্থাটা সৎ: §২ (মেনুর কাঠামো) লাইভে; প্রথম ধাপে (৮ অক্টোবর ২০২৬) §১, §৪
     * আর §১৪ পুরোটা, আর §৫, §৬, §৯, §১২, §১৩, §২১, §২২-এর একটা অংশ। §২৪ আর §২৫ কোনো
     * পর্দা নয় — কোড লেখার দিন মানার নিয়ম। ⛔ বাকি সব "আসছে"।
     *
     * @var array<int, array{status: string, systems: list<string>}>
     */
    public const SECTIONS = [
        1 => ['status' => self::LIVE, 'systems' => ['attachment', 'approval', 'audit_engine']],
        2 => ['status' => self::SHELL, 'systems' => ['menu', 'permissions']],
        3 => ['status' => self::PLANNED, 'systems' => ['dashboard', 'data_scope']],
        4 => ['status' => self::LIVE, 'systems' => ['attachment', 'data_scope', 'branch_wall']],
        5 => ['status' => self::LIVE, 'systems' => ['print', 'approval', 'audit_engine']],
        6 => ['status' => self::LIVE, 'systems' => ['attachment', 'image']],
        7 => ['status' => self::LIVE, 'systems' => ['image', 'attachment']],
        8 => ['status' => self::LIVE, 'systems' => ['search']],
        9 => ['status' => self::PARTLY, 'systems' => ['attachment', 'audit_trait']],
        10 => ['status' => self::LIVE, 'systems' => ['approval']],
        11 => ['status' => self::LIVE, 'systems' => ['approval', 'approval_flow']],
        12 => ['status' => self::LIVE, 'systems' => ['notification']],
        13 => ['status' => self::LIVE, 'systems' => ['permissions', 'data_scope', 'branch_wall']],
        14 => ['status' => self::LIVE, 'systems' => ['permissions']],
        15 => ['status' => self::LIVE, 'systems' => ['drill']],
        16 => ['status' => self::LIVE, 'systems' => ['search', 'data_scope']],
        17 => ['status' => self::PLANNED, 'systems' => ['report', 'data_scope']],
        18 => ['status' => self::PLANNED, 'systems' => ['audit_engine', 'audit_trait']],
        19 => ['status' => self::LIVE, 'systems' => ['audit_engine']],
        20 => ['status' => self::PARTLY, 'systems' => ['settings', 'number_series']],
        21 => ['status' => self::PARTLY, 'systems' => ['number_series', 'company_wall', 'branch_wall']],
        22 => ['status' => self::LIVE, 'systems' => ['approval']],
        23 => ['status' => self::LIVE, 'systems' => ['notification']],
        24 => ['status' => self::RULE, 'systems' => ['menu']],
        25 => ['status' => self::RULE, 'systems' => ['attachment', 'search', 'approval', 'audit_engine']],
    ];

    /** @return list<string> মেনুর পর্দাগুলোর নাম, ক্রমসহ */
    public static function screenSlugs(): array
    {
        return array_keys(self::SCREENS);
    }
}
