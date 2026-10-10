<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Modules\Accounts\Models\AssetEvent;
use App\Modules\Accounts\Models\AssetVerificationLine;
use App\Modules\Accounts\Models\FixedAsset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ স্থায়ী সম্পদের প্রতিবেদন — স্থায়ী সম্পদ ধাপ ৫ (মালিক, ১০ অক্টোবর ২০২৬; IAS 16.73-79)।
 *
 * ⓘ দশটা কাগজ, সবগুলো সম্পদের নিজের সারি থেকে (খাতা থেকে নয়) — খাতার সাথে মেলে কি না সেটা আলাদা যাচাই
 * ([[FixedAssetChecks]])। প্রতিটা কোয়েরিতে কোম্পানি আর শাখার দেয়াল ([[ReportEngine::branchWall()]])।
 *
 * ⚠️ "কোনো দিনে দাম" আর "কোনো দিনে সঞ্চিত ক্ষয়" — সারির আজকের অঙ্ক থেকে সেদিনের পরের ঘটনা বাদ দিয়ে, ঠিক
 * [[FixedAsset::costOn()]] আর [[FixedAsset::accumulated()]]-এর নিয়মে; সংখ্যা দুই জায়গায় দুই রকম হয় না।
 */
final class FixedAssetReports
{
    public const REGISTER = 'accounts.asset_register';

    public const SCHEDULE = 'accounts.asset_depreciation_schedule';

    public const MOVEMENT = 'accounts.asset_movement';

    public const NBV = 'accounts.asset_nbv';

    public const DISPOSALS = 'accounts.asset_disposals';

    public const FULLY_DEPRECIATED = 'accounts.asset_fully_depreciated';

    public const EXPIRING = 'accounts.asset_expiring';

    public const VARIANCE = 'accounts.asset_verification_variance';

    public const BOOK_VS_TAX = 'accounts.asset_book_vs_tax';

    public const MAINTENANCE = 'accounts.asset_maintenance';

    public static function registerAll(ReportEngine $engine): void
    {
        foreach ([self::register(), self::schedule(), self::movement(), self::nbv(), self::disposals(), self::fullyDepreciated(),
            self::expiring(), self::variance(), self::bookVsTax(), self::maintenance()] as $report) {
            $engine->register($report);
        }
    }

    // ── ১. নিবন্ধন ─────────────────────────────────────────────────────

    public static function register(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::REGISTER,
            permission: 'accounts.report',
            title: 'accounts::asset_report.register',
            filters: ['date_range', 'branch'],
            asOfDate: true,
            query: fn (array $f) => DB::query()->fromSub(self::asOf($f, (string) $f['to']), 'x')
                ->whereRaw('x.in_books = 1')
                ->select(['x.document_no', 'x.tag_no', 'x.name', 'x.category_name', 'x.branch_name', 'x.location', 'x.acquired_on', 'x.status_label'])
                ->selectRaw('x.cost_on AS cost')
                ->selectRaw('x.accumulated_on AS accumulated')
                ->selectRaw('x.cost_on - x.accumulated_on AS nbv')
                ->orderBy('x.category_name')->orderBy('x.document_no'),
            columns: [
                ['key' => 'document_no', 'label' => 'accounts::asset_report.document_no', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                ['key' => 'tag_no', 'label' => 'accounts::asset_report.tag_no', 'type' => ReportColumn::TEXT, 'width' => '8rem'],
                ['key' => 'name', 'label' => 'accounts::asset_report.name'],
                ['key' => 'category_name', 'label' => 'accounts::asset_report.category'],
                ['key' => 'branch_name', 'label' => 'accounts::asset_report.branch'],
                ['key' => 'location', 'label' => 'accounts::asset_report.location'],
                ['key' => 'acquired_on', 'label' => 'accounts::asset_report.acquired_on', 'type' => ReportColumn::DATE],
                ['key' => 'status_label', 'label' => 'accounts::asset_report.status'],
                ['key' => 'cost', 'label' => 'accounts::asset_report.cost', 'type' => ReportColumn::MONEY],
                ['key' => 'accumulated', 'label' => 'accounts::asset_report.accumulated', 'type' => ReportColumn::MONEY],
                ['key' => 'nbv', 'label' => 'accounts::asset_report.nbv', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    // ── ২. অবচয়ের তফসিল — মাস ধরে, সম্পদ ধরে ───────────────────────────

    public static function schedule(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::SCHEDULE,
            permission: 'accounts.report',
            title: 'accounts::asset_report.schedule',
            filters: ['date_range', 'branch'],
            query: function (array $f): Builder {
                $rows = DB::table('acc_depreciation_entries as de')
                    ->join('acc_fixed_assets as fa', 'fa.id', '=', 'de.fixed_asset_id')
                    ->where('de.company_id', $f['company_id'])
                    ->where('fa.company_id', $f['company_id'])
                    ->tap(ReportEngine::branchWall($f, 'fa.branch_id'))
                    ->whereBetween('de.period_end', [$f['from'], $f['to']])
                    ->groupBy('fa.id', 'de.period_end')
                    ->select(['fa.id', 'de.period_end'])
                    ->selectRaw('SUM(de.amount) AS amount');

                return DB::query()->fromSub($rows, 's')
                    ->join('acc_fixed_assets as a', 'a.id', '=', 's.id')
                    ->leftJoin('acc_asset_categories as c', 'c.id', '=', 'a.category_id')
                    ->select(['s.period_end', 'a.document_no', 'a.name', 's.amount'])
                    ->selectRaw(self::categoryName('c').' AS category_name')
                    ->orderBy('s.period_end')->orderByRaw(self::categoryName('c'))->orderBy('a.document_no');
            },
            columns: [
                ['key' => 'period_end', 'label' => 'accounts::asset_report.month', 'type' => ReportColumn::DATE, 'width' => '8rem'],
                ['key' => 'category_name', 'label' => 'accounts::asset_report.category'],
                ['key' => 'document_no', 'label' => 'accounts::asset_report.document_no', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                ['key' => 'name', 'label' => 'accounts::asset_report.name'],
                ['key' => 'amount', 'label' => 'accounts::asset_report.depreciation', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    // ── ৩. চলাচলের তফসিল — IAS 16.73(ঙ) ──────────────────────────────────

    /**
     * ⭐ শ্রেণি ধরে: শুরুর দাম + সংযোজন + পুনর্মূল্যায়ন − বিদায় = শেষের দাম; সঞ্চিত ক্ষয়েরও একই।
     *
     * ⓘ প্রতিটা সম্পদের শুরু আর শেষের দাম-ক্ষয় একই নিয়মে ([[asOf()]]), মাঝের সংখ্যাগুলো ঘটনা থেকে — আর শেষে
     * "শুরু + মাঝ = শেষ" নিজে মিলে যায় (পরীক্ষায় দেখা)।
     */
    public static function movement(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::MOVEMENT,
            permission: 'accounts.report',
            title: 'accounts::asset_report.movement',
            filters: ['date_range', 'branch'],
            query: function (array $f): Builder {
                $before = date('Y-m-d', strtotime((string) $f['from'].' -1 day'));
                $open = self::asOf($f, $before);
                $close = self::asOf($f, (string) $f['to']);
                $from = (string) $f['from'];
                $to = (string) $f['to'];

                $perAsset = DB::table('acc_fixed_assets as fa')
                    ->where('fa.company_id', $f['company_id'])
                    ->whereNull('fa.deleted_at')
                    ->where('fa.status', '!=', FixedAsset::AWAITING)
                    ->tap(ReportEngine::branchWall($f, 'fa.branch_id'))
                    ->joinSub($open, 'o', 'o.id', '=', 'fa.id')
                    ->joinSub($close, 'z', 'z.id', '=', 'fa.id')
                    ->select(['fa.id', 'fa.category_id'])
                    ->selectRaw('CASE WHEN o.in_books = 1 THEN o.cost_on ELSE 0 END AS opening_cost')
                    ->selectRaw('CASE WHEN o.in_books = 1 THEN o.accumulated_on ELSE 0 END AS opening_acc')
                    // ⓘ নতুন সম্পদ — সময়ের ভেতরে কেনা: কেনা দাম (সব ঘটনা বাদ দিয়ে; সংযোজন নিজের ঘরে)
                    ->selectRaw('CASE WHEN fa.acquired_on BETWEEN ? AND ? THEN fa.cost - '.self::eventSum('cost_change', null, null).' ELSE 0 END AS acquired', [$from, $to])
                    ->selectRaw(self::eventSumIn('cost_change', AssetEvent::ADDITION).' AS additions', [$from, $to])
                    ->selectRaw(self::eventSumIn('cost_change', AssetEvent::REVALUATION).' AS revalued', [$from, $to])
                    ->selectRaw(self::eventSumIn('accumulated_change', AssetEvent::REVALUATION).' AS revalued_acc', [$from, $to])
                    ->selectRaw(self::eventSumIn('accumulated_change', AssetEvent::IMPAIRMENT).' AS impaired', [$from, $to])
                    ->selectRaw('(SELECT COALESCE(SUM(de.amount), 0) FROM acc_depreciation_entries de WHERE de.fixed_asset_id = fa.id AND de.period_end BETWEEN ? AND ?) AS charge', [$from, $to])
                    // ⓘ সময়ের ভেতরে বিদায় — বিদায়ের দিনের দাম আর ক্ষয় (তার পরে আর কিছু বসে না, তাই শেষ দিনের অঙ্কই)
                    ->selectRaw('CASE WHEN fa.disposed_on BETWEEN ? AND ? THEN z.cost_all ELSE 0 END AS disposed_cost', [$from, $to])
                    ->selectRaw('CASE WHEN fa.disposed_on BETWEEN ? AND ? THEN z.acc_all ELSE 0 END AS disposed_acc', [$from, $to])
                    ->selectRaw('CASE WHEN z.in_books = 1 THEN z.cost_on ELSE 0 END AS closing_cost')
                    ->selectRaw('CASE WHEN z.in_books = 1 THEN z.accumulated_on ELSE 0 END AS closing_acc');

                return DB::query()->fromSub($perAsset, 'm')
                    ->leftJoin('acc_asset_categories as c', 'c.id', '=', 'm.category_id')
                    ->groupBy('m.category_id', 'c.name_en', 'c.name_bn')
                    ->selectRaw(self::categoryName('c').' AS category_name')
                    ->selectRaw('SUM(m.opening_cost) AS opening_cost')
                    ->selectRaw('SUM(m.acquired + m.additions) AS additions')
                    ->selectRaw('SUM(m.revalued) AS revalued')
                    ->selectRaw('SUM(m.disposed_cost) AS disposed_cost')
                    ->selectRaw('SUM(m.closing_cost) AS closing_cost')
                    ->selectRaw('SUM(m.opening_acc) AS opening_acc')
                    ->selectRaw('SUM(m.charge) AS charge')
                    ->selectRaw('SUM(m.impaired) AS impaired')
                    ->selectRaw('SUM(m.revalued_acc) AS revalued_acc')
                    ->selectRaw('SUM(m.disposed_acc) AS disposed_acc')
                    ->selectRaw('SUM(m.closing_acc) AS closing_acc')
                    ->selectRaw('SUM(m.closing_cost - m.closing_acc) AS closing_nbv')
                    ->havingRaw('SUM(m.opening_cost) <> 0 OR SUM(m.closing_cost) <> 0 OR SUM(m.disposed_cost) <> 0')
                    ->orderByRaw(self::categoryName('c'));
            },
            columns: [
                ['key' => 'category_name', 'label' => 'accounts::asset_report.category'],
                ['key' => 'opening_cost', 'label' => 'accounts::asset_report.opening_cost', 'type' => ReportColumn::MONEY],
                ['key' => 'additions', 'label' => 'accounts::asset_report.additions', 'type' => ReportColumn::MONEY],
                ['key' => 'revalued', 'label' => 'accounts::asset_report.revalued', 'type' => ReportColumn::MONEY],
                ['key' => 'disposed_cost', 'label' => 'accounts::asset_report.disposed_cost', 'type' => ReportColumn::MONEY],
                ['key' => 'closing_cost', 'label' => 'accounts::asset_report.closing_cost', 'type' => ReportColumn::MONEY],
                ['key' => 'opening_acc', 'label' => 'accounts::asset_report.opening_acc', 'type' => ReportColumn::MONEY],
                ['key' => 'charge', 'label' => 'accounts::asset_report.charge', 'type' => ReportColumn::MONEY],
                ['key' => 'impaired', 'label' => 'accounts::asset_report.impaired', 'type' => ReportColumn::MONEY],
                ['key' => 'revalued_acc', 'label' => 'accounts::asset_report.revalued_acc', 'type' => ReportColumn::MONEY],
                ['key' => 'disposed_acc', 'label' => 'accounts::asset_report.disposed_acc', 'type' => ReportColumn::MONEY],
                ['key' => 'closing_acc', 'label' => 'accounts::asset_report.closing_acc', 'type' => ReportColumn::MONEY],
                ['key' => 'closing_nbv', 'label' => 'accounts::asset_report.nbv', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    // ── ৪. শাখা আর শ্রেণি ধরে খাতার দাম ──────────────────────────────────

    public static function nbv(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::NBV,
            permission: 'accounts.report',
            title: 'accounts::asset_report.nbv_title',
            filters: ['date_range', 'branch'],
            asOfDate: true,
            query: fn (array $f) => DB::query()->fromSub(self::asOf($f, (string) $f['to']), 'x')
                ->whereRaw('x.in_books = 1')
                ->groupBy('x.branch_name', 'x.category_name')
                ->select(['x.branch_name', 'x.category_name'])
                ->selectRaw('COUNT(*) AS assets')
                ->selectRaw('SUM(x.cost_on) AS cost')
                ->selectRaw('SUM(x.accumulated_on) AS accumulated')
                ->selectRaw('SUM(x.cost_on - x.accumulated_on) AS nbv')
                ->orderBy('x.branch_name')->orderBy('x.category_name'),
            columns: [
                ['key' => 'branch_name', 'label' => 'accounts::asset_report.branch'],
                ['key' => 'category_name', 'label' => 'accounts::asset_report.category'],
                ['key' => 'assets', 'label' => 'accounts::asset_report.count', 'type' => ReportColumn::QUANTITY],
                ['key' => 'cost', 'label' => 'accounts::asset_report.cost', 'type' => ReportColumn::MONEY],
                ['key' => 'accumulated', 'label' => 'accounts::asset_report.accumulated', 'type' => ReportColumn::MONEY],
                ['key' => 'nbv', 'label' => 'accounts::asset_report.nbv', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    // ── ৫. বিদায় আর লাভ-লোকসান ────────────────────────────────────────

    public static function disposals(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::DISPOSALS,
            permission: 'accounts.report',
            title: 'accounts::asset_report.disposals',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::query()->fromSub(self::asOf($f, (string) $f['to']), 'x')
                ->whereBetween('x.disposed_on', [$f['from'], $f['to']])
                ->select(['x.disposed_on', 'x.document_no', 'x.name', 'x.category_name', 'x.branch_name', 'x.status_label', 'x.disposal_reason'])
                ->selectRaw('x.cost_all AS cost')
                ->selectRaw('x.acc_all AS accumulated')
                ->selectRaw('x.cost_all - x.acc_all AS nbv')
                ->selectRaw('COALESCE(x.disposal_amount, 0) AS proceeds')
                ->selectRaw('COALESCE(x.disposal_amount, 0) - (x.cost_all - x.acc_all) AS gain_loss')
                ->orderBy('x.disposed_on')->orderBy('x.document_no'),
            columns: [
                ['key' => 'disposed_on', 'label' => 'accounts::asset_report.disposed_on', 'type' => ReportColumn::DATE, 'width' => '8rem'],
                ['key' => 'document_no', 'label' => 'accounts::asset_report.document_no', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                ['key' => 'name', 'label' => 'accounts::asset_report.name'],
                ['key' => 'category_name', 'label' => 'accounts::asset_report.category'],
                ['key' => 'branch_name', 'label' => 'accounts::asset_report.branch'],
                ['key' => 'status_label', 'label' => 'accounts::asset_report.how_left'],
                ['key' => 'disposal_reason', 'label' => 'accounts::asset_report.reason'],
                ['key' => 'cost', 'label' => 'accounts::asset_report.cost', 'type' => ReportColumn::MONEY],
                ['key' => 'accumulated', 'label' => 'accounts::asset_report.accumulated', 'type' => ReportColumn::MONEY],
                ['key' => 'nbv', 'label' => 'accounts::asset_report.nbv', 'type' => ReportColumn::MONEY],
                ['key' => 'proceeds', 'label' => 'accounts::asset_report.proceeds', 'type' => ReportColumn::MONEY],
                ['key' => 'gain_loss', 'label' => 'accounts::asset_report.gain_loss', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    // ── ৬. পুরো ক্ষয় হয়ে গেছে, তবু চলছে ─────────────────────────────────

    public static function fullyDepreciated(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::FULLY_DEPRECIATED,
            permission: 'accounts.report',
            title: 'accounts::asset_report.fully_depreciated',
            filters: ['date_range', 'branch'],
            asOfDate: true,
            query: fn (array $f) => DB::query()->fromSub(self::asOf($f, (string) $f['to']), 'x')
                ->whereRaw('x.in_books = 1')
                ->whereIn('x.status', FixedAsset::IN_SERVICE)
                ->whereRaw('x.cost_on - x.accumulated_on <= x.salvage')
                ->select(['x.document_no', 'x.tag_no', 'x.name', 'x.category_name', 'x.branch_name', 'x.location', 'x.acquired_on', 'x.status_label'])
                ->selectRaw('x.cost_on AS cost')
                ->selectRaw('x.cost_on - x.accumulated_on AS nbv')
                ->orderBy('x.acquired_on'),
            columns: [
                ['key' => 'document_no', 'label' => 'accounts::asset_report.document_no', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                ['key' => 'tag_no', 'label' => 'accounts::asset_report.tag_no', 'type' => ReportColumn::TEXT, 'width' => '8rem'],
                ['key' => 'name', 'label' => 'accounts::asset_report.name'],
                ['key' => 'category_name', 'label' => 'accounts::asset_report.category'],
                ['key' => 'branch_name', 'label' => 'accounts::asset_report.branch'],
                ['key' => 'location', 'label' => 'accounts::asset_report.location'],
                ['key' => 'acquired_on', 'label' => 'accounts::asset_report.acquired_on', 'type' => ReportColumn::DATE],
                ['key' => 'status_label', 'label' => 'accounts::asset_report.status'],
                ['key' => 'cost', 'label' => 'accounts::asset_report.cost', 'type' => ReportColumn::MONEY],
                ['key' => 'nbv', 'label' => 'accounts::asset_report.nbv', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    // ── ৭. ওয়ারেন্টি আর বিমা শেষ হচ্ছে ──────────────────────────────────

    public static function expiring(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::EXPIRING,
            permission: 'accounts.report',
            title: 'accounts::asset_report.expiring',
            filters: ['date_range', 'branch'],
            query: function (array $f): Builder {
                $base = fn (string $column, string $what) => DB::table('acc_fixed_assets as fa')
                    ->where('fa.company_id', $f['company_id'])
                    ->whereNull('fa.deleted_at')
                    ->whereIn('fa.status', FixedAsset::IN_SERVICE)
                    ->tap(ReportEngine::branchWall($f, 'fa.branch_id'))
                    ->whereBetween('fa.'.$column, [$f['from'], $f['to']])
                    ->select(['fa.document_no', 'fa.name', 'fa.serial_no', 'fa.insurance_policy_no'])
                    ->selectRaw('fa.'.$column.' AS ends_on')
                    ->selectRaw('? AS what', [__('accounts::asset_report.'.$what)]);

                return DB::query()->fromSub($base('warranty_ends_on', 'warranty')->unionAll($base('insured_until', 'insurance')), 'e')
                    ->select(['e.ends_on', 'e.what', 'e.document_no', 'e.name', 'e.serial_no', 'e.insurance_policy_no'])
                    ->orderBy('e.ends_on')->orderBy('e.document_no');
            },
            columns: [
                ['key' => 'ends_on', 'label' => 'accounts::asset_report.ends_on', 'type' => ReportColumn::DATE, 'width' => '8rem'],
                ['key' => 'what', 'label' => 'accounts::asset_report.what'],
                ['key' => 'document_no', 'label' => 'accounts::asset_report.document_no', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                ['key' => 'name', 'label' => 'accounts::asset_report.name'],
                ['key' => 'serial_no', 'label' => 'accounts::asset_report.serial_no'],
                ['key' => 'insurance_policy_no', 'label' => 'accounts::asset_report.policy_no'],
            ],
        );
    }

    // ── ৮. গোনার পার্থক্য ──────────────────────────────────────────────

    public static function variance(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::VARIANCE,
            permission: 'accounts.report',
            title: 'accounts::asset_report.variance',
            filters: ['date_range', 'branch'],
            query: function (array $f): Builder {
                $results = collect([...AssetVerificationLine::RESULTS, 'unchecked'])
                    ->map(fn ($r) => 'WHEN '.DB::getPdo()->quote($r).' THEN '.DB::getPdo()->quote((string) __('accounts::asset.verify_'.$r)))
                    ->implode(' ');

                return DB::table('acc_asset_verification_lines as l')
                    ->join('acc_asset_verifications as v', 'v.id', '=', 'l.verification_id')
                    ->join('acc_fixed_assets as fa', 'fa.id', '=', 'l.fixed_asset_id')
                    ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
                    ->where('l.company_id', $f['company_id'])
                    ->where('v.company_id', $f['company_id'])
                    ->tap(ReportEngine::branchWall($f, 'v.branch_id'))
                    ->whereBetween('v.started_on', [$f['from'], $f['to']])
                    ->where(fn ($q) => $q->whereNull('l.result')->orWhere('l.result', '!=', AssetVerificationLine::FOUND))
                    ->select(['v.document_no as count_no', 'v.started_on', 'fa.document_no', 'fa.name', 'l.expected_location', 'l.found_location', 'l.note'])
                    ->selectRaw(self::branchName('b').' AS branch_name')
                    ->selectRaw("CASE COALESCE(l.result, 'unchecked') {$results} END AS result_label")
                    ->orderBy('v.started_on')->orderBy('v.document_no')->orderBy('fa.document_no');
            },
            columns: [
                ['key' => 'started_on', 'label' => 'accounts::asset_report.count_date', 'type' => ReportColumn::DATE, 'width' => '8rem'],
                ['key' => 'count_no', 'label' => 'accounts::asset_report.count_no', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                ['key' => 'branch_name', 'label' => 'accounts::asset_report.branch'],
                ['key' => 'document_no', 'label' => 'accounts::asset_report.document_no', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                ['key' => 'name', 'label' => 'accounts::asset_report.name'],
                ['key' => 'result_label', 'label' => 'accounts::asset_report.result'],
                ['key' => 'expected_location', 'label' => 'accounts::asset_report.expected_location'],
                ['key' => 'found_location', 'label' => 'accounts::asset_report.found_location'],
                ['key' => 'note', 'label' => 'accounts::asset_report.note'],
            ],
        );
    }

    // ── ৯. খাতার বনাম করের অবচয় ────────────────────────────────────────

    public static function bookVsTax(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::BOOK_VS_TAX,
            permission: 'accounts.report',
            title: 'accounts::asset_report.book_vs_tax',
            filters: ['date_range', 'branch'],
            query: fn (array $f) => DB::table('acc_asset_tax_years as t')
                ->join('acc_fixed_assets as fa', 'fa.id', '=', 't.fixed_asset_id')
                ->leftJoin('acc_asset_categories as c', 'c.id', '=', 'fa.category_id')
                ->where('t.company_id', $f['company_id'])
                ->where('fa.company_id', $f['company_id'])
                ->tap(ReportEngine::branchWall($f, 'fa.branch_id'))
                ->whereBetween('t.year_end', [$f['from'], $f['to']])
                ->select(['t.year_end', 'fa.document_no', 'fa.name', 't.rate', 't.opening_wdv', 't.closing_wdv'])
                ->selectRaw(self::categoryName('c').' AS category_name')
                ->selectRaw('(SELECT COALESCE(SUM(de.amount), 0) FROM acc_depreciation_entries de WHERE de.fixed_asset_id = fa.id AND de.period_end BETWEEN t.year_start AND t.year_end) AS book')
                ->selectRaw('t.amount AS tax')
                ->selectRaw('t.amount - (SELECT COALESCE(SUM(de2.amount), 0) FROM acc_depreciation_entries de2 WHERE de2.fixed_asset_id = fa.id AND de2.period_end BETWEEN t.year_start AND t.year_end) AS difference')
                ->orderBy('t.year_end')->orderByRaw(self::categoryName('c'))->orderBy('fa.document_no'),
            columns: [
                ['key' => 'year_end', 'label' => 'accounts::asset_report.year_end', 'type' => ReportColumn::DATE, 'width' => '8rem'],
                ['key' => 'category_name', 'label' => 'accounts::asset_report.category'],
                ['key' => 'document_no', 'label' => 'accounts::asset_report.document_no', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                ['key' => 'name', 'label' => 'accounts::asset_report.name'],
                ['key' => 'rate', 'label' => 'accounts::asset_report.tax_rate', 'type' => ReportColumn::PERCENT, 'width' => '6rem'],
                ['key' => 'opening_wdv', 'label' => 'accounts::asset_report.opening_wdv', 'type' => ReportColumn::MONEY],
                ['key' => 'book', 'label' => 'accounts::asset_report.book', 'type' => ReportColumn::MONEY],
                ['key' => 'tax', 'label' => 'accounts::asset_report.tax', 'type' => ReportColumn::MONEY],
                ['key' => 'difference', 'label' => 'accounts::asset_report.difference', 'type' => ReportColumn::MONEY],
                ['key' => 'closing_wdv', 'label' => 'accounts::asset_report.closing_wdv', 'type' => ReportColumn::MONEY],
            ],
        );
    }

    // ── ১০. সম্পদ ধরে মেরামতের খরচ ─────────────────────────────────────

    public static function maintenance(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::MAINTENANCE,
            permission: 'accounts.report',
            title: 'accounts::asset_report.maintenance',
            filters: ['date_range', 'branch'],
            query: function (array $f): Builder {
                $repairs = DB::table('acc_asset_events as ev')
                    ->join('acc_fixed_assets as fa', 'fa.id', '=', 'ev.fixed_asset_id')
                    ->where('ev.company_id', $f['company_id'])
                    ->where('fa.company_id', $f['company_id'])
                    ->tap(ReportEngine::branchWall($f, 'fa.branch_id'))
                    ->where('ev.kind', AssetEvent::REPAIR)
                    ->where('ev.status', AssetEvent::POSTED)
                    ->whereBetween('ev.happened_on', [$f['from'], $f['to']])
                    ->groupBy('fa.id')
                    ->select('fa.id')
                    ->selectRaw('COUNT(*) AS repairs')
                    ->selectRaw('SUM(ev.amount) AS amount')
                    ->selectRaw('MAX(ev.happened_on) AS last_on');

                return DB::query()->fromSub($repairs, 'r')
                    ->join('acc_fixed_assets as a', 'a.id', '=', 'r.id')
                    ->leftJoin('acc_asset_categories as c', 'c.id', '=', 'a.category_id')
                    ->select(['a.document_no', 'a.name', 'r.repairs', 'r.last_on', 'r.amount', 'a.cost'])
                    ->selectRaw(self::categoryName('c').' AS category_name')
                    ->orderByDesc('r.amount')->orderBy('a.document_no');
            },
            columns: [
                ['key' => 'document_no', 'label' => 'accounts::asset_report.document_no', 'type' => ReportColumn::TEXT, 'width' => '9rem'],
                ['key' => 'name', 'label' => 'accounts::asset_report.name'],
                ['key' => 'category_name', 'label' => 'accounts::asset_report.category'],
                ['key' => 'repairs', 'label' => 'accounts::asset_report.repairs', 'type' => ReportColumn::QUANTITY],
                ['key' => 'last_on', 'label' => 'accounts::asset_report.last_repair', 'type' => ReportColumn::DATE],
                ['key' => 'amount', 'label' => 'accounts::asset_report.repair_cost', 'type' => ReportColumn::MONEY],
                ['key' => 'cost', 'label' => 'accounts::asset_report.cost', 'type' => ReportColumn::MONEY, 'total' => false],
            ],
        );
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * ⭐ প্রতিটা সম্পদ কোনো দিনে — খাতায় ছিল কি না, সেদিনের দাম আর সঞ্চিত ক্ষয়, আর বিদায়ের দিনের পুরো অঙ্ক।
     *
     * ⓘ `cost_all` / `acc_all` — সব পাকা ঘটনাসহ (বিদায়ের পরে কিছু বসে না, তাই বিদায়ের দিনের অঙ্কই)।
     */
    public static function asOf(array $f, string $on): Builder
    {
        $statuses = collect(FixedAsset::STATUSES)
            ->map(fn ($s) => 'WHEN '.DB::getPdo()->quote($s).' THEN '.DB::getPdo()->quote((string) __('accounts::asset.status_'.$s)))
            ->implode(' ');

        return DB::table('acc_fixed_assets as fa')
            ->leftJoin('acc_asset_categories as c', 'c.id', '=', 'fa.category_id')
            ->leftJoin('branches as b', 'b.id', '=', 'fa.branch_id')
            ->where('fa.company_id', $f['company_id'])
            ->whereNull('fa.deleted_at')
            ->where('fa.status', '!=', FixedAsset::AWAITING)
            ->tap(ReportEngine::branchWall($f, 'fa.branch_id'))
            ->select(['fa.id', 'fa.document_no', 'fa.tag_no', 'fa.name', 'fa.location', 'fa.acquired_on', 'fa.status', 'fa.salvage',
                'fa.disposed_on', 'fa.disposal_amount', 'fa.disposal_reason'])
            ->selectRaw(self::categoryName('c').' AS category_name')
            ->selectRaw(self::branchName('b').' AS branch_name')
            ->selectRaw("CASE fa.status {$statuses} END AS status_label")
            ->selectRaw('CASE WHEN fa.acquired_on <= ? AND (fa.disposed_on IS NULL OR fa.disposed_on > ?) THEN 1 ELSE 0 END AS in_books', [$on, $on])
            ->selectRaw('fa.cost - '.self::eventSum('cost_change', '?', '>').' AS cost_on', [$on])
            ->selectRaw('(SELECT COALESCE(SUM(de.amount), 0) FROM acc_depreciation_entries de WHERE de.fixed_asset_id = fa.id AND de.period_end <= ?) + '
                .self::eventSum('accumulated_change', '?', '<=').' AS accumulated_on', [$on, $on])
            ->selectRaw('fa.cost AS cost_all')
            ->selectRaw('(SELECT COALESCE(SUM(de.amount), 0) FROM acc_depreciation_entries de WHERE de.fixed_asset_id = fa.id) + '
                .self::eventSum('accumulated_change', null, null).' AS acc_all');
    }

    /** পাকা ঘটনার যোগফল, তারিখের শর্তসহ বা ছাড়া */
    private static function eventSum(string $column, ?string $against, ?string $op): string
    {
        $when = $against === null ? '' : " AND ev.happened_on {$op} {$against}";

        return "(SELECT COALESCE(SUM(ev.{$column}), 0) FROM acc_asset_events ev WHERE ev.fixed_asset_id = fa.id AND ev.status = 'posted'{$when})";
    }

    /** একটা ধরনের পাকা ঘটনা, সময়ের ভেতরে — দুইটা বাঁধা মান: শুরু, শেষ */
    private static function eventSumIn(string $column, string $kind): string
    {
        return "(SELECT COALESCE(SUM(ev.{$column}), 0) FROM acc_asset_events ev WHERE ev.fixed_asset_id = fa.id AND ev.status = 'posted'"
            ." AND ev.kind = '{$kind}' AND ev.happened_on BETWEEN ? AND ?)";
    }

    private static function categoryName(string $alias): string
    {
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF({$alias}.name_bn, ''), {$alias}.name_en)" : "{$alias}.name_en";

        return 'COALESCE('.$name.', '.DB::getPdo()->quote((string) __('accounts::asset_report.no_category')).')';
    }

    private static function branchName(string $alias): string
    {
        $name = app()->getLocale() === 'bn' ? "COALESCE(NULLIF({$alias}.name_bn, ''), {$alias}.name_en)" : "{$alias}.name_en";

        return 'COALESCE('.$name.', '.DB::getPdo()->quote((string) __('accounts::asset_report.no_branch')).')';
    }
}
