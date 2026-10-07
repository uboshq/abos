<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Dashboard;

use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ সরবরাহকারীর ড্যাশবোর্ডের বাকি নকশা — মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬।
 *
 * ⓘ এ মাসে কত কেনা, ছয় মাসের কেনা-ফেরতের ধারা, আর এ মাসের শীর্ষ পাঁচ সরবরাহকারীর কাজের খাতা (দেরিতে মাল, ফেরত)।
 *
 * ── ⚠️ কেন কাঁচা কোয়েরি ──────────────────────────────────────────────
 * সরবরাহকারী মডিউল ক্রয়কে চেনে না (`depends_on`: accounts, master_data) — ক্রয়ের মডেল এখানে আনলে
 * [[BoundariesTest]] ধরত, আর উল্টো তীর একটা চক্র বানাত। ⓘ তাই টেবিলগুলো সরাসরি পড়া, যেমন
 * [[PartyReports]] পড়ে; কোম্পানি (`company_id`), মোছা বাদ (`deleted_at`) আর হেডারে বাছা শাখা
 * ([[DataScope::inView()]]) — তিনটাই হাতে বসানো, প্রতিটা কোয়েরিতে।
 *
 * ⛔ সবই ক্রয়ের অঙ্ক — কেবল `purchase.bill.view` যাঁর আছে; চাবি না থাকলে ঘরটাই নেই (ক্রয় মডিউল না থাকলে চাবিও নেই)।
 * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
 */
final class SupplierCharts
{
    /**
     * ⭐ এ মাসে কেনা — নিশ্চিত (বা বন্ধ) বিলের মোট।
     *
     * ⓘ সংজ্ঞা ক্রয়ের ড্যাশবোর্ডের "এই মাসে কেনা"-র হুবহু (মাসের প্রথম দিন থেকে, বিলের `total`), তাই দুই পর্দা কখনো দুই কথা বলে না।
     *
     * @return list<Stat>
     */
    public static function stats(): array
    {
        if (! self::on()) {
            return [];
        }

        $bought = (string) (self::bills()
            ->where('pur_bills.trx_date', '>=', Carbon::today()->startOfMonth()->toDateString())
            ->selectRaw('COALESCE(SUM(pur_bills.total), 0) as amount')
            ->value('amount') ?? '0');

        return [new Stat(
            label: __('supplier::dashboard.bought_this_month'),
            value: Money::format($bought),
            hint: __('supplier::dashboard.bought_this_month_hint'),
            // ⓘ ক্রয়ের পাতা — মডিউলটা না থাকলে পথও নেই, তখন সংখ্যাটা কোথাও নিয়ে যায় না
            href: \Illuminate\Support\Facades\Route::has('purchase.bill.index') ? route('purchase.bill.index') : null,
            permission: 'purchase.bill.view',
        )];
    }

    /**
     * ⭐ কেনার ধারা — গত ছয় মাস (এ মাসসহ), কেনা আর ফেরত পাশাপাশি, ভরা রেখায়।
     *
     * ⓘ কেনা = নিশ্চিত বিলের `total`, ফেরত = নিশ্চিত ফেরতের `total`; মাস ধরে ভাগ, ফাঁকা মাসও শূন্য নিয়ে থাকে।
     * ⓘ মাসের হিসাব অ্যাপের ঘড়িতে — SQL-এ আজকের তারিখ নয়।
     *
     * @return list<Series>
     */
    public static function panels(): array
    {
        if (! self::on()) {
            return [];
        }

        $start = Carbon::today()->startOfMonth()->subMonths(5);
        $today = Carbon::today();
        $range = [$start->toDateString(), $today->toDateString()];

        $bought = self::byMonth(self::bills(), 'pur_bills', $range);
        $returned = self::byMonth(self::returns(), 'pur_returns', $range);

        $points = [];

        for ($month = $start->copy(); $month->lessThanOrEqualTo($today); $month->addMonth()) {
            $ym = $month->format('Y-m');
            $in = (string) ($bought[$ym] ?? '0');
            $out = (string) ($returned[$ym] ?? '0');

            $points[] = [
                'label' => $month->translatedFormat('M'),
                'first' => $in,
                'second' => $out,
                'firstTitle' => Money::format($in),
                'secondTitle' => Money::format($out),
            ];
        }

        return [new Series(
            label: __('supplier::dashboard.trend'),
            points: $points,
            firstLabel: __('supplier::dashboard.trend_bought'),
            secondLabel: __('supplier::dashboard.trend_returned'),
            chart: 'area',
            range: DateRange::label($start, $today),
        )];
    }

    /**
     * ⭐ সরবরাহকারীর কাজের খাতা — এ মাসে যাঁদের কাছ থেকে সবচেয়ে বেশি কেনা, প্রথম পাঁচজন।
     *
     * ⓘ দেরিতে মাল: এ মাসের নিশ্চিত মাল গ্রহণ, যা ক্রয়াদেশের "আসার কথা" (`expected_on`) তারিখের **পরে** এসেছে — কয়টা কাগজ।
     * আদেশ ছাড়া মাল গ্রহণ, বা আসার তারিখ না লেখা আদেশ গোনা হয় না: তুলনার কিছু নেই।
     * ⓘ ফেরত: এ মাসের নিশ্চিত ফেরতের মোট টাকা। কেনা: উপরের সংখ্যার একই বিল, এ মাসের।
     *
     * @return list<Listing>
     */
    public static function listings(): array
    {
        if (! self::on()) {
            return [];
        }

        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->endOfMonth()->toDateString();

        $top = self::bills()->whereBetween('pur_bills.trx_date', [$from, $to])
            ->selectRaw('pur_bills.supplier_id, COALESCE(SUM(pur_bills.total), 0) as amount')
            ->groupBy('pur_bills.supplier_id')
            ->orderByDesc('amount')->orderBy('pur_bills.supplier_id')
            ->limit(5)->get();

        $ids = $top->pluck('supplier_id')->map(fn ($id) => (int) $id)->all();

        $late = $ids === [] ? collect() : self::scoped(DB::table('pur_receipts')
            ->join('pur_orders', 'pur_orders.id', '=', 'pur_receipts.purchase_order_id')
            ->where('pur_receipts.company_id', CompanyContext::id()), 'pur_receipts')
            ->whereBetween('pur_receipts.trx_date', [$from, $to])
            ->whereNotNull('pur_orders.expected_on')
            ->whereColumn('pur_receipts.trx_date', '>', 'pur_orders.expected_on')
            ->whereIn('pur_receipts.supplier_id', $ids)
            ->selectRaw('pur_receipts.supplier_id, COUNT(*) as n')
            ->groupBy('pur_receipts.supplier_id')
            ->pluck('n', 'supplier_id');

        $returned = $ids === [] ? collect() : self::returns()
            ->whereBetween('pur_returns.trx_date', [$from, $to])
            ->whereIn('pur_returns.supplier_id', $ids)
            ->selectRaw('pur_returns.supplier_id, COALESCE(SUM(pur_returns.total), 0) as amount')
            ->groupBy('pur_returns.supplier_id')
            ->pluck('amount', 'supplier_id');

        $names = Supplier::query()->whereKey($ids)->get()->keyBy('id');

        $rows = $top->map(fn ($r) => [
            'name' => $names->get((int) $r->supplier_id)?->name() ?? '—',
            'bought' => (string) $r->amount,
            'late' => (int) ($late[$r->supplier_id] ?? 0),
            'returned' => (string) ($returned[$r->supplier_id] ?? '0'),
        ]);

        return [new Listing(
            label: __('supplier::dashboard.performance'),
            columns: [
                ['key' => 'name', 'label' => __('supplier::field.name'),
                    'render' => fn (array $r) => $r['name']],
                ['key' => 'bought', 'label' => __('supplier::dashboard.trend_bought'), 'width' => '9rem',
                    'render' => fn (array $r) => Money::format($r['bought'])],
                ['key' => 'late', 'label' => __('supplier::dashboard.late_deliveries'), 'width' => '8rem',
                    'render' => fn (array $r) => (string) $r['late']],
                ['key' => 'returned', 'label' => __('supplier::dashboard.trend_returned'), 'width' => '9rem',
                    'render' => fn (array $r) => Money::format($r['returned'])],
            ],
            rows: $rows,
            empty: __('supplier::dashboard.no_purchase_this_month'),
            href: route('supplier.index'),
        )];
    }

    private static function on(): bool
    {
        return (bool) config('abos.dashboards_v2') && (bool) auth()->user()?->can('purchase.bill.view');
    }

    /** নিশ্চিত (বা বন্ধ) ক্রয় বিল — এই কোম্পানির, মোছা বাদ, হেডারে বাছা শাখার */
    private static function bills(): Builder
    {
        return self::scoped(DB::table('pur_bills')->where('pur_bills.company_id', CompanyContext::id()), 'pur_bills');
    }

    /** নিশ্চিত (বা বন্ধ) ক্রয় ফেরত — একই তিন ছাঁকনি */
    private static function returns(): Builder
    {
        return self::scoped(DB::table('pur_returns')->where('pur_returns.company_id', CompanyContext::id()), 'pur_returns');
    }

    /** পাকা কাগজ, মোছা বাদ, আর হেডারে বাছা শাখা ([[DataScope::inView()]]) */
    private static function scoped(Builder $query, string $table): Builder
    {
        $query->whereIn($table.'.status', DocumentStatus::POSTED)->whereNull($table.'.deleted_at');

        return app(DataScope::class)->inView($query, $table.'.branch_id');
    }

    /**
     * মাস ধরে মোট — `Y-m` => টাকা।
     *
     * @param  array{0: string, 1: string}  $range
     * @return \Illuminate\Support\Collection<string, string>
     */
    private static function byMonth(Builder $query, string $table, array $range): \Illuminate\Support\Collection
    {
        $expr = "DATE_FORMAT({$table}.trx_date, '%Y-%m')";

        return $query->whereBetween($table.'.trx_date', $range)
            ->selectRaw("{$expr} as ym, COALESCE(SUM({$table}.total), 0) as amount")
            ->groupByRaw($expr)
            ->pluck('amount', 'ym');
    }
}
