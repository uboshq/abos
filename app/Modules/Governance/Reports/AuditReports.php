<?php

declare(strict_types=1);

namespace App\Modules\Governance\Reports;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Models\AuditTrail;
use App\Models\PeriodLock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * নিরীক্ষার খাতা — রিপোর্ট সেন্টার ধাপ ৬ (মালিক, ১ অক্টোবর ২০২৬; সমন্বয়কের ভাগ, ২ অক্টোবর)।
 *
 * *"নিরীক্ষা: কে কোন কাগজ/ভাউচার বদলাল-বাতিল করল, পেছনের তারিখ, মাস বন্ধ খোলা"* — তিনটা প্রশ্ন, তিনটা রিপোর্ট:
 *
 *   বদল ও বাতিল     অডিটের খাতা থেকে — কে, কখন, কোন কাগজ, কী করলেন (বদলানো / বাতিল / মোছা / ফেরানো), কারণ
 *   পেছনের তারিখ    কাগজের তারিখ লেখার দিনের আগে — কত দিন পিছিয়ে, কে লিখলেন, কত টাকার
 *   মাস বন্ধ-খোলা   মাসের তালা ([[PeriodLock]]) বসানো মানে বন্ধ, তোলা মানে আবার খোলা — দুটোই অডিটে ধরা
 *
 * ⓘ "কে" সবসময় যিনি কাজটা করলেন — অডিটের `user_id` / কাগজের `created_by`, কখনো কাগজের পক্ষ নয়।
 * ⓘ কোম্পানির দেয়াল আর শাখার দেয়াল ([[ReportEngine::branchWall()]]) প্রতিটা অংশে; দেখার চাবি `governance.audit.view`।
 */
final class AuditReports
{
    /** পেছনের তারিখ খোঁজা হয় এই কাগজগুলোয় — টেবিল => [ধরনের লেখার চাবি, drill-এর ধরন] */
    private const PAPERS = [
        'sal_orders' => ['sales_order', 'sales_order'],
        'sal_challans' => ['delivery_challan', 'delivery_challan'],
        'sal_invoices' => ['sales_invoice', 'sales_invoice'],
        'sal_returns' => ['sales_return', 'sales_return'],
        'sal_collections' => ['collection', 'collection'],
        'pur_orders' => ['purchase_order', 'purchase_order'],
        'pur_receipts' => ['purchase_receipt', 'purchase_receipt'],
        'pur_bills' => ['purchase_bill', 'purchase_bill'],
        'pur_returns' => ['purchase_return', 'purchase_return'],
        'pur_payments' => ['purchase_payment', 'purchase_payment'],
        // ⓘ ভাউচারের ধরন সারি ধরে — `{type}_voucher` ([[Voucher::drillSourceType()]]-এর টীকা); নিচে আলাদা অংশে
        'vouchers' => ['voucher', 'voucher'],
    ];

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::changes());
        $engine->register(self::backdated());
        $engine->register(self::periods());
    }

    /** বদল ও বাতিল — অডিটের খাতা থেকে */
    public static function changes(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'governance.changes',
            permission: 'governance.audit.view',
            title: 'governance::audit_report.changes_title',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::trail($f)
                // ⓘ জন্ম, নিশ্চিত আর সই বাদে সবই বদল — `repriced`-এর মতো মডিউলের নিজের কাজসহ ([[IsAudited::auditAction()]])
                ->whereNotIn('a.action', [AuditTrail::CREATED, AuditTrail::CONFIRMED, AuditTrail::APPROVED, AuditTrail::REJECTED])
                ->where('a.auditable_type', '<>', PeriodLock::class)
                ->orderByDesc('a.created_at')
                ->orderByDesc('a.id'),
            columns: [
                ['key' => 'happened_at', 'label' => 'governance::audit_report.when', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'user_name', 'label' => 'governance::audit_report.who'],
                ['key' => 'action_label', 'label' => 'governance::audit_report.what', 'width' => '7rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'doc_id',
                ],
                ['key' => 'label', 'label' => 'governance::audit_report.paper'],
                ['key' => 'reason', 'label' => 'governance::audit_report.reason'],
            ],
        );
    }

    /** পেছনের তারিখ — কাগজের তারিখ লেখার দিনের আগে */
    public static function backdated(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'governance.backdated',
            permission: 'governance.audit.view',
            title: 'governance::audit_report.backdated_title',
            filters: ['date_range', 'branch'],
            query: function (array $f) {
                $parts = collect(self::PAPERS)
                    ->filter(fn ($_, string $table) => Schema::hasTable($table))
                    ->map(fn (array $kind, string $table) => self::backdatedPart($f, $table, $kind[0], $kind[1]))
                    ->values();

                $union = $parts->shift();
                $parts->each(fn (Builder $p) => $union->unionAll($p));

                return DB::query()->fromSub($union, 'papers')
                    ->orderByDesc('days_back')
                    ->orderBy('document_no');
            },
            columns: [
                ['key' => 'trx_date', 'label' => 'core.print.date', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'written_on', 'label' => 'governance::audit_report.written_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'days_back', 'label' => 'governance::audit_report.days_back', 'width' => '6rem'],
                ['key' => 'kind', 'label' => 'governance::audit_report.kind', 'width' => '8rem'],
                [
                    'key' => 'document_no',
                    'label' => 'core.table.document',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type_literal',
                    'source_id' => 'doc_id',
                ],
                ['key' => 'user_name', 'label' => 'governance::audit_report.written_by'],
                ['key' => 'amount', 'label' => 'governance::audit_report.amount', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    /** মাস বন্ধ আর আবার খোলা — মাসের তালা বসানো ও তোলা */
    public static function periods(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'governance.periods',
            permission: 'governance.audit.view',
            title: 'governance::audit_report.periods_title',
            filters: ['date_range'],
            // ⓘ মাসের তালা কোম্পানির — `period_locks`-এ শাখার ঘরই নেই, তাই সব শাখা একই তালা দেখে
            branchless: ReportDefinition::NO_BRANCH_DATA,
            query: fn (array $f) => self::trail($f, branch: false)
                ->where('a.auditable_type', PeriodLock::class)
                ->whereIn('a.action', [AuditTrail::CREATED, AuditTrail::DELETED])
                ->orderByDesc('a.created_at')
                ->orderByDesc('a.id'),
            columns: [
                ['key' => 'happened_at', 'label' => 'governance::audit_report.when', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'user_name', 'label' => 'governance::audit_report.who'],
                ['key' => 'lock_label', 'label' => 'governance::audit_report.what', 'width' => '9rem'],
                ['key' => 'label', 'label' => 'governance::audit_report.month'],
                ['key' => 'reason', 'label' => 'governance::audit_report.reason'],
            ],
        );
    }

    /** অডিটের সারি — কে (নাম), কী (কথায়), কোন কাগজ (drill-এর ধরনসহ) */
    private static function trail(array $f, bool $branch = true): Builder
    {
        $q = DB::getPdo();

        // ⓘ খাতায় যত রকম কাজ আছে সবগুলোর শব্দ — তালিকার বাইরের কাজও কাঁচা চাবি হয়ে ছাপে না ([[AuditTrail::actionInWords()]])
        $actions = collect(AuditTrail::ACTIONS)
            ->merge(DB::table('audit_trails')->where('company_id', $f['company_id'])->distinct()->pluck('action'))
            ->filter()->unique()
            ->map(fn (string $a) => 'WHEN '.$q->quote($a).' THEN '.$q->quote(AuditTrail::actionInWords($a)))
            ->implode(' ');

        // ⓘ অডিট রাখে ক্লাসের নাম, drill চায় ধরনের নাম — মডিউলের নিজের ঘোষণা থেকে উল্টো করে ([[DrillResolver::map()]])
        $types = collect(app(DrillResolver::class)->map())
            ->map(fn (string $class, string $type) => 'WHEN '.$q->quote($class).' THEN '.$q->quote($type))
            ->implode(' ');

        $lock = 'CASE a.action WHEN '.$q->quote(AuditTrail::CREATED).' THEN '.$q->quote((string) __('governance::audit_report.month_closed'))
            .' ELSE '.$q->quote((string) __('governance::audit_report.month_reopened')).' END';

        return DB::table('audit_trails as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.company_id', $f['company_id'])
            ->when($branch, fn (Builder $b) => $b->tap(ReportEngine::branchWall($f, 'a.branch_id')))
            ->whereBetween('a.created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
            ->selectRaw('DATE(a.created_at) as happened_at')
            ->selectRaw("COALESCE(u.name, '—') as user_name")
            ->selectRaw("CASE a.action {$actions} ELSE a.action END as action_label")
            ->selectRaw("{$lock} as lock_label")
            ->selectRaw('a.document_no')
            ->selectRaw($types === '' ? 'NULL as source_type_literal' : "CASE a.auditable_type {$types} END as source_type_literal")
            ->selectRaw('a.auditable_id as doc_id')
            ->selectRaw('a.label')
            ->selectRaw('a.reason');
    }

    /** একটা কাগজের টেবিল — তারিখ লেখার দিনের আগে হলে */
    private static function backdatedPart(array $f, string $table, string $kind, string $drill): Builder
    {
        $q = DB::getPdo();
        $total = Schema::hasColumn($table, 'total') ? 'p.total' : 'p.amount';

        if ($table === 'vouchers') {
            $labels = collect(['receipt', 'payment', 'expense', 'journal', 'contra'])
                ->map(fn (string $t) => "WHEN '{$t}' THEN ".$q->quote((string) __('core.source.'.$t.'_voucher')))
                ->implode(' ');
            $kindSql = "CASE p.type {$labels} ELSE p.type END";
            $drillSql = "CONCAT(p.type, '_voucher')";
        } else {
            $kindSql = $q->quote((string) __('core.source.'.$kind));
            $drillSql = $q->quote($drill);
        }

        return DB::table("{$table} as p")
            ->leftJoin('users as u', 'u.id', '=', 'p.created_by')
            ->where('p.company_id', $f['company_id'])
            ->tap(ReportEngine::branchWall($f, 'p.branch_id'))
            ->whereNull('p.deleted_at')
            ->whereBetween('p.created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
            ->whereRaw('p.trx_date < DATE(p.created_at)')
            ->selectRaw('p.trx_date')
            ->selectRaw('DATE(p.created_at) as written_on')
            ->selectRaw('DATEDIFF(DATE(p.created_at), p.trx_date) as days_back')
            ->selectRaw("{$kindSql} as kind")
            ->selectRaw('p.document_no')
            ->selectRaw("{$drillSql} as source_type_literal")
            ->selectRaw('p.id as doc_id')
            ->selectRaw("COALESCE(u.name, '—') as user_name")
            ->selectRaw("{$total} as amount");
    }
}
