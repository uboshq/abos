<?php

declare(strict_types=1);

namespace App\Modules\Documents\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentWorkflow;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ডকুমেন্টের রিপোর্ট — ABOS-এর রিপোর্ট ইঞ্জিনে (পরিকল্পনা §১৭; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ── ⛔ প্রতিটা রিপোর্ট চার দেয়ালের ভিতরে ─────────────────────────────────
 * কোম্পানি (`company_id`), শাখা ([[ReportEngine::branchWall()]]), আর ⭐ যিনি দেখছেন তিনি যে কাগজ দেখতে পান
 * কেবল সেগুলো ([[Document::scopeVisibleTo()]] — গোপনীয়তা, বিভাগ, নিজের কাগজ, কাগজ-ধরে অধিকার)। ⚠️ গোনার
 * রিপোর্টও — নাহলে "অতি গোপন: ৩টা" লেখাটাই একটা খবর হয়ে যেত।
 *
 * ⓘ ONLY_FULL_GROUP_BY: প্রতিটা দলের রিপোর্ট যা বাছে তা-ই দলে রাখে (নামের দুই ভাষাসহ)।
 */
final class DocumentReports
{
    /** ⓘ দরজা দুইটা চাবি চায় — মডিউলের দলে `documents.view`, কন্ট্রোলারে `documents.report` */
    public const PERMISSION = ['documents.view', 'documents.report'];

    public static function registerAll(ReportEngine $engine): void
    {
        foreach ([
            self::register(), self::summary(), self::byType(), self::byDepartment(), self::byBranch(), self::byOwner(),
            self::activity(), self::accessHistory(), self::approvals(), self::signatures(), self::expiry(),
            self::archive(), self::versions(), self::storage(),
        ] as $definition) {
            $engine->register($definition);
        }
    }

    /** দেখা যায় এমন কাগজের গোড়া — চার দেয়াল */
    private static function documents(array $f): Builder
    {
        $user = auth()->user();

        $query = Document::query()->where('dms_documents.company_id', $f['company_id']);

        if ($user instanceof User) {
            $query->visibleTo($user);
        }

        return $query->toBase()->tap(ReportEngine::branchWall($f, 'dms_documents.branch_id'));
    }

    /** তারিখের সীমা — কাগজ যেদিন তোলা হলো */
    private static function created(Builder $q, array $f): Builder
    {
        return $q->whereBetween('dms_documents.created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59']);
    }

    /** কোড → পর্দার ভাষার নাম, SQL-এর ভিতরে — যাতে দল আর বাছাই একই ঘর ধরে */
    private static function words(string $column, string $group, array $codes): string
    {
        $case = 'CASE '.$column;

        foreach ($codes as $code) {
            $case .= ' WHEN '.DB::getPdo()->quote($code).' THEN '.DB::getPdo()->quote((string) __('documents::catalog.'.$group.'.'.$code));
        }

        return $case.' ELSE '.$column.' END';
    }

    public static function register(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'documents.register',
            permission: self::PERMISSION,
            title: 'documents::report.register',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::created(self::documents($f), $f)
                ->leftJoin('users as o', 'o.id', '=', 'dms_documents.owner_id')
                ->leftJoin('dms_document_versions as v', 'v.id', '=', 'dms_documents.current_version_id')
                ->select(['dms_documents.document_no', 'dms_documents.name', 'dms_documents.doc_type', 'dms_documents.folder',
                    'dms_documents.document_date', 'dms_documents.expiry_date', 'o.name as owner_name'])
                ->selectRaw("CONCAT('v', v.major, '.', v.minor) as version")
                ->selectRaw(self::words('dms_documents.status', 'status', DocumentCatalog::STATUSES).' as status_label')
                ->selectRaw(self::words('dms_documents.confidentiality', 'level', DocumentCatalog::LEVELS).' as level_label')
                ->orderBy('dms_documents.document_no'),
            columns: [
                ['key' => 'document_no', 'label' => 'documents::field.document_no', 'width' => '8rem'],
                ['key' => 'name', 'label' => 'documents::field.name'],
                ['key' => 'doc_type', 'label' => 'documents::field.doc_type', 'width' => '8rem'],
                ['key' => 'folder', 'label' => 'documents::field.folder', 'width' => '8rem'],
                ['key' => 'owner_name', 'label' => 'documents::field.owner'],
                ['key' => 'version', 'label' => 'documents::field.version', 'width' => '5rem'],
                ['key' => 'status_label', 'label' => 'documents::field.status', 'width' => '8rem'],
                ['key' => 'level_label', 'label' => 'documents::field.confidentiality', 'width' => '8rem'],
                ['key' => 'document_date', 'label' => 'documents::field.document_date', 'type' => ReportColumn::DATE],
                ['key' => 'expiry_date', 'label' => 'documents::field.expiry_date', 'type' => ReportColumn::DATE],
            ],
        );
    }

    /** সারসংক্ষেপ — অবস্থা ধরে গোনা */
    public static function summary(): ReportDefinition
    {
        $label = self::words('dms_documents.status', 'status', DocumentCatalog::STATUSES);

        return new ReportDefinition(
            key: 'documents.summary',
            permission: self::PERMISSION,
            title: 'documents::report.summary',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::created(self::documents($f), $f)
                ->selectRaw($label.' as status_label')
                ->selectRaw('COUNT(*) as documents')
                ->groupBy('dms_documents.status')
                ->orderByRaw('COUNT(*) desc'),
            columns: [
                ['key' => 'status_label', 'label' => 'documents::field.status'],
                ['key' => 'documents', 'label' => 'documents::report.count', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }

    public static function byType(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'documents.by_type',
            permission: self::PERMISSION,
            title: 'documents::report.by_type',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::counted(self::created(self::documents($f), $f), 'dms_documents.doc_type', 'doc_type'),
            columns: self::countColumns('doc_type', 'documents::field.doc_type'),
        );
    }

    public static function byDepartment(): ReportDefinition
    {
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(d.name_bn, ''), d.name_en)" : 'd.name_en';

        return new ReportDefinition(
            key: 'documents.by_department',
            permission: self::PERMISSION,
            title: 'documents::report.by_department',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::counted(
                self::created(self::documents($f), $f)->leftJoin('mdm_departments as d', 'd.id', '=', 'dms_documents.department_id'),
                ['d.id', 'd.name_en', 'd.name_bn'],
                "COALESCE({$name}, '—')",
            ),
            columns: self::countColumns('label', 'documents::field.department'),
        );
    }

    public static function byBranch(): ReportDefinition
    {
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF(b.name_bn, ''), b.name_en)" : 'b.name_en';

        return new ReportDefinition(
            key: 'documents.by_branch',
            permission: self::PERMISSION,
            title: 'documents::report.by_branch',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::counted(
                self::created(self::documents($f), $f)->leftJoin('branches as b', 'b.id', '=', 'dms_documents.branch_id'),
                ['b.id', 'b.name_en', 'b.name_bn'],
                "COALESCE({$name}, ".DB::getPdo()->quote((string) __('documents::message.company_wide')).')',
            ),
            columns: self::countColumns('label', 'documents::field.branch'),
        );
    }

    /** কর্মী ধরে — কাগজের মালিক */
    public static function byOwner(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'documents.by_owner',
            permission: self::PERMISSION,
            title: 'documents::report.by_owner',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::counted(
                self::created(self::documents($f), $f)->leftJoin('users as o', 'o.id', '=', 'dms_documents.owner_id'),
                ['o.id', 'o.name'],
                "COALESCE(o.name, '—')",
            ),
            columns: self::countColumns('label', 'documents::field.owner'),
        );
    }

    /**
     * কাজের হিসাব — তোলা, দেখা, নামানো, ছাপা, শেয়ার, মোছা, ফেরানো… অডিটের খাতা থেকে, কাজ ধরে গোনা।
     */
    public static function activity(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'documents.activity',
            permission: self::PERMISSION,
            title: 'documents::report.activity',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::audit($f)
                ->select('a.action')
                ->selectRaw('COUNT(*) as events')
                ->selectRaw('COUNT(DISTINCT a.auditable_id) as documents')
                ->selectRaw('COUNT(DISTINCT a.user_id) as people')
                ->groupBy('a.action')
                ->orderByRaw('COUNT(*) desc'),
            columns: [
                ['key' => 'action', 'label' => 'documents::field.what'],
                ['key' => 'events', 'label' => 'documents::report.events', 'type' => ReportColumn::QUANTITY],
                ['key' => 'documents', 'label' => 'documents::report.count', 'type' => ReportColumn::QUANTITY, 'total' => false],
                ['key' => 'people', 'label' => 'documents::report.people', 'type' => ReportColumn::QUANTITY, 'total' => false],
            ],
        );
    }

    /** কে কবে কোন কাগজ নামালেন, ছাপলেন, শেয়ার করলেন, মুছলেন, ফেরালেন */
    public static function accessHistory(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'documents.access_history',
            permission: self::PERMISSION,
            title: 'documents::report.access_history',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::audit($f)
                ->whereIn('a.action', ['document_downloaded', 'document_printed', 'document_shared', 'deleted',
                    'document_restored', 'document_purged', 'document_viewed', 'document_previewed'])
                ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->select(['a.created_at', 'a.document_no', 'a.label', 'a.action', 'u.name as user_name', 'a.ip_address'])
                ->orderByDesc('a.id'),
            columns: [
                ['key' => 'created_at', 'label' => 'documents::field.when', 'type' => ReportColumn::DATE],
                ['key' => 'document_no', 'label' => 'documents::field.document_no', 'width' => '8rem'],
                ['key' => 'label', 'label' => 'documents::field.name'],
                ['key' => 'action', 'label' => 'documents::field.what'],
                ['key' => 'user_name', 'label' => 'documents::field.who'],
                ['key' => 'ip_address', 'label' => 'documents::field.ip'],
            ],
        );
    }

    /** অনুমোদন আর বাতিল — অনুমোদন ইঞ্জিনের অনুরোধ থেকে */
    public static function approvals(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'documents.approvals',
            permission: self::PERMISSION,
            title: 'documents::report.approvals',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::documents($f)
                ->join('approvals as ap', function ($j) {
                    $j->on('ap.approvable_id', '=', 'dms_documents.id')
                        ->where('ap.approvable_type', Document::class)
                        ->where('ap.module', DocumentWorkflow::MODULE);
                })
                ->whereBetween('ap.requested_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
                ->leftJoin('users as r', 'r.id', '=', 'ap.requested_by')
                ->select(['dms_documents.document_no', 'dms_documents.name', 'ap.action', 'ap.status', 'r.name as requester',
                    'ap.requested_at', 'ap.decided_at'])
                ->orderByDesc('ap.requested_at'),
            columns: [
                ['key' => 'document_no', 'label' => 'documents::field.document_no', 'width' => '8rem'],
                ['key' => 'name', 'label' => 'documents::field.name'],
                ['key' => 'action', 'label' => 'documents::report.kind', 'width' => '7rem'],
                ['key' => 'status', 'label' => 'documents::field.status', 'width' => '7rem'],
                ['key' => 'requester', 'label' => 'documents::field.requester'],
                ['key' => 'requested_at', 'label' => 'documents::report.asked', 'type' => ReportColumn::DATE],
                ['key' => 'decided_at', 'label' => 'documents::report.decided', 'type' => ReportColumn::DATE],
            ],
        );
    }

    public static function signatures(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'documents.signatures',
            permission: self::PERMISSION,
            title: 'documents::report.signatures',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::documents($f)
                ->join('dms_signatures as s', 's.document_id', '=', 'dms_documents.id')
                ->join('dms_document_versions as v', 'v.id', '=', 's.version_id')
                ->leftJoin('users as u', 'u.id', '=', 's.user_id')
                ->whereBetween('s.signed_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
                ->select(['dms_documents.document_no', 'dms_documents.name', 'u.name as signer', 's.level', 's.signed_at', 's.file_hash'])
                ->selectRaw("CONCAT('v', v.major, '.', v.minor) as version")
                ->orderByDesc('s.signed_at'),
            columns: [
                ['key' => 'document_no', 'label' => 'documents::field.document_no', 'width' => '8rem'],
                ['key' => 'name', 'label' => 'documents::field.name'],
                ['key' => 'signer', 'label' => 'documents::field.signer'],
                ['key' => 'level', 'label' => 'documents::field.level', 'width' => '4rem'],
                ['key' => 'version', 'label' => 'documents::field.version', 'width' => '5rem'],
                ['key' => 'signed_at', 'label' => 'documents::field.date', 'type' => ReportColumn::DATE],
                ['key' => 'file_hash', 'label' => 'documents::field.file_hash'],
            ],
        );
    }

    /** মেয়াদ ও নবায়ন — তারিখের সীমায় যাদের মেয়াদ শেষ */
    public static function expiry(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'documents.expiry',
            permission: self::PERMISSION,
            title: 'documents::report.expiry',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::documents($f)
                ->whereNull('dms_documents.deleted_at')
                ->whereBetween('dms_documents.expiry_date', [$f['from'], $f['to']])
                ->leftJoin('users as o', 'o.id', '=', 'dms_documents.owner_id')
                ->select(['dms_documents.document_no', 'dms_documents.name', 'dms_documents.expiry_date', 'o.name as owner_name'])
                ->selectRaw(self::words('dms_documents.status', 'status', DocumentCatalog::STATUSES).' as status_label')
                ->selectRaw('(SELECT COUNT(*) FROM dms_expiry_notices n WHERE n.document_id = dms_documents.id AND n.company_id = dms_documents.company_id) as reminders')
                ->orderBy('dms_documents.expiry_date'),
            columns: [
                ['key' => 'document_no', 'label' => 'documents::field.document_no', 'width' => '8rem'],
                ['key' => 'name', 'label' => 'documents::field.name'],
                ['key' => 'expiry_date', 'label' => 'documents::field.expiry_date', 'type' => ReportColumn::DATE],
                ['key' => 'status_label', 'label' => 'documents::field.status', 'width' => '8rem'],
                ['key' => 'owner_name', 'label' => 'documents::field.owner'],
                ['key' => 'reminders', 'label' => 'documents::report.reminders', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }

    public static function archive(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'documents.archive',
            permission: self::PERMISSION,
            title: 'documents::report.archive',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::documents($f)
                ->whereNotNull('dms_documents.archived_at')
                ->whereBetween('dms_documents.archived_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
                ->leftJoin('users as a', 'a.id', '=', 'dms_documents.archived_by')
                ->select(['dms_documents.document_no', 'dms_documents.name', 'dms_documents.archived_at', 'a.name as archived_by_name'])
                ->orderByDesc('dms_documents.archived_at'),
            columns: [
                ['key' => 'document_no', 'label' => 'documents::field.document_no', 'width' => '8rem'],
                ['key' => 'name', 'label' => 'documents::field.name'],
                ['key' => 'archived_at', 'label' => 'documents::field.date', 'type' => ReportColumn::DATE],
                ['key' => 'archived_by_name', 'label' => 'documents::field.who'],
            ],
        );
    }

    /** ভার্সন — কোন কাগজে কয়টা ভার্সন, শেষটা কবে */
    public static function versions(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'documents.versions',
            permission: self::PERMISSION,
            title: 'documents::report.versions',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => self::documents($f)
                ->join('dms_document_versions as v', 'v.document_id', '=', 'dms_documents.id')
                ->whereBetween('v.created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
                ->select(['dms_documents.id', 'dms_documents.document_no', 'dms_documents.name'])
                ->selectRaw('COUNT(v.id) as versions')
                ->selectRaw('MAX(v.created_at) as last_version_at')
                ->groupBy('dms_documents.id', 'dms_documents.document_no', 'dms_documents.name')
                ->orderByRaw('COUNT(v.id) desc'),
            columns: [
                ['key' => 'document_no', 'label' => 'documents::field.document_no', 'width' => '8rem'],
                ['key' => 'name', 'label' => 'documents::field.name'],
                ['key' => 'versions', 'label' => 'documents::report.versions_count', 'type' => ReportColumn::QUANTITY],
                ['key' => 'last_version_at', 'label' => 'documents::field.date', 'type' => ReportColumn::DATE],
            ],
        );
    }

    /** জায়গা — ফোল্ডার ধরে কত MB (প্রতিটা ভার্সনের ফাইল, একই ফাইল দুইবার গোনা নয়) */
    public static function storage(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'documents.storage',
            permission: self::PERMISSION,
            title: 'documents::report.storage',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::query()->fromSub(
                self::created(self::documents($f), $f)
                    ->join('dms_document_versions as v', 'v.document_id', '=', 'dms_documents.id')
                    ->join('attachments as at', 'at.id', '=', 'v.attachment_id')
                    ->select(['dms_documents.folder', 'at.id as attachment_id', 'at.size_bytes'])
                    ->distinct(),
                'files',
            )
                ->select('files.folder')
                ->selectRaw('COUNT(*) as files')
                ->selectRaw('ROUND(SUM(files.size_bytes) / 1048576, 2) as megabytes')
                ->groupBy('files.folder')
                ->orderByRaw('SUM(files.size_bytes) desc'),
            columns: [
                ['key' => 'folder', 'label' => 'documents::field.folder'],
                ['key' => 'files', 'label' => 'documents::report.files', 'type' => ReportColumn::QUANTITY],
                ['key' => 'megabytes', 'label' => 'documents::report.megabytes', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }

    /**
     * অডিটের খাতা — কেবল দেখা যায় এমন কাগজের সারি, শাখার দেয়ালে, তারিখের সীমায়।
     */
    private static function audit(array $f): Builder
    {
        $visible = self::documents($f)->select('dms_documents.id');

        return DB::table('audit_trails as a')
            ->where('a.company_id', $f['company_id'])
            ->where('a.auditable_type', Document::class)
            ->whereIn('a.auditable_id', $visible)
            ->tap(ReportEngine::branchWall($f, 'a.branch_id'))
            ->whereBetween('a.created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59']);
    }

    /**
     * দল ধরে গোনা — মোট, চলতি, আর্কাইভ, মেয়াদোত্তীর্ণ।
     *
     * @param  string|list<string>  $groupBy
     */
    private static function counted(Builder $q, string|array $groupBy, ?string $label = null): Builder
    {
        $groups = (array) $groupBy;
        $archived = DB::getPdo()->quote(DocumentCatalog::ARCHIVED);
        $expired = DB::getPdo()->quote(DocumentCatalog::EXPIRED);

        return $q->selectRaw(($label ?? $groups[0]).' as '.($label === null ? 'doc_type' : 'label'))
            ->selectRaw('COUNT(*) as documents')
            ->selectRaw("SUM(CASE WHEN dms_documents.status = {$archived} THEN 1 ELSE 0 END) as archived")
            ->selectRaw("SUM(CASE WHEN dms_documents.status = {$expired} THEN 1 ELSE 0 END) as expired")
            ->groupBy(...$groups)
            ->orderByRaw('COUNT(*) desc');
    }

    /** @return list<array<string, mixed>> */
    private static function countColumns(string $key, string $label): array
    {
        return [
            ['key' => $key, 'label' => $label],
            ['key' => 'documents', 'label' => 'documents::report.count', 'type' => ReportColumn::QUANTITY],
            ['key' => 'archived', 'label' => 'documents::catalog.status.archived', 'type' => ReportColumn::QUANTITY],
            ['key' => 'expired', 'label' => 'documents::catalog.status.expired', 'type' => ReportColumn::QUANTITY],
        ];
    }
}
