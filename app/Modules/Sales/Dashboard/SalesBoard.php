<?php

declare(strict_types=1);

namespace App\Modules\Sales\Dashboard;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Metrics\SalesAnalytics;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ বিক্রয়ের ড্যাশবোর্ডের অঙ্ক — মালিকের চূড়ান্ত নমুনা H8JXFPzh8m7zpsQSedmPDT (10 নভেম্বর 2026 রাত: "100% স্যাম্পলের সাথে মিল রেখে")।
 *
 * ⓘ বিক্রির অঙ্ক বিক্রয়ের নিজের হিসাব থেকে ([[SalesAnalytics]]) — বিক্রয়ের রিপোর্ট আর ড্যাশবোর্ড দুই কথা বলে না।
 * ⓘ সমস্যা কেন্দ্রের রং জরুরিতা ধরে — লাল জরুরি, কমলা আজকের মধ্যে, সোনালি এ সপ্তাহে, সবুজ ঠিক আছে।
 */
final class SalesBoard
{
    /** দেরি করা চালান — অনুমোদনের পর এত দিনের বেশি বিল হয়নি */
    public const LATE_DAYS = 3;

    /** হারানো গ্রাহক — আগে কিনত, এত দিন কেনেনি */
    public const LOST_DAYS = 60;

    public function __construct(private readonly SalesAnalytics $analytics) {}

    /**
     * ⭐ সমস্যা কেন্দ্র — দশটা ঘর, নমুনার ক্রমে।
     *
     * @return list<array{key: string, n: string, detail: string, note: string, sev: string, href: string}>
     */
    public function problems(): array
    {
        $today = Carbon::today();
        $month = $today->copy()->startOfMonth()->toDateString();
        $out = [];

        // 1 — বাকির সীমায় আটকে: হিসাবের ধাপে আটকে রাখা ডেলিভারি অর্ডার
        $held = DeliveryOrder::query()->where('status', DeliveryOrderStatus::ACCOUNTS_HELD)->get(['total']);
        $out[] = ['key' => 'credit', 'n' => (string) $held->count(), 'detail' => __('sales::board.p.credit_detail', ['value' => self::short(self::sum($held, 'total'))]),
            'note' => '', 'sev' => $held->isEmpty() ? 'ok' : 'urgent', 'href' => self::link('sales.delivery_order.index', ['status' => DeliveryOrderStatus::ACCOUNTS_HELD])];

        // 2 — দেরি করা চালান: অনুমোদিত, 3 দিনের বেশি, এখনো বিল হয়নি
        $late = DeliveryOrder::query()->whereIn('status', [DeliveryOrderStatus::ACCOUNTS_APPROVED, DeliveryOrderStatus::DEPOT_CHECK])
            ->whereNotNull('approved_at')->where('approved_at', '<', now()->subDays(self::LATE_DAYS))->get(['total']);
        $out[] = ['key' => 'late', 'n' => (string) $late->count(), 'detail' => __('sales::board.p.late_detail', ['n' => self::LATE_DAYS, 'value' => self::short(self::sum($late, 'total'))]),
            'note' => '', 'sev' => $late->isEmpty() ? 'ok' : 'urgent', 'href' => self::link('sales.delivery_order.index')];

        // 3 — মাল নেই বলে আটকে: খোলা আদেশে যে পণ্যের বিক্রিযোগ্য মজুদ শূন্য বা কম; আর 30 দিনের হারানো বিক্রি
        $open = app(\App\Core\Contracts\StockDemand::class)->openByProduct();
        $stock = app(\App\Modules\Inventory\Services\StockFacts::class)->snapshot()->keyBy('id');
        $short = collect($open)->filter(fn (array $o, $id) => bccomp((string) ($stock[(int) $id]?->getAttribute('available_qty') ?? '0'), '0', 4) <= 0);
        $lost = array_sum(array_map('floatval', app(\App\Core\Contracts\StockDemand::class)->lostByProduct($today->copy()->subDays(30)->toDateString())));
        $out[] = ['key' => 'stock', 'n' => (string) $short->count(), 'detail' => __('sales::board.p.stock_detail', ['value' => self::short((string) $short->sum(fn ($o) => (float) $o['value']))]),
            'note' => __('sales::board.p.stock_note', ['value' => self::short((string) $lost)]), 'sev' => $short->isEmpty() ? 'ok' : 'urgent', 'href' => self::link('sales.order.index')];

        // 4 — ছাড়ের বিল: এ মাসের পাকা বিলে ছাড় (মালিক: যেকোনো ছাড়ে মালিকের হাত লাগে)
        $discounted = SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->where('trx_date', '>=', $month)->where('discount', '>', 0)->get(['discount']);
        $out[] = ['key' => 'discount', 'n' => (string) $discounted->count(), 'detail' => __('sales::board.p.discount_detail', ['value' => self::short(self::sum($discounted, 'discount'))]),
            'note' => '', 'sev' => $discounted->isEmpty() ? 'ok' : 'today', 'href' => self::link('sales.invoice.index')];

        // 5 — তালিকার নিচে দাম: এ মাসের বিলের লাইনে দামের তালিকা থেকে নিচে (price_variance < 0, বিলের সময় দাম-নিয়মের নিজের মাপ)
        $below = SalesInvoiceLine::query()->whereHas('invoice', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED)->where('trx_date', '>=', $month))
            ->where('price_variance', '<', 0)->get(['qty', 'rate', 'price_variance']);
        $gap = $below->reduce(fn (float $c, $l) => $c + (float) $l->qty * (float) $l->rate * abs((float) $l->price_variance) / max(1, 100 - abs((float) $l->price_variance)), 0.0);
        $out[] = ['key' => 'price', 'n' => (string) $below->count(), 'detail' => __('sales::board.p.price_detail', ['value' => self::short((string) $gap)]),
            'note' => '', 'sev' => $below->isEmpty() ? 'ok' : 'today', 'href' => self::link('sales.invoice.index')];

        // 6 — 90+ দিনের বাকি: গ্রাহকের বাকির বয়সের রিপোর্টের একই অঙ্ক
        [$oldShops, $oldValue] = $this->overdue90();
        $out[] = ['key' => 'aged', 'n' => (string) $oldShops, 'detail' => self::short($oldValue), 'note' => '',
            'sev' => $oldShops === 0 ? 'ok' : 'today', 'href' => self::link('customer.report.show', ['slug' => 'ageing'])];

        // 7 — হারানো গ্রাহক: আগের 4 মাসে কিনেছে, শেষ 60 দিনে কেনেনি
        [$lostShops, $lostMonthly] = $this->lostCustomers();
        $out[] = ['key' => 'lost', 'n' => (string) $lostShops, 'detail' => __('sales::board.p.lost_detail', ['n' => self::LOST_DAYS]),
            'note' => __('sales::board.p.lost_note', ['value' => self::short($lostMonthly)]), 'sev' => $lostShops === 0 ? 'ok' : 'week', 'href' => self::link('customer.index')];

        // 8 — পতনশীল ডিস্ট্রিবিউটর: শেষ 2 মাস বনাম তার আগের 2 মাস, সবচেয়ে বড় পতন (আগে মাসে অন্তত ৳50,000)
        $falling = $this->fallingCustomer();
        $out[] = ['key' => 'falling', 'n' => $falling === null ? '0' : $falling['pct'].'%', 'detail' => $falling['name'] ?? '', 'note' => '',
            'sev' => $falling === null ? 'ok' : 'week', 'href' => self::link('customer.index')];

        // 9 — মেয়াদ ফুরানো উদ্ধৃতি: পাঠানো/অনুমোদিত, এ সপ্তাহে মেয়াদ শেষ, অর্ডার হয়নি
        $quotes = SalesQuotation::query()->whereIn('status', [SalesQuotation::SENT, SalesQuotation::APPROVED, SalesQuotation::SUBMITTED])
            ->whereBetween('valid_until', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])->get(['total']);
        $out[] = ['key' => 'quotes', 'n' => (string) $quotes->count(), 'detail' => self::short(self::sum($quotes, 'total')), 'note' => '',
            'sev' => $quotes->isEmpty() ? 'ok' : 'week', 'href' => self::link('sales.quotation.index')];

        // 10 — রুটের দোকান কম কিনছে: SR-এর রুটের দোকানের কত শতাংশ 30 দিনে অর্ডার দিয়েছে (productive coverage), সবচেয়ে কম
        $route = $this->weakestRoute();
        $out[] = ['key' => 'route', 'n' => $route === null ? '—' : $route['pct'].'%', 'detail' => $route['name'] ?? '', 'note' => '',
            'sev' => $route === null || $route['pct'] >= 70 ? 'ok' : 'week', 'href' => self::link('sales.route.index')];

        return $out;
    }

    /**
     * ⭐ এক নজরে — বিক্রি, আদায়, আদায়ের হার, বিল: আজ, এ মাস, এ অর্থবছর; গত বছরের এ সময়ের সাথে; আর চারটা ছোট ঘর।
     *
     * @return array<string, mixed>
     */
    public function glance(): array
    {
        $today = Carbon::today()->toDateString();
        $ranges = [
            'today' => [$today, $today],
            'month' => [Carbon::today()->startOfMonth()->toDateString(), $today],
            'year' => [\App\Core\Support\FiscalYearStart::on(Carbon::today()), $today],
        ];
        $cols = [];

        foreach ($ranges as $k => [$from, $to]) {
            $totals = $this->analytics->totals($from, $to);
            $collected = $this->analytics->collection($from, $to);
            $cols[$k] = ['sales' => (string) $totals['net'], 'collected' => $collected, 'count' => (int) $totals['count'],
                'rate' => bccomp((string) $totals['net'], '0', 4) > 0 ? (int) round((float) $collected / (float) $totals['net'] * 100) : null];
        }

        $lastYear = $this->analytics->totals(Carbon::parse($ranges['year'][0])->subYear()->toDateString(), Carbon::today()->subYear()->toDateString());
        $yoy = bccomp((string) $lastYear['net'], '0', 4) > 0 ? (int) round(((float) $cols['year']['sales'] - (float) $lastYear['net']) / (float) $lastYear['net'] * 100) : null;

        $undelivered = SalesOrder::query()->open()->get(['total']);
        $month = $ranges['month'];
        $shops = SalesOrder::query()->where('status', '<>', DocumentStatus::CANCELLED)->whereBetween('trx_date', $month)->distinct()->count('customer_id');
        $quotes = SalesQuotation::query()->whereBetween('trx_date', $month)->count();
        $converted = SalesQuotation::query()->whereBetween('trx_date', $month)->where('status', SalesQuotation::CONVERTED)->count();

        return [
            'cols' => $cols,
            'yoy' => $yoy,
            'undelivered' => [$undelivered->count(), self::sum($undelivered, 'total')],
            'shops' => $shops,
            'avg' => $cols['month']['count'] > 0 ? bcdiv($cols['month']['sales'], (string) $cols['month']['count'], 0) : null,
            'quote_rate' => $quotes > 0 ? (int) round($converted * 100 / $quotes) : null,
        ];
    }

    /** @return array{0: int, 1: string} 90 দিনের বেশি পুরনো বাকি — কয়টা গ্রাহক, কত টাকা */
    private function overdue90(): array
    {
        $engine = app(ReportEngine::class);

        if (! in_array('customer.ageing', $engine->keys(), true)) {
            return [0, '0'];
        }

        $rows = collect($engine->run('customer.ageing', ['to' => Carbon::today()->toDateString()], 1, 100000)->rows)->map(fn ($r) => (array) $r)
            ->filter(fn (array $r) => bccomp((string) ($r['bucket_90'] ?? '0'), '0', 4) > 0);

        return [$rows->count(), $rows->reduce(fn (string $c, array $r) => bcadd($c, (string) $r['bucket_90'], 4), '0')];
    }

    /** @return array{0: int, 1: string} হারানো গ্রাহক — কয়টা, আর আগে তারা মাসে গড়ে কত কিনত */
    private function lostCustomers(): array
    {
        $edge = Carbon::today()->subDays(self::LOST_DAYS)->toDateString();
        $since = Carbon::today()->subDays(self::LOST_DAYS + 120)->toDateString();
        $recent = SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->where('trx_date', '>=', $edge)->distinct()->pluck('customer_id');
        $before = SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', [$since, $edge])
            ->whereNotIn('customer_id', $recent->all() ?: [0])->groupBy('customer_id')->selectRaw('customer_id, SUM(subtotal - discount) as v')->get();

        return [$before->count(), bcdiv((string) $before->sum('v'), '4', 4)];
    }

    /** @return array{name: string, pct: int}|null শেষ 2 মাসে আগের 2 মাসের চেয়ে সবচেয়ে বেশি কমেছে এমন বড় গ্রাহক */
    private function fallingCustomer(): ?array
    {
        $mid = Carbon::today()->subMonthsNoOverflow(2)->toDateString();
        $start = Carbon::today()->subMonthsNoOverflow(4)->toDateString();
        $sum = fn (string $from, string $to) => SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', [$from, $to])
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(subtotal - discount) as v')->pluck('v', 'customer_id');
        $before = $sum($start, $mid);
        $now = $sum(Carbon::parse($mid)->addDay()->toDateString(), Carbon::today()->toDateString());
        $worst = null;

        foreach ($before as $id => $v) {
            if ((float) $v / 2 < 50000) {
                continue;
            }

            $pct = (int) round(((float) ($now[$id] ?? 0) - (float) $v) / (float) $v * 100);

            if ($pct < -20 && ($worst === null || $pct < $worst['pct'])) {
                $worst = ['id' => (int) $id, 'pct' => $pct];
            }
        }

        if ($worst === null) {
            return null;
        }

        return ['name' => (string) (\App\Modules\Customer\Models\Customer::query()->find($worst['id'])?->name() ?? ''), 'pct' => $worst['pct']];
    }

    /** @return array{name: string, pct: int}|null রুট-পরিকল্পনার যে রুটের দোকান সবচেয়ে কম কিনেছে (30 দিন) */
    private function weakestRoute(): ?array
    {
        $plans = \App\Modules\Sales\Models\RouteVisit::query()->with(['route', 'user'])->get();
        $since = Carbon::today()->subDays(30)->toDateString();
        $worst = null;

        foreach ($plans->unique('route_id') as $plan) {
            $shops = \App\Modules\Customer\Models\Customer::query()->where('location_id', $plan->route_id)->pluck('id');

            if ($shops->isEmpty()) {
                continue;
            }

            $bought = SalesOrder::query()->where('status', '<>', DocumentStatus::CANCELLED)->where('trx_date', '>=', $since)->whereIn('customer_id', $shops->all())->distinct()->count('customer_id');
            $pct = (int) round($bought * 100 / $shops->count());

            if ($worst === null || $pct < $worst['pct']) {
                $worst = ['name' => trim(($plan->user?->name ?? '').' · '.($plan->route?->drillLabel() ?? ''), ' ·'), 'pct' => $pct];
            }
        }

        return $worst;
    }

    private static function sum(Collection $rows, string $column): string
    {
        return $rows->reduce(fn (string $c, $r) => bcadd($c, (string) ($r->{$column} ?? '0'), 4), '0');
    }

    /** ⓘ নমুনার রূপ: কোটির ওপরে "৳X.XX কো", লাখের ওপরে "৳X.X লা", নিচে হাজারে "৳Xk" — ইংরেজি অঙ্ক */
    public static function short(string $amount): string
    {
        $a = ltrim($amount, '-');
        $sign = bccomp($amount, '0', 4) < 0 ? '−' : '';

        if (bccomp($a, '10000000', 4) >= 0) {
            return $sign.'৳'.bcdiv($a, '10000000', 2).' '.__('sales::board.crore');
        }

        if (bccomp($a, '100000', 4) >= 0) {
            return $sign.'৳'.bcdiv($a, '100000', 1).' '.__('sales::board.lakh');
        }

        if (bccomp($a, '1000', 4) >= 0) {
            return $sign.'৳'.bcdiv($a, '1000', 0).'k';
        }

        return $sign.'৳'.bcadd($a, '0', 0);
    }

    /** @param  array<string, mixed>  $params */
    private static function link(string $route, array $params = []): string
    {
        return Route::has($route) ? route($route, $params) : '#';
    }
}
