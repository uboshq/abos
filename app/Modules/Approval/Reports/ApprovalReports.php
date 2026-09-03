<?php

declare(strict_types=1);

namespace App\Modules\Approval\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Models\Approval;
use App\Modules\Approval\Services\ApprovalFlowService;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * অনুমোদনের চারটা রিপোর্ট — চেকলিস্ট §২.৮।
 *
 * ── কেন তিনটা সারি-ভিত্তিক, একটা গণনা ───────────────────────────────
 * প্রথম তিনটার প্রতিটা সারি **একটা অনুরোধ**, তাই সারিটাই ক্লিকযোগ্য —
 * নিয়ম ১ ("প্রতিটা সংখ্যা তার উৎসে যায়") ওখানে পুরোপুরি খাটে।
 * চতুর্থটা মানুষ ধরে গোনা ("রহিম ১২টা অনুমোদন, ৩টা ফেরত"), আর গোষ্ঠীবদ্ধ
 * সারি থেকে ড্রিল করার ব্যবস্থা ইঞ্জিনে নেই। ⓘ সেটা লুকানো হয়নি —
 * তুলে ধরা হয়েছে, আর নতুন কলাম-ধরন বানানো এই কাজের সীমার বাইরে।
 *
 * ── ⚠️ যা এখানে নেই, আর কেন ─────────────────────────────────────────
 * §২.৮ শাখাভিত্তিক ও SLA রিপোর্টও চায়। **`approvals` টেবিলে
 * `branch_id` কলামই নেই**, আর SLA বলে কোনো ধারণাই এখনো নেই (Phase 2)।
 * ভিত্তি ছাড়া রিপোর্ট বানানো মানে একটা পর্দা যা সবসময় একই উত্তর দেয় —
 * তার চেয়ে না থাকা ভালো।
 *
 * ── কাজের নামটা SQL-এ কোথা থেকে আসে ─────────────────────────────────
 * সারিতে `module` ও `action` কাঁচা নামে বসে (`purchase` · `order`), আর
 * মানুষের ভাষার নামটা জানে কেবল মডিউলের নিজের ঘোষণা। তাই নামগুলো
 * `ApprovalFlowService::labels()` থেকে নিয়ে একটা CASE বানানো হয় —
 * **দ্বিতীয় কোনো তালিকা কোথাও লেখা হয় না**, নাহলে একদিন ছকের পর্দায়
 * এক নাম আর রিপোর্টে আরেক নাম দেখাত।
 */
final class ApprovalReports
{
    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::pending());
        $engine->register(self::approved());
        $engine->register(self::rejected());
        $engine->register(self::byUser());
    }

    /**
     * এখনো কারো সইয়ের অপেক্ষায়।
     *
     * সবচেয়ে পুরনোটা আগে — যেটা সবচেয়ে বেশিক্ষণ ঝুলে আছে সেটাই কাউকে
     * সবচেয়ে বেশিক্ষণ আটকে রেখেছে। ⓘ ইনবক্সও এই ক্রমেই দেখায়।
     */
    public static function pending(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'approval.pending',
            title: 'approval::menu.report_pending',
            filters: ['date_range'],
            query: fn (array $f) => self::base($f)
                ->where('approvals.status', Approval::PENDING)
                ->whereBetween('approvals.requested_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
                ->orderBy('approvals.requested_at')
                ->select([
                    'approvals.id as approval_id',
                    'approvals.requested_at',
                    'approvals.amount',
                    'approvals.current_level',
                    DB::raw("'".Approval::drillSourceType()."' as approval_source"),
                    self::whatLabel(),
                    DB::raw('users.name as requester'),
                    /*
                     * কত দিন ধরে ঝুলে আছে — এটাই এই রিপোর্টের আসল কথা।
                     *
                     * তারিখটা একা যথেষ্ট নয়: "১২ আগস্ট" দেখে কেউ মাথায়
                     * বিয়োগ করেন না, আর ঠিক ওই বিয়োগটাই বলে দেয় কোনটা
                     * ভুলে যাওয়া হয়েছে।
                     */
                    DB::raw('TIMESTAMPDIFF(DAY, approvals.requested_at, NOW()) as waiting_days'),
                ]),
            columns: [
                ['key' => 'requested_at', 'label' => 'approval::field.requested_at',
                    'type' => ReportColumn::DATE, 'width' => '9rem'],
                ['key' => 'what', 'label' => 'approval::field.action',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'approval_source', 'source_id' => 'approval_id'],
                ['key' => 'requester', 'label' => 'approval::field.requested_by', 'width' => '11rem'],
                ['key' => 'current_level', 'label' => 'approval::field.level',
                    'type' => ReportColumn::QUANTITY, 'width' => '6rem'],
                ['key' => 'waiting_days', 'label' => 'approval::field.waiting_days',
                    'type' => ReportColumn::QUANTITY, 'width' => '8rem'],
                ['key' => 'amount', 'label' => 'approval::field.amount', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /**
     * যেগুলোতে সই হয়ে গেছে — আর কত দিনে হয়েছে।
     *
     * ⓘ সময়টা এখানে গুরুত্বপূর্ণ: অনুমোদন দ্রুত হচ্ছে না ধীরে, সেটা
     * ছকটা কাজে লাগছে কি না তার একমাত্র মাপ।
     */
    public static function approved(): ReportDefinition
    {
        return self::decided('approval.approved', 'approval::menu.report_approved', Approval::APPROVED);
    }

    /**
     * যেগুলো ফেরত দেওয়া হয়েছে — কারণসহ।
     *
     * ⚠️ কারণটা কলামে থাকা বাধ্যতামূলক। কারণ ছাড়া তালিকাটা কেবল বলে
     * "না হয়েছে", আর তখন প্রতিটা সারির জন্য একটা করে ফোন কল লাগে।
     */
    public static function rejected(): ReportDefinition
    {
        return self::decided('approval.rejected', 'approval::menu.report_rejected', Approval::REJECTED);
    }

    /** দুইটা রিপোর্ট এক ছাঁচে — কেবল অবস্থাটা আলাদা। */
    private static function decided(string $key, string $title, string $status): ReportDefinition
    {
        $refused = $status === Approval::REJECTED;

        $columns = [
            ['key' => 'decided_at', 'label' => 'approval::field.decided_at',
                'type' => ReportColumn::DATE, 'width' => '9rem'],
            ['key' => 'what', 'label' => 'approval::field.action',
                'type' => ReportColumn::DOCUMENT,
                'source_type' => 'approval_source', 'source_id' => 'approval_id'],
            ['key' => 'requester', 'label' => 'approval::field.requested_by', 'width' => '10rem'],
            ['key' => 'deciders', 'label' => 'approval::field.approver', 'width' => '11rem'],
            ['key' => 'took_days', 'label' => 'approval::field.took_days',
                'type' => ReportColumn::QUANTITY, 'width' => '7rem'],
        ];

        if ($refused) {
            $columns[] = ['key' => 'reason', 'label' => 'approval::field.remarks'];
        }

        $columns[] = ['key' => 'amount', 'label' => 'approval::field.amount', 'type' => ReportColumn::MONEY];

        return new ReportDefinition(
            key: $key,
            title: $title,
            filters: ['date_range'],
            query: fn (array $f) => self::base($f)
                ->where('approvals.status', $status)
                // সিদ্ধান্তের তারিখ ধরে, অনুরোধের নয় — প্রশ্নটা "এই মাসে
                // কী কী পাশ হলো", "এই মাসে কী কী চাওয়া হলো" নয়
                ->whereBetween('approvals.decided_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
                ->orderByDesc('approvals.decided_at')
                ->select(array_filter([
                    'approvals.id as approval_id',
                    'approvals.decided_at',
                    'approvals.amount',
                    DB::raw("'".Approval::drillSourceType()."' as approval_source"),
                    self::whatLabel(),
                    DB::raw('users.name as requester'),
                    /*
                     * কে কে সই দিয়েছেন — সবাই, একটা ঘরে।
                     *
                     * বহু-স্তরের ছকে একজনের নাম দেখানো মিথ্যা বলত:
                     * পাঠক ভাবতেন একজনই পাশ করেছেন, অথচ তিনজন লেগেছে।
                     */
                    DB::raw(
                        '(SELECT GROUP_CONCAT(DISTINCT du.name ORDER BY d.level SEPARATOR ", ") '
                        .'FROM approval_decisions d JOIN users du ON du.id = d.user_id '
                        .'WHERE d.approval_id = approvals.id) as deciders'
                    ),
                    DB::raw('TIMESTAMPDIFF(DAY, approvals.requested_at, approvals.decided_at) as took_days'),
                    $refused ? DB::raw(
                        '(SELECT d.remarks FROM approval_decisions d '
                        .'WHERE d.approval_id = approvals.id AND d.decision = "rejected" '
                        .'ORDER BY d.id DESC LIMIT 1) as reason'
                    ) : null,
                ])),
            columns: $columns,
        );
    }

    /**
     * কে কয়টা সিদ্ধান্ত দিয়েছেন।
     *
     * ── কেন সিদ্ধান্তের টেবিল ধরে, অনুরোধের নয় ─────────────────────
     * একটা অনুরোধে তিনজন সই দিতে পারেন। অনুরোধ ধরে গুনলে তিনজনের
     * কাজ একজনের নামে বসত, আর বহু-স্তরের ছকে সংখ্যাটা কার্যত অর্থহীন
     * হয়ে যেত।
     *
     * ⓘ **এই রিপোর্টের সারি ক্লিকযোগ্য নয়** — সারিটা একটা কাগজ নয়,
     * একটা গণনা। গোষ্ঠীবদ্ধ সারি থেকে ড্রিল করার ব্যবস্থা ইঞ্জিনে নেই,
     * আর সেটা এখানে নীরবে লুকানোর চেয়ে লিখে রাখা ভালো।
     */
    public static function byUser(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'approval.by_user',
            title: 'approval::menu.report_by_user',
            filters: ['date_range'],
            groupBy: 'user_id',
            query: fn (array $f) => DB::table('approval_decisions')
                ->join('approvals', 'approvals.id', '=', 'approval_decisions.approval_id')
                ->join('users', 'users.id', '=', 'approval_decisions.user_id')
                ->where('approvals.company_id', $f['company_id'])
                ->whereBetween('approval_decisions.decided_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
                ->groupBy('approval_decisions.user_id', 'users.name')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->select([
                    'approval_decisions.user_id',
                    DB::raw('users.name as decider'),
                    DB::raw('SUM(approval_decisions.decision = "approved") as approved_count'),
                    DB::raw('SUM(approval_decisions.decision = "rejected") as rejected_count'),
                    DB::raw('COUNT(*) as total_count'),
                    /*
                     * গড় সময় ঘণ্টায় গুনে দিনে ভাঙা হয়।
                     *
                     * সরাসরি দিনে গুনলে একই দিনের সব সিদ্ধান্ত শূন্য হত,
                     * আর গড়টা "০ দিন" — যা দ্রুততা নয়, তথ্যের অভাব।
                     */
                    DB::raw(
                        'ROUND(AVG(TIMESTAMPDIFF(HOUR, approvals.requested_at, '
                        .'approval_decisions.decided_at)) / 24, 1) as avg_days'
                    ),
                ]),
            columns: [
                ['key' => 'decider', 'label' => 'approval::field.approver'],
                ['key' => 'approved_count', 'label' => 'approval::field.approved_count',
                    'type' => ReportColumn::QUANTITY, 'width' => '8rem'],
                ['key' => 'rejected_count', 'label' => 'approval::field.rejected_count',
                    'type' => ReportColumn::QUANTITY, 'width' => '8rem'],
                ['key' => 'total_count', 'label' => 'approval::field.total_count',
                    'type' => ReportColumn::QUANTITY, 'width' => '7rem'],
                ['key' => 'avg_days', 'label' => 'approval::field.avg_days',
                    'type' => ReportColumn::QUANTITY, 'width' => '8rem'],
            ],
        );
    }

    /** তিনটা সারি-ভিত্তিক রিপোর্টের অভিন্ন শুরুটা। */
    private static function base(array $filters): Builder
    {
        return DB::table('approvals')
            ->join('users', 'users.id', '=', 'approvals.requested_by')
            ->where('approvals.company_id', $filters['company_id']);
    }

    /**
     * "purchase · order" নয়, "ক্রয়াদেশ নিশ্চিত করা"।
     *
     * নামগুলো মডিউলের নিজের ঘোষণা থেকে আসে, তাই এখানে দ্বিতীয় কোনো
     * তালিকা নেই। ⓘ ঘোষণা না থাকলে কাঁচা নামটাই দেখানো হয় — লুকিয়ে
     * ফেলার চেয়ে ভালো, কারণ তখন অন্তত বোঝা যায় কোন মডিউল ঘোষণা করতে
     * ভুলে গেছে।
     */
    private static function whatLabel(): Expression
    {
        $labels = app(ApprovalFlowService::class)->labels();

        if ($labels === []) {
            return DB::raw("CONCAT(approvals.module, ' · ', approvals.action) as what");
        }

        $case = 'CASE';
        $bindings = [];

        foreach ($labels as $key => $label) {
            [$module, $action] = explode('.', $key, 2);
            $case .= ' WHEN approvals.module = ? AND approvals.action = ? THEN ?';
            $bindings[] = $module;
            $bindings[] = $action;
            $bindings[] = $label;
        }

        $case .= " ELSE CONCAT(approvals.module, ' · ', approvals.action) END as what";

        return DB::raw(
            // বাঁধনগুলো এখানেই বসানো হয়, কারণ `select()` আলাদা করে
            // binding নেয় না — আর অনুবাদের লেখায় উদ্ধৃতি থাকতেই পারে।
            vsprintf(str_replace('?', '%s', $case), array_map(
                static fn (string $value): string => DB::connection()->getPdo()->quote($value),
                $bindings,
            ))
        );
    }
}
