<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\NoticeStatus;
use Illuminate\Support\Facades\DB;

/**
 * নোটিশের তিনটা রিপোর্ট — স্পেকের ধারা ২৯।
 *
 * ── ⚠️ কেন তিনটা, এগারোটা নয় ────────────────────────────────────────
 * ⓘ স্পেকে এগারোটা রিপোর্টের নাম লেখা, কিন্তু তার আটটা একই তালিকার
 * **ছাঁকনি** — খসড়া, নির্ধারিত, মেয়াদ শেষ, অনুমোদনের অপেক্ষা। ⛔ প্রতিটার
 * জন্য আলাদা রিপোর্ট লিখলে আটটা প্রায়-একই ফাইল হত, আর একদিন একটায়
 * কলাম যোগ হত অন্যটায় নয়।
 *
 * ⭐ তাই সত্যিই আলাদা প্রশ্ন তিনটা: **কী বেরিয়েছে** (রেজিস্টার),
 * **কে মানেনি** (সই), আর **কোনটা কেউ পড়ছে না** (পৌঁছানো)।
 *
 * ── ⓘ শাখাভিত্তিক রিপোর্ট এখানে নেই, আর কারণটা লেখা থাকা দরকার ──────
 * ⚠️ নোটিশের লক্ষ্য শাখা ধরে হয় ([[NoticeAudience]]), কিন্তু **কে কোন
 * শাখায় পড়েছেন** সেটা `notice_reads`-এ লেখা নেই — ওখানে কেবল কে আর
 * কখন। ⛔ শাখা ধরে ভাগ করতে গেলে পাঠকের **আজকের** শাখা ধরতে হত, আর
 * সেটা তিনি বদলালে গত মাসের রিপোর্টও বদলে যেত।
 *
 * ⓘ সংখ্যাটা ভুল হওয়ার চেয়ে না থাকা ভালো — [[ApprovalReports]]-এ একই
 * সিদ্ধান্ত, একই কারণে।
 */
final class NoticeReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::register());
        $engine->register(self::signatures());
    }

    /**
     * ⭐ নোটিশ রেজিস্টার — কোন কাগজ কবে বেরিয়েছিল।
     *
     * ⓘ প্রতিটা সারি একটা নোটিশ, তাই সারিটাই ক্লিকযোগ্য।
     */
    public static function register(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'system_admin.notice_register',
            title: 'core.notice.report_register',
            filters: ['date_range'],
            query: fn (array $f) => DB::table('notices')
                ->leftJoin('users', 'users.id', '=', 'notices.created_by')
                ->where('notices.company_id', \App\Core\Support\CompanyContext::id())

                /*
                 * ⚠️ তারিখটা `published_at` ধরে, `created_at` ধরে নয়।
                 *
                 * ⓘ রেজিস্টারের প্রশ্ন *"কী বেরিয়েছিল"*, *"কী লেখা
                 * হয়েছিল"* নয়। ⛔ খসড়া যেগুলো কোনোদিন বেরোয়নি সেগুলো
                 * রেজিস্টারে থাকলে সংখ্যাটা মিথ্যা বলত।
                 */
                ->whereNotNull('notices.published_at')
                ->whereBetween('notices.published_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
                ->orderByDesc('notices.published_at')
                ->select([
                    'notices.id as notice_id',
                    'notices.document_no',
                    'notices.title',
                    'notices.priority',
                    'notices.status',
                    'notices.published_at',
                    'notices.expires_at',
                    DB::raw('users.name as author'),
                ]),
            columns: [
                ['key' => 'document_no', 'label' => 'core.table.code', 'width' => '10rem'],
                ['key' => 'title', 'label' => 'core.table.name'],
                ['key' => 'priority', 'label' => 'core.notice.priority_label', 'width' => '8rem'],
                ['key' => 'status', 'label' => 'core.table.status', 'width' => '8rem'],
                ['key' => 'author', 'label' => 'core.notice.author_label', 'width' => '10rem'],
                ['key' => 'published_at', 'label' => 'core.notice.published_at_label',
                    'type' => ReportColumn::DATE, 'width' => '9rem'],
                ['key' => 'expires_at', 'label' => 'core.notice.expires_at_label',
                    'type' => ReportColumn::DATE, 'width' => '9rem'],
            ],
        );
    }

    /**
     * ⛔ সইয়ের রিপোর্ট — কতজন পড়েছেন, কতজন মেনেছেন।
     *
     * ── ⚠️ কেন সংখ্যাটা "যতজন ছুঁয়েছেন" ধরে ────────────────────────
     * ⓘ *"কতজনের মানার কথা"* বের করতে হলে প্রতিটা ব্যবহারকারীর চাবি
     * বানাতে হয়, আর সেটা হাজার জনের কোম্পানিতে হাজারটা প্রশ্ন।
     *
     * ⭐ তাই হরটা **যতজন ছুঁয়েছেন** — পড়েছেন বা সই দিয়েছেন। ⓘ সংখ্যাটা
     * রক্ষণশীল, আর কখনো ১০০%-এর বেশি দেখায় না। ⛔ উল্টোটা, অর্থাৎ ভুয়া
     * *"সবাই মেনেছেন"*, বিপজ্জনক দিকে ভুল।
     */
    public static function signatures(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'system_admin.notice_signatures',
            title: 'core.notice.report_signatures',
            filters: ['date_range'],
            query: fn (array $f) => DB::table('notices')
                ->where('notices.company_id', \App\Core\Support\CompanyContext::id())
                ->where('notices.ack_required', true)
                ->where('notices.status', NoticeStatus::PUBLISHED->value)
                ->whereBetween('notices.published_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
                ->orderByDesc('notices.published_at')
                ->select([
                    'notices.id as notice_id',
                    'notices.document_no',
                    'notices.title',
                    'notices.ack_deadline',
                    DB::raw('(SELECT COUNT(*) FROM notice_reads WHERE notice_reads.notice_id = notices.id) as read_count'),
                    DB::raw('(SELECT COUNT(*) FROM notice_acknowledgements WHERE notice_acknowledgements.notice_id = notices.id) as signed_count'),
                ]),
            columns: [
                ['key' => 'document_no', 'label' => 'core.table.code', 'width' => '10rem'],
                ['key' => 'title', 'label' => 'core.table.name'],
                ['key' => 'read_count', 'label' => 'core.notice.read_count',
                    'type' => ReportColumn::QUANTITY, 'width' => '7rem'],
                ['key' => 'signed_count', 'label' => 'core.notice.signed_count',
                    'type' => ReportColumn::QUANTITY, 'width' => '7rem'],
                ['key' => 'ack_deadline', 'label' => 'core.notice.deadline_label',
                    'type' => ReportColumn::DATE, 'width' => '9rem'],
            ],
        );
    }
}
