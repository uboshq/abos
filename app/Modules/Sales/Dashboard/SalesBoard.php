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
        // ⓘ সারি 1 আর সারি 4 দুই জায়গাতেই লাগে — এক অনুরোধে একবারই গোনা (অনুরোধ, কোম্পানি ও দেখা শাখা ধরে; পরের অনুরোধে নতুন করে)
        $key = spl_object_id(request()).'.'.\App\Core\Support\CompanyContext::id().'.'.(\App\Core\Support\ViewedBranch::one() ?? 'all');

        return self::$problemsOnce[$key] ??= $this->countProblems();
    }

    /** @var array<string, list<array<string, string>>> */
    private static array $problemsOnce = [];

    /** @return list<array{key: string, n: string, detail: string, note: string, sev: string, href: string}> */
    private function countProblems(): array
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

    /** সরাসরি বিক্রির খরচের খাত — গাড়ি ভাড়া, জ্বালানি, পুরনো পরিবহন; লোডিং, আনলোডিং, হাম্মালি */
    public const DIRECT_COST_CODES = ['vehicle' => ['5217', '5216', '5204'], 'labour' => ['5218', '5219', '5220']];

    /**
     * ⭐ সারি 2 — বিক্রি থেকে নিট লাভ · এ মাস: বিক্রি − কেনা দাম = গ্রস লাভ → সরাসরি বিক্রির খরচ → SR কমিশন → অফিস ও অন্যান্য খরচ → অন্যান্য আয় → নিট লাভ।
     * ⓘ বিক্রি = নিট বিক্রি ([[SalesAnalytics::totals()]]); গ্রস লাভ = পাকা বিলের (উপমোট − ছাড়) − বিক্রীত মালের খরচ ([[StockDemand::grossProfit()]] — মজুদের ড্যাশবোর্ডের একই অঙ্ক)।
     * ⓘ সরাসরি খরচ খাতা থেকে (গাড়ি ভাড়া ও জ্বালানি, লোড-আনলোড-হাম্মালি); অফিস ও অন্যান্য = সব পরিচালন খরচ − সরাসরি খরচ; অন্যান্য আয় = সব আয় − নিট বিক্রির খাত।
     * ⓘ SR কমিশন খাতায় বসে না — কমিশনের হিসাব ([[CommissionEngine::preview()]]) এ মাসের পাকা বিলের ওপর; তাই নিট লাভ = খাতার নিট লাভ − এই কমিশন।
     *
     * @return array<string, string>
     */
    public function profitLadder(): array
    {
        $from = Carbon::today()->startOfMonth();
        $to = Carbon::today();
        $sales = (string) $this->analytics->totals($from->toDateString(), $to->toDateString())['net'];
        $gross = app(\App\Core\Contracts\StockDemand::class)->grossProfit($from->toDateString(), $to->toDateString());
        $sum = fn (array $codes) => array_reduce($codes, fn (string $c, string $code) => bcadd($c, self::moved($code, $from, $to), 4), '0');
        $vehicle = $sum(self::DIRECT_COST_CODES['vehicle']);
        $labour = $sum(self::DIRECT_COST_CODES['labour']);
        $direct = bcadd($vehicle, $labour, 4);
        $opex = self::moved(\App\Modules\Accounts\Services\StandardChart::OPERATING_EXPENSES, $from, $to);
        $income = self::moved(\App\Modules\Accounts\Services\StandardChart::INCOME, $from, $to);
        $expense = self::moved('5000', $from, $to);
        $commission = $this->commission($from, $to);

        return [
            'sales' => $sales,
            'cogs' => bcsub($sales, $gross, 4),
            'gross' => $gross,
            'vehicle' => $vehicle,
            'labour' => $labour,
            'direct' => $direct,
            'commission' => $commission,
            'other_cost' => bcsub($opex, $direct, 4),
            'other_income' => bcsub($income, $sum(\App\Modules\Accounts\Services\StandardChart::NET_SALES), 4),
            'net' => bcsub(bcsub($income, $expense, 4), $commission, 4),
        ];
    }

    /**
     * ⭐ মাসের লক্ষ্য — SR-দের লক্ষ্যের যোগ বনাম অর্জন ([[SalesAnalytics::targetAchievement()]]), মাসের কত দিন পেরিয়েছে, এই গতিতে মাস শেষে কত,
     * বাকি দিনে দিনে কত দরকার আর দিনে কত হচ্ছে।
     *
     * @return array{target: ?string, achieved: string, pct: ?int, days_pct: int, projected: string, need: ?string, pace: string}
     */
    public function monthTarget(): array
    {
        $today = Carbon::today();
        $t = $this->analytics->targetAchievement($today);
        $days = $today->daysInMonth;
        $pace = bcdiv($t['achieved'], (string) $today->day, 4);
        $left = $days - $today->day;
        $gap = $t['target'] === null ? '0' : bcsub($t['target'], $t['achieved'], 4);

        return [
            'target' => $t['target'],
            'achieved' => $t['achieved'],
            'pct' => $t['percent'] === null ? null : (int) round((float) $t['percent']),
            'days_pct' => (int) round($today->day * 100 / $days),
            'projected' => bcmul($pace, (string) $days, 4),
            'need' => $t['target'] === null || $left <= 0 ? null : (bccomp($gap, '0', 4) > 0 ? bcdiv($gap, (string) $left, 4) : '0'),
            'pace' => $pace,
        ];
    }

    /**
     * ⭐ সেরা 5 ও দুর্বল 5 পণ্য — এ মাসের সবচেয়ে বেশি বিক্রি (টাকায়), আর গত মাসের একই দিনগুলোর তুলনায় সবচেয়ে বেশি কমেছে (শতাংশে)।
     *
     * @return array{top: list<array{name: string, value: string}>, falling: list<array{name: string, pct: int}>}
     */
    public function products(): array
    {
        $today = Carbon::today();
        $from = $today->copy()->startOfMonth();
        $lastFrom = $from->copy()->subMonthNoOverflow();
        $lastTo = $lastFrom->copy()->addDays($today->day - 1)->min($lastFrom->copy()->endOfMonth());
        $by = fn (Carbon $a, Carbon $b) => SalesInvoiceLine::query()
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', [$a->toDateString(), $b->toDateString()]))
            ->groupBy('product_id')->selectRaw('product_id, SUM(amount) as v')->pluck('v', 'product_id');
        $now = $by($from, $today);
        $last = $by($lastFrom, $lastTo);
        $names = \App\Modules\Inventory\Models\Product::query()->whereIn('id', array_unique([...$now->keys()->all(), ...$last->keys()->all()]) ?: [0])->get()
            ->mapWithKeys(fn ($p) => [(int) $p->id => $p->name()]);

        return [
            'top' => $now->sortDesc()->take(5)->map(fn ($v, $id) => ['name' => (string) ($names[(int) $id] ?? ''), 'value' => bcadd((string) $v, '0', 4)])->values()->all(),
            'falling' => $last->filter(fn ($v) => (float) $v > 0)
                ->map(fn ($v, $id) => (int) round(((float) ($now[$id] ?? 0) - (float) $v) / (float) $v * 100))
                ->filter(fn ($pct) => $pct < 0)->sort()->take(5)
                ->map(fn ($pct, $id) => ['name' => (string) ($names[(int) $id] ?? ''), 'pct' => $pct])->values()->all(),
        ];
    }

    /**
     * ⭐ সারি 3 — লক্ষ্যের পথে: এ মাসের দিনে দিনে জমা নিট বিক্রি ([[SalesMetrics::netByDay()]] — বিক্রির রিপোর্টের একই অঙ্ক),
     * গত বছরের একই মাসের জমা, লক্ষ্য (মাসের SR লক্ষ্যের যোগ) আর এই গতিতে মাস শেষের পূর্বাভাস।
     *
     * @return array{days: int, today: int, now: list<float>, last_year: list<float>, target: ?string, forecast: string}
     */
    public function cumulative(): array
    {
        $today = Carbon::today();
        $from = $today->copy()->startOfMonth();
        $days = $today->daysInMonth;
        $run = function (array $byDay, Carbon $start, int $upto): array {
            $out = [];
            $sum = 0.0;

            for ($i = 0; $i < $upto; $i++) {
                $sum += (float) ($byDay[$start->copy()->addDays($i)->toDateString()] ?? 0);
                $out[] = $sum;
            }

            return $out;
        };
        $now = $run(\App\Modules\Sales\Metrics\SalesMetrics::netByDay($from->toDateString(), $today->toDateString()), $from, $today->day);
        $lyFrom = $from->copy()->subYear();
        $lastYear = $run(\App\Modules\Sales\Metrics\SalesMetrics::netByDay($lyFrom->toDateString(), $lyFrom->copy()->endOfMonth()->toDateString()), $lyFrom, $lyFrom->daysInMonth);
        $done = end($now) ?: 0.0;

        return [
            'days' => $days,
            'today' => $today->day,
            'now' => $now,
            'last_year' => $lastYear,
            'target' => $this->analytics->targetAchievement($today)['target'],
            'forecast' => number_format($done / $today->day * $days, 4, '.', ''),
        ];
    }

    /**
     * ⭐ বিক্রি কেন বাড়ল — দাম · পরিমাণ · মিশ্রণ (PVM): এ মাসের 1 থেকে আজ বনাম গত মাসের একই দিন, পণ্য ধরে (প্রচলিত ভাঙন, যোগফল হুবহু মেলে):
     * পরিমাণ = (মোট পরিমাণের বদল) × আগের গড় দাম; মিশ্রণ = Σ আগের দাম × এখনকার পরিমাণ − এখনকার মোট পরিমাণ × আগের গড় দাম;
     * দাম = Σ (এখনকার দাম − আগের দাম) × এখনকার পরিমাণ; নতুন পণ্য = আগে বিক্রি হয়নি এমন পণ্যের বিক্রি; বাদ পড়া পণ্য = এবার বিক্রি হয়নি এমনের আগের বিক্রি।
     * ⓘ সব পণ্যের মাত্রায় — গ্রাহকের হিসাব এখানে মেশানো হয় না (মেশালে একই টাকা দুইবার গোনা হত)।
     *
     * @return array{before: string, volume: string, price: string, mix: string, new: string, dropped: string, after: string, from_label: string, to_label: string}
     */
    public function pvm(): array
    {
        $today = Carbon::today();
        $from = $today->copy()->startOfMonth();
        $lastFrom = $from->copy()->subMonthNoOverflow();
        $lastTo = $lastFrom->copy()->addDays($today->day - 1)->min($lastFrom->copy()->endOfMonth());
        $lines = fn (Carbon $a, Carbon $b) => SalesInvoiceLine::query()
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', [$a->toDateString(), $b->toDateString()]))
            ->groupBy('product_id')->selectRaw('product_id, SUM(qty) as q, SUM(amount) as v')->get()->keyBy('product_id');
        $p0 = $lines($lastFrom, $lastTo);
        $p1 = $lines($from, $today);
        $common = $p0->keys()->intersect($p1->keys());
        $q0 = $common->sum(fn ($id) => (float) $p0[$id]->q);
        $q1 = $common->sum(fn ($id) => (float) $p1[$id]->q);
        $r0c = $common->sum(fn ($id) => (float) $p0[$id]->v);
        $avg0 = $q0 > 0 ? $r0c / $q0 : 0.0;
        $price0 = fn ($id) => (float) $p0[$id]->q > 0 ? (float) $p0[$id]->v / (float) $p0[$id]->q : 0.0;
        $atOld = $common->sum(fn ($id) => $price0($id) * (float) $p1[$id]->q);
        $f = fn (float $v) => number_format($v, 4, '.', '');
        $months = __('sales::board.months_short');

        return [
            'before' => $f((float) $p0->sum('v')),
            'volume' => $f(($q1 - $q0) * $avg0),
            'mix' => $f($atOld - $q1 * $avg0),
            'price' => $f($common->sum(fn ($id) => (float) $p1[$id]->v) - $atOld),
            'new' => $f($p1->except($common->all())->sum(fn ($r) => (float) $r->v)),
            'dropped' => $f(-$p0->except($common->all())->sum(fn ($r) => (float) $r->v)),
            'after' => $f((float) $p1->sum('v')),
            'from_label' => $months[$lastFrom->month].' 1–'.$lastTo->day,
            'to_label' => $months[$from->month].' 1–'.$today->day,
        ];
    }

    /**
     * ⭐ সারি 4 — গ্রাহক ধরে রাখা: মাসে চালু দোকান (মাসে অন্তত একবার পাকা বিল) শেষ 5 মাস + এ মাস; ধরে রাখা = গত মাসে কেনা দোকানের কত শতাংশ
     * এ মাসেও কিনেছে; নতুন = এ মাসেই প্রথম কেনা; ফিরে এল = গত মাসে কেনেনি অথচ আগে কিনেছিল, এ মাসে কিনেছে; 60 দিন কেনেনি = হারানো।
     *
     * @return array{months: list<array{month: int, shops: int}>, kept: ?int, new: int, back: int, lost: int}
     */
    public function retention(): array
    {
        $buyers = fn (Carbon $a, Carbon $b) => SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)
            ->whereBetween('trx_date', [$a->toDateString(), $b->toDateString()])->distinct()->pluck('customer_id');
        $months = [];

        for ($i = 5; $i >= 0; $i--) {
            $m = Carbon::today()->startOfMonth()->subMonthsNoOverflow($i);
            $months[] = ['month' => (int) $m->month, 'shops' => $buyers($m, $i === 0 ? Carbon::today() : $m->copy()->endOfMonth())->count()];
        }

        $thisFrom = Carbon::today()->startOfMonth();
        $now = $buyers($thisFrom, Carbon::today());
        $last = $buyers($thisFrom->copy()->subMonthNoOverflow(), $thisFrom->copy()->subDay());
        $before = SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->where('trx_date', '<', $thisFrom->copy()->subMonthNoOverflow()->toDateString())
            ->whereIn('customer_id', $now->all() ?: [0])->distinct()->pluck('customer_id');

        return [
            'months' => $months,
            'kept' => $last->isEmpty() ? null : (int) round($last->intersect($now)->count() * 100 / $last->count()),
            'new' => $now->diff($last)->diff($before)->count(),
            'back' => $now->diff($last)->intersect($before)->count(),
            'lost' => $this->lostCustomers()[0],
        ];
    }

    /**
     * ⭐ আদায়ের দক্ষতা · SR ধরে — বিলের SR (বিল যিনি লিখেছেন) ধরে: দক্ষতা = এ মাসে আদায় ÷ (এ মাসে আদায় + এখনো মেয়াদ পেরোনো বাকি),
     * আর DSO = এখনকার বাকি ÷ শেষ 90 দিনের বিক্রি × 90। ⓘ CEI-এর প্রচলিত সংক্ষিপ্ত রূপ — মাস-শুরুর বাকির ছবি খাতায় SR ধরে রাখা নেই।
     *
     * @return array{rows: list<array{name: string, pct: ?int, dso: ?int}>, total: ?int}
     */
    public function collectionEffectiveness(): array
    {
        $month = Carbon::today()->startOfMonth()->toDateString();
        $since = Carbon::today()->subDays(89)->toDateString();
        $invoices = SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->withCollected()->where('trx_date', '>=', Carbon::today()->subYear()->toDateString())->get();
        $applied = \App\Modules\Sales\Models\CollectionApplication::query()
            ->whereHas('collection', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED)->where('trx_date', '>=', $month))
            ->whereIn('target_invoice_id', $invoices->pluck('id')->all() ?: [0])
            ->groupBy('target_invoice_id')->selectRaw('target_invoice_id, SUM(amount) as v')->pluck('v', 'target_invoice_id');
        $names = \App\Models\User::query()->whereIn('id', $invoices->pluck('created_by')->filter()->unique()->all() ?: [0])->pluck('name', 'id');
        $today = Carbon::today()->toDateString();
        $rows = [];
        $tc = 0.0;
        $to = 0.0;

        foreach ($invoices->groupBy('created_by') as $sr => $list) {
            $collected = $list->sum(fn ($i) => (float) ($applied[$i->id] ?? 0));
            $overdue = $list->filter(fn ($i) => $i->due_on !== null && substr((string) $i->due_on, 0, 10) < $today)->sum(fn ($i) => max(0, (float) $i->dueAmount()));
            $due = $list->sum(fn ($i) => max(0, (float) $i->dueAmount()));
            $sold = $list->filter(fn ($i) => substr((string) $i->trx_date, 0, 10) >= $since)->sum(fn ($i) => (float) $i->total);

            if ($collected + $overdue <= 0) {
                continue;
            }

            $tc += $collected;
            $to += $overdue;
            $rows[] = ['name' => (string) ($names[$sr] ?? __('sales::board.r4.nobody')), 'pct' => (int) round($collected * 100 / ($collected + $overdue)),
                'dso' => $sold > 0 ? (int) round($due / $sold * 90) : null];
        }

        usort($rows, fn (array $a, array $b) => $b['pct'] <=> $a['pct']);

        return ['rows' => array_slice($rows, 0, 7), 'total' => $tc + $to > 0 ? (int) round($tc * 100 / ($tc + $to)) : null];
    }

    /**
     * ⭐ অর্ডার থেকে টাকা (ফানেল) · এ মাস — অর্ডার এসেছে → অনুমোদিত (ডেলিভারি অর্ডার হিসাবের অনুমোদন পেরিয়েছে) → চালান রওনা → বিল হয়েছে → টাকা এসেছে।
     *
     * @return array<string, string>
     */
    public function orderToCash(): array
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();
        $range = [$from, $to];

        return [
            'ordered' => (string) SalesOrder::query()->where('status', '<>', DocumentStatus::CANCELLED)->whereBetween('trx_date', $range)->sum('total'),
            'approved' => (string) DeliveryOrder::query()->whereIn('status', [DeliveryOrderStatus::ACCOUNTS_APPROVED, DeliveryOrderStatus::DEPOT_CHECK, DeliveryOrderStatus::INVOICED])->whereBetween('trx_date', $range)->sum('total'),
            'dispatched' => (string) \App\Modules\Sales\Models\DeliveryChallan::query()->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', $range)->sum('total'),
            'invoiced' => (string) SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', $range)->sum('total'),
            'collected' => $this->analytics->collection($from, $to),
        ];
    }

    /**
     * ⭐ ফেরত ও ছাড় · কারণ ধরে · এ মাস — ফেরত কারণের কোড ধরে (বড় তিনটা), আর ছাড় তিন রকম: বিলের লাইনের ছাড়, প্রচারের ছাড়, আদায়ে নগদ ছাড় (খাতা)।
     *
     * @return array{rows: list<array{name: string, value: string, kind: string}>, return_pct: ?string, discount_pct: ?string}
     */
    public function returnsAndDiscounts(): array
    {
        $from = Carbon::today()->startOfMonth();
        $range = [$from->toDateString(), Carbon::today()->toDateString()];
        $returns = \App\Modules\Sales\Models\SalesReturn::query()->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', $range)
            ->groupBy('reason_code_id')->selectRaw('reason_code_id, SUM(subtotal) as v')->get();
        $reasons = \App\Modules\MasterData\Models\ReasonCode::query()->whereIn('id', $returns->pluck('reason_code_id')->filter()->all() ?: [0])->get()
            ->mapWithKeys(fn ($r) => [(int) $r->id => $r->drillLabel()]);
        $rows = $returns->sortByDesc(fn ($r) => (float) $r->v)->take(3)
            ->map(fn ($r) => ['name' => (string) ($reasons[(int) $r->reason_code_id] ?? __('sales::board.r4.no_reason')), 'value' => bcadd((string) $r->v, '0', 4), 'kind' => 'return'])->values()->all();
        $lines = SalesInvoiceLine::query()->whereHas('invoice', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', $range))
            ->selectRaw('COALESCE(SUM(discount), 0) as d, COALESCE(SUM(promotion_discount), 0) as p')->first();
        $cash = self::moved(\App\Modules\Accounts\Services\StandardChart::COLLECTION_DISCOUNT, $from, Carbon::today());
        $lineDiscount = bcsub((string) ($lines->d ?? '0'), (string) ($lines->p ?? '0'), 4);

        foreach ([['volume', bccomp($lineDiscount, '0', 4) > 0 ? $lineDiscount : '0'], ['promotion', (string) ($lines->p ?? '0')], ['cash', ltrim($cash, '-')]] as [$k, $v]) {
            $rows[] = ['name' => (string) __('sales::board.r4.discount_'.$k), 'value' => bcadd($v, '0', 4), 'kind' => 'discount'];
        }

        $sales = (float) $this->analytics->totals($range[0], $range[1])['net'];
        $ret = collect($rows)->where('kind', 'return')->sum(fn ($r) => (float) $r['value']);
        $disc = collect($rows)->where('kind', 'discount')->sum(fn ($r) => (float) $r['value']);

        return ['rows' => $rows, 'return_pct' => $sales > 0 ? number_format($ret / $sales * 100, 1, '.', '') : null, 'discount_pct' => $sales > 0 ? number_format($disc / $sales * 100, 1, '.', '') : null];
    }

    /**
     * ⭐ সারি 5 — ব্র্যান্ড ধরে বিক্রি (ট্রিম্যাপ) · এ মাস: বিলের লাইনের টাকা পণ্যের ব্র্যান্ড ধরে, বড় ছয়টা আর বাকি "অন্যান্য";
     * ঘর সারি-ভাগে (slice-and-dice)। ⓘ ব্র্যান্ডের লক্ষ্য খাতায় নেই — শতাংশ মানে বিক্রির ভাগ।
     *
     * @return list<array{name: string, value: string, pct: int, x: float, y: float, w: float, h: float}>
     */
    public function brands(): array
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $rows = SalesInvoiceLine::query()->join('inv_products', 'inv_products.id', '=', 'sal_invoice_lines.product_id')
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED)->where('trx_date', '>=', $from))
            ->groupBy('inv_products.brand_id')->selectRaw('inv_products.brand_id as brand, SUM(sal_invoice_lines.amount) as v')->get()
            ->filter(fn ($r) => (float) $r->v > 0)->sortByDesc(fn ($r) => (float) $r->v)->values();
        $names = \App\Modules\MasterData\Models\Brand::query()->whereIn('id', $rows->pluck('brand')->filter()->all() ?: [0])->get()->mapWithKeys(fn ($b) => [(int) $b->id => $b->drillLabel()]);
        $top = $rows->take(6)->map(fn ($r) => ['name' => (string) ($names[(int) $r->brand] ?? __('sales::board.r5.no_brand')), 'value' => bcadd((string) $r->v, '0', 4)])->all();
        $rest = $rows->slice(6)->sum(fn ($r) => (float) $r->v);

        if ($rest > 0) {
            $top[] = ['name' => (string) __('sales::board.r5.others'), 'value' => number_format($rest, 4, '.', '')];
        }

        $total = array_sum(array_map(fn ($g) => (float) $g['value'], $top));

        if ($total <= 0) {
            return [];
        }

        $out = [];
        $box = ['x' => 0.0, 'y' => 0.0, 'w' => 100.0, 'h' => 100.0];
        $left = $total;
        $vertical = true;

        foreach ($top as $i => $g) {
            $share = $left > 0 ? (float) $g['value'] / $left : 1.0;
            $last = $i === count($top) - 1;
            $g['pct'] = (int) round((float) $g['value'] / $total * 100);

            if ($vertical) {
                $w = $last ? $box['w'] : $box['w'] * $share;
                $out[] = $g + ['x' => $box['x'], 'y' => $box['y'], 'w' => $w, 'h' => $box['h']];
                $box['x'] += $w;
                $box['w'] -= $w;
            } else {
                $h = $last ? $box['h'] : $box['h'] * $share;
                $out[] = $g + ['x' => $box['x'], 'y' => $box['y'], 'w' => $box['w'], 'h' => $h];
                $box['y'] += $h;
                $box['h'] -= $h;
            }

            $left -= (float) $g['value'];
            $vertical = ! $vertical;
        }

        return $out;
    }

    /**
     * ⭐ বিক্রির পথ ধরে · এ মাস — গ্রাহকের বিক্রির পথ (sales channel) ধরে পাকা বিলের টাকা; আর নগদ বনাম বাকি — বিলের শর্তে:
     * শেষ দিন বিলের দিনের পরে = বাকিতে, নাহলে নগদ।
     *
     * @return array{parts: list<array{name: string, value: string}>, total: string, cash_pct: ?int}
     */
    public function channels(): array
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $invoices = SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->where('trx_date', '>=', $from)->with('customer')->get(['id', 'customer_id', 'total', 'trx_date', 'due_on']);
        $channels = \App\Modules\MasterData\Models\SalesChannel::query()->get()->mapWithKeys(fn ($c) => [(int) $c->id => $c->drillLabel()]);
        $parts = $invoices->groupBy(fn ($i) => (int) ($i->customer?->channel_id ?? 0))
            ->map(fn ($list, $id) => ['name' => (string) ($channels[(int) $id] ?? __('sales::board.r5.no_channel')), 'value' => number_format($list->sum(fn ($i) => (float) $i->total), 4, '.', '')])
            ->sortByDesc(fn ($p) => (float) $p['value'])->values()->all();
        $total = $invoices->sum(fn ($i) => (float) $i->total);
        $credit = $invoices->filter(fn ($i) => $i->due_on !== null && substr((string) $i->due_on, 0, 10) > substr((string) $i->trx_date, 0, 10))->sum(fn ($i) => (float) $i->total);

        return ['parts' => $parts, 'total' => number_format($total, 4, '.', ''), 'cash_pct' => $total > 0 ? (int) round(($total - $credit) / $total * 100) : null];
    }

    /**
     * ⭐ কখন অর্ডার আসে — বার × সময়: শেষ 8 সপ্তাহের অর্ডার লেখার সময় (শনি থেকে শুক্র × সকাল 8টা থেকে রাত 10টা, দুই ঘণ্টার ঘর)।
     *
     * @return array{grid: array<int, array<int, int>>, max: int}
     */
    public function orderHours(): array
    {
        $grid = array_fill(0, 7, array_fill(0, 7, 0));
        SalesOrder::query()->where('created_at', '>=', Carbon::today()->subWeeks(8))->get(['created_at'])
            ->each(function ($o) use (&$grid): void {
                $at = Carbon::parse($o->created_at)->setTimezone(config('app.timezone'));
                $hour = (int) $at->format('G');

                if ($hour < 8 || $hour >= 22) {
                    return;
                }

                $grid[($at->dayOfWeek + 1) % 7][intdiv($hour - 8, 2)]++;
            });

        return ['grid' => $grid, 'max' => max(1, ...array_map('max', $grid))];
    }

    /**
     * ⭐ সারি 6 — SR-দের লক্ষ্য (বুলেট): মাসের লক্ষ্য আছে এমন SR — অর্জন ÷ লক্ষ্য; কালো দাগ = মাসের কত দিন পেরিয়েছে (আজ পর্যন্ত থাকার কথা)।
     * ⓘ অর্জন লক্ষ্যের পাতার একই হিসাবে ([[SalesTargetService::achievedByUser()]]), ভ্যাট বাদে।
     *
     * @return array{rows: list<array{name: string, pct: int}>, due: int}
     */
    public function srTargets(): array
    {
        $first = Carbon::today()->startOfMonth();
        $targets = \App\Modules\Sales\Models\SalesTarget::query()->forMonth($first)->pluck('amount', 'user_id');
        $achieved = app(\App\Modules\Sales\Services\SalesTargetService::class)->achievedByUser($first, $first->copy()->endOfMonth());
        $names = \App\Models\User::query()->whereIn('id', $targets->keys()->all() ?: [0])->pluck('name', 'id');
        $rows = $targets->filter(fn ($t) => (float) $t > 0)
            ->map(fn ($t, $id) => ['name' => (string) ($names[(int) $id] ?? ''), 'pct' => (int) round((float) ($achieved[(int) $id] ?? 0) / (float) $t * 100)])
            ->sortByDesc('pct')->take(7)->values()->all();

        return ['rows' => $rows, 'due' => (int) round(Carbon::today()->day * 100 / Carbon::today()->daysInMonth)];
    }

    /**
     * ⭐ SR কমিশন · এ মাস এ পর্যন্ত — বিলের SR ধরে বিক্রি, কমিশন ([[CommissionEngine::preview()]]) আর হার; বড় সাতজন + মোট।
     *
     * @return array{rows: list<array{name: string, sales: string, rate: ?string, commission: string}>, sales: string, commission: string}
     */
    public function srCommission(): array
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();
        $key = 'abos.sales-board-sr-commission.'.\App\Core\Support\CompanyContext::id().'.'.(\App\Core\Support\ViewedBranch::one() ?? 'all').'.'.$to;

        return \Illuminate\Support\Facades\Cache::remember($key, 600, function () use ($from, $to): array {
            $engine = app(\App\Modules\Sales\Services\CommissionEngine::class);
            $by = [];

            foreach (SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', [$from, $to])->get() as $invoice) {
                $sr = (int) $invoice->created_by;
                $by[$sr] ??= ['sales' => '0', 'commission' => '0'];
                $by[$sr]['sales'] = bcadd($by[$sr]['sales'], bcsub((string) $invoice->subtotal, (string) $invoice->discount, 4), 4);
                $by[$sr]['commission'] = bcadd($by[$sr]['commission'], (string) $engine->preview($invoice)['total'], 4);
            }

            $names = \App\Models\User::query()->whereIn('id', array_keys($by) ?: [0])->pluck('name', 'id');
            $rows = collect($by)->map(fn (array $v, $id) => ['name' => (string) ($names[(int) $id] ?? __('sales::board.r4.nobody')), 'sales' => $v['sales'],
                'rate' => bccomp($v['sales'], '0', 4) > 0 ? bcdiv(bcmul($v['commission'], '100', 4), $v['sales'], 1) : null, 'commission' => $v['commission']])
                ->sortByDesc(fn (array $r) => (float) $r['sales'])->take(7)->values()->all();

            return ['rows' => $rows, 'sales' => array_reduce($by, fn (string $c, array $v) => bcadd($c, $v['sales'], 4), '0'),
                'commission' => array_reduce($by, fn (string $c, array $v) => bcadd($c, $v['commission'], 4), '0')];
        });
    }

    /**
     * ⭐ সেরা দুই SR — রাডার (এ মাসে বিক্রিতে প্রথম দুজন): ছয় মাপ 0–100 — লক্ষ্যের অর্জন, আদায়ের দক্ষতা, দোকান (দুজনের বেশিজনের তুলনায়),
     * গড় অর্ডার (তুলনায়), ফেরত কম (100 − ফেরতের হার × 10), বৃদ্ধি (গত মাসের একই দিনের তুলনায়, ±50% = 0–100)।
     *
     * @return list<array{name: string, values: list<int>}>
     */
    public function topTwo(): array
    {
        $from = Carbon::today()->startOfMonth();
        $to = Carbon::today();
        $lastFrom = $from->copy()->subMonthNoOverflow();
        $lastTo = $lastFrom->copy()->addDays($to->day - 1)->min($lastFrom->copy()->endOfMonth());
        $now = SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', [$from->toDateString(), $to->toDateString()])->get(['id', 'created_by', 'customer_id', 'subtotal', 'discount']);
        $last = SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', [$lastFrom->toDateString(), $lastTo->toDateString()])
            ->groupBy('created_by')->selectRaw('created_by, SUM(subtotal - discount) as v')->pluck('v', 'created_by');
        $returns = \App\Modules\Sales\Models\SalesReturn::query()->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('sales_invoice_id')->with('invoice:id,created_by')->get(['sales_invoice_id', 'subtotal']);
        $bySr = $now->groupBy('created_by')->map(fn ($l) => ['sales' => $l->sum(fn ($i) => (float) $i->subtotal - (float) $i->discount), 'shops' => $l->pluck('customer_id')->unique()->count(), 'bills' => $l->count()])
            ->sortByDesc('sales');
        $top = $bySr->take(2);

        if ($top->isEmpty()) {
            return [];
        }

        $targets = $this->srTargets();
        $cei = collect($this->collectionEffectiveness()['rows'])->keyBy('name');
        $names = \App\Models\User::query()->whereIn('id', $top->keys()->all())->pluck('name', 'id');
        $maxShops = max(1, $top->max('shops'));
        $maxAvg = max(1.0, $top->max(fn ($s) => $s['bills'] > 0 ? $s['sales'] / $s['bills'] : 0));
        $clamp = fn (float $v) => (int) round(max(0, min(100, $v)));

        return $top->map(function (array $s, $id) use ($names, $targets, $cei, $maxShops, $maxAvg, $last, $returns, $clamp): array {
            $name = (string) ($names[(int) $id] ?? __('sales::board.r4.nobody'));
            $ret = $returns->filter(fn ($r) => (int) ($r->invoice?->created_by ?? 0) === (int) $id)->sum(fn ($r) => (float) $r->subtotal);
            $prev = (float) ($last[$id] ?? 0);

            return ['name' => $name, 'values' => [
                $clamp((float) (collect($targets['rows'])->firstWhere('name', $name)['pct'] ?? 0)),
                $clamp((float) ($cei[$name]['pct'] ?? 0)),
                $clamp($s['shops'] / $maxShops * 100),
                $clamp(($s['bills'] > 0 ? $s['sales'] / $s['bills'] : 0) / $maxAvg * 100),
                $clamp(100 - ($s['sales'] > 0 ? $ret / $s['sales'] * 1000 : 0)),
                $clamp($prev > 0 ? 50 + ($s['sales'] - $prev) / $prev * 100 : 50),
            ]];
        })->values()->all();
    }

    /**
     * ⭐ ডিস্ট্রিবিউটর — আকার বনাম বৃদ্ধি: এ প্রান্তিকের বড় 12 গ্রাহক — x = বিক্রি, y = গত প্রান্তিকের একই দিনের তুলনায় বৃদ্ধি, বৃত্ত = বাকি।
     *
     * @return list<array{name: string, sales: string, growth: ?int, due: string}>
     */
    public function distributors(): array
    {
        $from = Carbon::today()->firstOfQuarter();
        $to = Carbon::today();
        $lastFrom = $from->copy()->subMonthsNoOverflow(3);
        $lastTo = $lastFrom->copy()->addDays($from->diffInDays($to));
        $sum = fn (Carbon $a, Carbon $b) => SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', [$a->toDateString(), $b->toDateString()])
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(subtotal - discount) as v')->pluck('v', 'customer_id');
        $now = $sum($from, $to)->sortDesc()->take(12);
        $before = $sum($lastFrom, $lastTo);
        $due = SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->withCollected()->whereIn('customer_id', $now->keys()->all() ?: [0])->get()
            ->groupBy('customer_id')->map(fn ($l) => $l->sum(fn ($i) => max(0, (float) $i->dueAmount())));
        $names = \App\Modules\Customer\Models\Customer::query()->whereIn('id', $now->keys()->all() ?: [0])->get()->mapWithKeys(fn ($c) => [(int) $c->id => $c->name()]);

        return $now->map(fn ($v, $id) => ['name' => (string) ($names[(int) $id] ?? ''), 'sales' => bcadd((string) $v, '0', 4),
            'growth' => (float) ($before[$id] ?? 0) > 0 ? (int) round(((float) $v - (float) $before[$id]) / (float) $before[$id] * 100) : null,
            'due' => number_format((float) ($due[$id] ?? 0), 4, '.', '')])->values()->all();
    }

    /**
     * ⭐ সারি 7 — শাখা ধরে · 5 মাস + এ মাস (স্তূপ), শীর্ষ গ্রাহক (পারেটো, এ মাস), এলাকা ধরে (এ মাস) — বিক্রয়ের নিজের হিসাবে ([[SalesAnalytics]])।
     *
     * @return array{branches: list<array{month: int, parts: array<string, string>}>, names: list<string>, pareto: list<array{name: string, value: string, cum: int}>, areas: list<array{name: string, value: string}>}
     */
    public function spread(): array
    {
        $months = [];
        $names = [];

        for ($i = 5; $i >= 0; $i--) {
            $m = Carbon::today()->startOfMonth()->subMonthsNoOverflow($i);
            $rows = $this->analytics->byBranch($m->toDateString(), ($i === 0 ? Carbon::today() : $m->copy()->endOfMonth())->toDateString());
            $parts = [];

            foreach ($rows as $r) {
                $parts[$r['name']] = (string) $r['amount'];
                $names[$r['name']] = ($names[$r['name']] ?? 0) + (float) $r['amount'];
            }

            $months[] = ['month' => (int) $m->month, 'parts' => $parts];
        }

        arsort($names);
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();
        $customers = collect($this->analytics->topCustomers($from, $to))->take(12)->values();
        $net = (float) $this->analytics->totals($from, $to)['net'];
        $cum = 0.0;
        $pareto = $customers->map(function ($c) use (&$cum, $net): array {
            $cum += (float) $c['amount'];

            return ['name' => (string) $c['name'], 'value' => (string) $c['amount'], 'cum' => $net > 0 ? (int) round($cum / $net * 100) : 0];
        })->all();
        $areas = collect($this->analytics->byTerritory($from, $to)['rows'] ?? [])->take(12)
            ->map(fn ($r) => ['name' => (string) $r['name'], 'value' => (string) $r['amount']])->values()->all();

        return ['branches' => $months, 'names' => array_slice(array_keys($names), 0, 6), 'pareto' => $pareto, 'areas' => $areas];
    }

    /** এ মাসের পাকা বিলের SR কমিশন — কমিশনের হিসাব থেকে, 10 মিনিট মনে রাখা (বিল ধরে গোনা ভারী) */
    private function commission(Carbon $from, Carbon $to): string
    {
        $key = 'abos.sales-board-commission.'.\App\Core\Support\CompanyContext::id().'.'.(\App\Core\Support\ViewedBranch::one() ?? 'all').'.'.$to->toDateString();

        return \Illuminate\Support\Facades\Cache::remember($key, 600, function () use ($from, $to): string {
            $engine = app(\App\Modules\Sales\Services\CommissionEngine::class);

            return SalesInvoice::query()->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', [$from->toDateString(), $to->toDateString()])
                ->get()->reduce(fn (string $c, SalesInvoice $i) => bcadd($c, (string) $engine->preview($i)['total'], 4), '0');
        });
    }

    /** একটা খাতের (ও নিচের) সময়ের নড়াচড়া, দেখার নাগালে */
    private static function moved(string $code, Carbon $from, Carbon $to): string
    {
        $account = \App\Modules\Accounts\Services\StandardChart::find($code);

        if ($account === null) {
            return '0';
        }

        $ids = app(\App\Core\Services\DataScope::class)->viewBranchIds(auth()->user());
        $before = $from->copy()->subDay()->toDateString();
        $total = '0';

        foreach ($ids === null ? [null] : $ids as $branch) {
            $total = bcadd($total, bcsub($account->balanceOn($to->toDateString(), $branch), $account->balanceOn($before, $branch), 4), 4);
        }

        return $total;
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
