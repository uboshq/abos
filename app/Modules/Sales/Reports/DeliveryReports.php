<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ডেলিভারির রিপোর্ট — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) §৯, ৬ অক্টোবর ২০২৬ (সমন্বয়কের মারফত):
 *
 *   ক OTIF — সময়মতো ও পুরো, আদেশের লাইন ধরে ([[OTIF]])
 *   খ চালানের অবস্থা — প্রতিটা চালান, এখনকার ধাপ আর প্রতিটা ধাপের সময়, গেট পাস, তৈরি থেকে রওনা ([[CHALLAN_STATUS]])
 *
 * ── ⭐ OTIF-এর সংজ্ঞা (SAP / D365-এর মতো; সমন্বয়কের সিদ্ধান্ত) ─────────────────────────────
 * একক = আদেশের লাইন। লাইনটা "সময়মতো ও পুরো" যদি প্রতিশ্রুত দিনের মধ্যে পৌঁছানো পরিমাণ ≥ চূড়ান্ত পরিমাণ
 * (`ordered_qty − rejected_qty`, [[OrderProgress]]-এর একই নিয়ম)।
 *   · প্রতিশ্রুত দিন: আদেশের `deliver_on` → নাহলে সেই আদেশের প্রথম চালানের `ship_date` → নাহলে আদেশের দিন
 *   · পৌঁছানো পরিমাণ: চালানের প্রথম "পৌঁছেছে" ঘটনায় চালানের লাইনের পুরোটা; প্রথমটা "আংশিক" হলে সেই ঘটনার লাইনে যতটা
 *     গেল ([[DeliveryEventLine]]) — ধাপের খাতা থেকে, হাতের লেখা নয়
 *   · গোনায় আসে কেবল যার প্রতিশ্রুত দিন দিনটা বা আগে (সামনের লাইনের দেরি হয়নি)
 * ⛔ আগে মাপা হত চালান ধরে, আর "পুরো" মানে ছিল চালানের ধাপ "পৌঁছেছে" — তাই আদেশের অর্ধেক মালের চালানও "পুরো" গুনত।
 *
 * ⓘ খসড়া, বাতিল আর ফেরানো আদেশ, আর ফেরানো লাইন বাদ। শাখা আদেশের; বিক্রয়কর্মী কেবল নিজের ডিলার ([[ReportEngine::dealerWall()]])।
 */
final class DeliveryReports
{
    public const OTIF = 'sales.otif';

    public const CHALLAN_STATUS = 'sales.challan_status';

    private const KEY = 'sales.report';

    public static function registerAll(ReportEngine $engine): void
    {
        $engine->register(self::otif());
        $engine->register(self::challanStatus());
    }

    /**
     * আদেশের প্রতিটা লাইন: প্রতিশ্রুত দিন, চূড়ান্ত পরিমাণ, প্রতিশ্রুত দিনের মধ্যে আর মোট পৌঁছানো, প্রথম পৌঁছানো, আর গোনায় /
     * সময়মতো-ও-পুরো (১/০)। ⓘ রিপোর্ট আর ড্যাশবোর্ড ([[DeliveryPerformance::summary()]]) দুইজনেই এটা পড়ে।
     *
     * @param  array<string, mixed>  $f  company_id, from, to (প্রতিশ্রুত দিন ধরে), আর দেয়ালের ছাঁকনি
     */
    public static function lines(array $f, ?string $today = null): Builder
    {
        $company = (int) $f['company_id'];
        $today = DB::getPdo()->quote($today ?? Carbon::today()->toDateString());

        // ⓘ চালানের প্রথম পৌঁছানো — সবচেয়ে ছোট id-র "পৌঁছেছে" বা "আংশিক" ঘটনা
        $firstArrival = DB::table('sal_delivery_events')
            ->where('company_id', $company)
            ->whereIn('to_stage', [DeliveryStage::DELIVERED, DeliveryStage::PARTIALLY_DELIVERED])
            ->groupBy('delivery_challan_id')
            ->selectRaw('delivery_challan_id, MIN(id) as event_id');

        $partial = DB::getPdo()->quote(DeliveryStage::PARTIALLY_DELIVERED);

        $arrived = DB::table('sal_challan_lines as cl')
            ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
            ->joinSub($firstArrival, 'fa', 'fa.delivery_challan_id', '=', 'c.id')
            ->join('sal_delivery_events as ev', 'ev.id', '=', 'fa.event_id')
            ->leftJoin('sal_delivery_event_lines as el', function ($j) {
                $j->on('el.delivery_event_id', '=', 'ev.id')->on('el.delivery_challan_line_id', '=', 'cl.id');
            })
            ->where('c.company_id', $company)
            ->whereIn('c.status', DocumentStatus::POSTED)
            ->whereNotNull('cl.sales_order_line_id')
            ->selectRaw("cl.sales_order_line_id as line_id, DATE(ev.occurred_at) as arrived_on, CASE WHEN ev.to_stage = {$partial} "
                .'THEN COALESCE(el.delivered_qty, 0) ELSE cl.delivered_qty END as qty');

        $firstShip = DB::table('sal_challans')
            ->where('company_id', $company)
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereNotNull('sales_order_id')
            ->groupBy('sales_order_id')
            ->selectRaw('sales_order_id, MIN(COALESCE(ship_date, trx_date)) as ship_on');

        $promised = 'COALESCE(o.deliver_on, fs.ship_on, o.trx_date)';
        $wanted = 'l.ordered_qty - COALESCE(l.rejected_qty, 0)';

        $inner = DB::table('sal_order_lines as l')
            ->join('sal_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->leftJoinSub($firstShip, 'fs', 'fs.sales_order_id', '=', 'o.id')
            ->leftJoinSub($arrived, 'a', 'a.line_id', '=', 'l.id')
            ->join('customers as cu', 'cu.id', '=', 'o.customer_id')
            ->join('inv_products as p', 'p.id', '=', 'l.product_id')
            ->where('o.company_id', $company)
            ->whereNotIn('o.status', [SalesOrderStatus::DRAFT, SalesOrderStatus::REJECTED, SalesOrderStatus::CANCELLED])
            ->where(fn ($q) => $q->whereNull('l.line_status')->orWhere('l.line_status', '!=', SalesOrderStatus::LINE_REJECTED))
            ->whereRaw("{$wanted} > 0")
            ->whereRaw("{$promised} BETWEEN ? AND ?", [$f['from'], $f['to']])
            ->tap(ReportEngine::branchWall($f, 'o.branch_id'))
            ->tap(ReportEngine::dealerWall($f, 'o.customer_id'))
            ->groupBy('l.id')
            ->selectRaw('MIN(o.document_no) as document_no, MIN(o.id) as source_id, MIN(o.trx_date) as order_date, '
                .'MIN('.self::name('cu').') as customer, MIN('.self::name('p').') as product, '
                ."MIN({$promised}) as promised_on, MIN({$wanted}) as wanted, "
                ."COALESCE(SUM(CASE WHEN a.arrived_on <= {$promised} THEN a.qty ELSE 0 END), 0) as on_time_qty, "
                .'COALESCE(SUM(a.qty), 0) as arrived_qty, MIN(a.arrived_on) as first_arrival');

        $state = 'CASE WHEN x.promised_on > '.$today.' THEN '.DB::getPdo()->quote((string) __('sales::otif.state_upcoming'))
            .' WHEN x.on_time_qty >= x.wanted THEN '.DB::getPdo()->quote((string) __('sales::otif.state_otif'))
            .' WHEN x.arrived_qty >= x.wanted THEN '.DB::getPdo()->quote((string) __('sales::otif.state_late'))
            .' WHEN x.arrived_qty > 0 THEN '.DB::getPdo()->quote((string) __('sales::otif.state_short'))
            .' ELSE '.DB::getPdo()->quote((string) __('sales::otif.state_none')).' END';

        return DB::query()->fromSub($inner, 'x')
            ->select('x.*')
            ->selectRaw(DB::getPdo()->quote('sales_order').' as source_type')
            ->selectRaw("CASE WHEN x.promised_on <= {$today} THEN 1 ELSE 0 END as due")
            ->selectRaw("CASE WHEN x.promised_on <= {$today} AND x.on_time_qty >= x.wanted THEN 1 ELSE 0 END as otif")
            ->selectRaw("{$state} as state");
    }

    /**
     * ⭐ খ — চালানের অবস্থা: তারিখের মধ্যের প্রতিটা নিশ্চিত চালান — এখনকার ধাপ, প্রতিটা ধাপে প্রথম কখন এল (ধাপের খাতা,
     * [[DeliveryEvent]] — হাতের লেখা নয়), গেট পাস (বাতিল নয়), আর তৈরি থেকে রওনা কত ঘণ্টা।
     *
     * ⓘ একটা ধাপ দুবার এলে (পৌঁছায়নি → আবার রওনা) প্রথমবারেরটা — দেরি লুকায় না। ধাপ বাদ দিয়ে এগোলে সেই ঘর খালি।
     * ⓘ শাখা চালানের; বিক্রয়কর্মী কেবল নিজের ডিলার।
     */
    private static function challanStatus(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::CHALLAN_STATUS,
            permission: self::KEY,
            title: 'sales::challan_status.title',
            filters: ['date_range'],
            query: function (array $f): Builder {
                $company = (int) $f['company_id'];
                $pdo = DB::getPdo();
                $first = fn (string $stage) => 'MIN(CASE WHEN ev.to_stage = '.$pdo->quote($stage).' THEN ev.occurred_at END)';

                $events = DB::table('sal_delivery_events as ev')
                    ->where('ev.company_id', $company)
                    ->groupBy('ev.delivery_challan_id')
                    ->selectRaw('ev.delivery_challan_id, '
                        .$first(DeliveryStage::ALLOCATED).' as allocated_at, '.$first(DeliveryStage::PICKING).' as picking_at, '
                        .$first(DeliveryStage::PACKED).' as packed_at, '.$first(DeliveryStage::DISPATCHED).' as dispatched_at, '
                        .'MIN(CASE WHEN ev.to_stage IN ('.$pdo->quote(DeliveryStage::DELIVERED).', '.$pdo->quote(DeliveryStage::PARTIALLY_DELIVERED).') '
                        .'THEN ev.occurred_at END) as arrived_at');

                $gate = DB::table('sal_gate_passes as gp')
                    ->where('gp.company_id', $company)
                    ->where('gp.status', \App\Modules\Sales\Models\GatePass::ISSUED)
                    ->groupBy('gp.delivery_challan_id')
                    ->selectRaw('gp.delivery_challan_id, MIN(gp.issued_at) as gate_at');

                /*
                 * ⭐ পৌঁছানোর প্রমাণ — ধাপ ৭ (মালিক, ৬ অক্টোবর ২০২৬: "ডিলার বুঝে নিলেন — নাম, ফোন … কম বা ভাঙা মাল"):
                 * প্রথম পৌঁছানোর (পুরো বা আংশিক) ঘটনার নাম · ফোন, আর সব পৌঁছানোর লাইনে ভাঙার যোগ।
                 */
                $arrived = [$pdo->quote(DeliveryStage::DELIVERED), $pdo->quote(DeliveryStage::PARTIALLY_DELIVERED)];
                $firstArrival = DB::table('sal_delivery_events as ae')
                    ->where('ae.company_id', $company)
                    ->whereRaw('ae.to_stage IN ('.implode(', ', $arrived).')')
                    ->groupBy('ae.delivery_challan_id')
                    ->selectRaw('ae.delivery_challan_id, MIN(ae.id) as first_id');
                $damaged = DB::table('sal_delivery_event_lines as dl')
                    ->join('sal_delivery_events as de', 'de.id', '=', 'dl.delivery_event_id')
                    ->where('dl.company_id', $company)
                    ->groupBy('de.delivery_challan_id')
                    ->selectRaw('de.delivery_challan_id, SUM(dl.damaged_qty) as damaged_qty');

                $stage = 'CASE COALESCE(st.stage, '.$pdo->quote(DeliveryStage::PENDING).')';

                foreach (DeliveryStage::ALL as $one) {
                    $stage .= ' WHEN '.$pdo->quote($one).' THEN '.$pdo->quote((string) __('sales::delivery.stage.'.$one));
                }

                $stage .= ' ELSE st.stage END';

                return DB::table('sal_challans as c')
                    ->join('customers as cu', 'cu.id', '=', 'c.customer_id')
                    ->leftJoin('sal_orders as o', 'o.id', '=', 'c.sales_order_id')
                    ->leftJoin('sal_delivery_states as st', 'st.delivery_challan_id', '=', 'c.id')
                    ->leftJoinSub($events, 'e', 'e.delivery_challan_id', '=', 'c.id')
                    ->leftJoinSub($gate, 'g', 'g.delivery_challan_id', '=', 'c.id')
                    ->leftJoinSub($firstArrival, 'fa', 'fa.delivery_challan_id', '=', 'c.id')
                    ->leftJoin('sal_delivery_events as rcv', 'rcv.id', '=', 'fa.first_id')
                    ->leftJoinSub($damaged, 'dmg', 'dmg.delivery_challan_id', '=', 'c.id')
                    ->where('c.company_id', $company)
                    ->whereIn('c.status', DocumentStatus::POSTED)
                    ->whereBetween('c.trx_date', [$f['from'], $f['to']])
                    ->tap(ReportEngine::branchWall($f, 'c.branch_id'))
                    ->tap(ReportEngine::dealerWall($f, 'c.customer_id'))
                    ->selectRaw('c.document_no as document_no, '.$pdo->quote('delivery_challan').' as source_type, c.id as source_id, '
                        .'c.trx_date as trx_date, '.self::name('cu').' as customer, o.document_no as order_no, '
                        ."COALESCE(NULLIF(c.vehicle_no, ''), '') as vehicle, {$stage} as stage, "
                        .'c.created_at as created_at, e.allocated_at, e.picking_at, e.packed_at, g.gate_at, e.dispatched_at, e.arrived_at, '
                        .'CASE WHEN e.dispatched_at IS NULL THEN NULL ELSE TIMESTAMPDIFF(HOUR, c.created_at, e.dispatched_at) END as hours_to_dispatch, '
                        ."CONCAT_WS(' · ', NULLIF(rcv.receiver_name, ''), NULLIF(rcv.receiver_phone, '')) as received_by, "
                        .'COALESCE(dmg.damaged_qty, 0) as damaged_qty')
                    ->orderBy('c.trx_date')
                    ->orderBy('c.id');
            },
            columns: [
                [
                    'key' => 'document_no',
                    'label' => 'sales::challan_status.challan',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                ['key' => 'customer', 'label' => 'sales::otif.customer'],
                ['key' => 'order_no', 'label' => 'sales::otif.order'],
                ['key' => 'vehicle', 'label' => 'sales::challan_status.vehicle'],
                ['key' => 'stage', 'label' => 'sales::challan_status.stage'],
                ['key' => 'created_at', 'label' => 'sales::challan_status.created_at'],
                ['key' => 'packed_at', 'label' => 'sales::challan_status.packed_at'],
                ['key' => 'gate_at', 'label' => 'sales::challan_status.gate_at'],
                ['key' => 'dispatched_at', 'label' => 'sales::challan_status.dispatched_at'],
                ['key' => 'arrived_at', 'label' => 'sales::challan_status.arrived_at'],
                ['key' => 'received_by', 'label' => 'sales::challan_status.received_by'],
                ['key' => 'damaged_qty', 'label' => 'sales::challan_status.damaged_qty', 'type' => ReportColumn::QUANTITY],
                ['key' => 'hours_to_dispatch', 'label' => 'sales::challan_status.hours_to_dispatch', 'type' => ReportColumn::QUANTITY, 'total' => false],
            ],
        );
    }

    /** নাম — বাংলায় বাংলা নাম (না থাকলে ইংরেজি), নাহলে ইংরেজি */
    private static function name(string $alias): string
    {
        return app()->getLocale() === 'bn' ? "COALESCE(NULLIF({$alias}.name_bn, ''), {$alias}.name_en)" : "{$alias}.name_en";
    }

    private static function otif(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::OTIF,
            permission: self::KEY,
            title: 'sales::otif.title',
            filters: ['date_range'],
            query: fn (array $f): Builder => self::lines($f)->orderBy('x.promised_on')->orderBy('x.document_no'),
            summary: function (array $totals): array {
                $due = (int) ($totals['due'] ?? 0);
                $otif = (int) ($totals['otif'] ?? 0);
                $rate = $due === 0 ? null : bcdiv(bcmul((string) $otif, '100', 4), (string) $due, 1);

                return [
                    'label' => __('sales::otif.summary'),
                    'value' => $rate ?? '0',
                    'text' => $rate === null ? __('sales::otif.none_due') : __('sales::otif.summary_text', ['rate' => $rate, 'otif' => $otif, 'due' => $due]),
                    'good' => $rate === null || bccomp($rate, '95', 1) >= 0,
                ];
            },
            columns: [
                [
                    'key' => 'document_no',
                    'label' => 'sales::otif.order',
                    'type' => ReportColumn::DOCUMENT,
                    'source_type' => 'source_type',
                    'source_id' => 'source_id',
                ],
                ['key' => 'customer', 'label' => 'sales::otif.customer'],
                ['key' => 'product', 'label' => 'sales::otif.product'],
                ['key' => 'promised_on', 'label' => 'sales::otif.promised_on', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'wanted', 'label' => 'sales::otif.wanted', 'type' => ReportColumn::QUANTITY],
                ['key' => 'on_time_qty', 'label' => 'sales::otif.on_time_qty', 'type' => ReportColumn::QUANTITY],
                ['key' => 'arrived_qty', 'label' => 'sales::otif.arrived_qty', 'type' => ReportColumn::QUANTITY],
                ['key' => 'first_arrival', 'label' => 'sales::otif.first_arrival', 'type' => ReportColumn::DATE, 'width' => '7rem'],
                ['key' => 'state', 'label' => 'sales::otif.state'],
                ['key' => 'due', 'label' => 'sales::otif.due', 'type' => ReportColumn::QUANTITY],
                ['key' => 'otif', 'label' => 'sales::otif.otif', 'type' => ReportColumn::QUANTITY],
            ],
        );
    }
}
