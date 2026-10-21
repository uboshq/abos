<?php

declare(strict_types=1);

namespace App\Modules\Sales\Metrics;

use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerMetrics;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesTarget;
use App\Modules\Sales\Services\OrderTracking;
use App\Modules\Sales\Services\SalesTargetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * বিক্রয়ের পর্দার প্রতিটা সংখ্যা — একটা পদ্ধতি, একটা সংজ্ঞা (NEXUS §৪)।
 *
 * ── ⭐ কেন নতুন কোনো যোগফল এখানে জন্মায় না ────────────────────────────
 * "বিক্রয়" মানে [[SalesMetrics::invoiceTotal()]], "আদায়" মানে
 * [[SalesMetrics::collectionTotal()]], "বাজারে পাওনা" মানে
 * [[CustomerMetrics::dues()]], "মাল যায়নি" মানে [[OrderTracking::counts()]],
 * আর টার্গেটের অর্জন [[SalesTargetService]]। ফোনের "আজ কেমন গেল"
 * ([[DashboardTodayController]]) ঠিক এগুলোই ডাকে — তাই ফোন, হোম পর্দা আর
 * এই পর্দা একই মানুষকে একই অঙ্ক বলে। ⛔ এখানে আবার `sum('total')` লিখলে
 * সেটা হত এই সংখ্যার পঞ্চম সংজ্ঞা — ঠিক যেটা [[SalesMetrics]] ঠেকাতে লেখা।
 *
 * ── যা সত্যিই নতুন ──────────────────────────────────────────────────
 * ভাগগুলো (কোন গ্রাহক, কোন পণ্য, কোন এরিয়া, কোন শাখা, কে বিল কেটেছেন)
 * আর ছাড়-ভ্যাট-মোট দামের ঘরগুলো — এগুলোর আগে কোনো সংজ্ঞা ছিল না। ⓘ তবু
 * প্রতিটা ভাগ **একই ছাঁকনিতে** দাঁড়ায় ([[posted()]]): তাই ভাগগুলোর যোগফল
 * আর বিক্রয়ের কার্ড একই অঙ্ক — পরীক্ষাটা ঠিক সেটাই মেলায়।
 *
 * ── ⚠️ শাখার দেয়াল ─────────────────────────────────────────────────
 * সব কোয়েরি মডেল দিয়ে (`SalesInvoice::query()`), `DB::table()` নয় — যাতে
 * [[ScopedToUserBranch]] নিজে থেকেই বসে, [[SalesMetrics]]-এর হুবহু।
 * ⛔ রিপোর্ট ইঞ্জিনের রিপোর্টগুলো (`sales.by_customer`…) এখানে ধার করা হয়নি
 * ঠিক এই কারণে: ওরা `DB::table()`, আর শাখায় সীমিত মানুষের সীমা মানে না।
 */
final class SalesAnalytics
{
    /** র‍্যাঙ্কিংয়ের সারি — দশটা; পুরোটা রিপোর্টে। */
    public const TOP = 10;

    /** দৈনিক ধারার জানালা — শেষ তারিখ থেকে পেছনে। */
    public const DAYS = 30;

    /** মাসিক ধারার জানালা। */
    public const MONTHS = 12;

    public function __construct(
        private readonly CustomerMetrics $customers,
        private readonly OrderTracking $tracking,
        private readonly SalesTargetService $targets,
    ) {}

    // ── কার্ড ──────────────────────────────────────────────────────────

    /**
     * বিলের মোট দাম, ছাড়, ভ্যাট আর নিট — একই বিলগুলো থেকে।
     *
     * ⓘ নিট = [[SalesMetrics::net()]] — বিল বাদ ফেরত, VAT বাদে (IFRS 15; ২০ অক্টোবর ২০২৬), দ্বিতীয় যোগফল নয়। দাম (`subtotal`) ছাড়ের আগে, ভ্যাটের আগে।
     * ⚠️ নিট ≠ দাম − ছাড় + ভ্যাট সবসময়: দামের ভেতরের ভ্যাটে (`is_inclusive`)
     * ভ্যাট আলাদা লেখা হয় কিন্তু মোটে যোগ হয় না — তাই নিটটা বিলের নিজের
     * `total` থেকেই, তিন ঘর জোড়া দিয়ে নয়।
     *
     * @return array{count: int, gross: string, discount: string, tax: string, net: string}
     */
    public function totals(string $from, string $to): array
    {
        $row = $this->posted($from, $to)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(subtotal), 0) as gross, '
                .'COALESCE(SUM(discount), 0) as discount, COALESCE(SUM(tax), 0) as tax')
            ->toBase()
            ->first();

        return [
            'count' => (int) ($row->n ?? 0),
            'gross' => Money::of($row->gross ?? '0'),
            'discount' => Money::of($row->discount ?? '0'),
            'tax' => Money::of($row->tax ?? '0'),
            // ⭐ নিট বিক্রি — VAT আর ফেরত বাদে (IFRS 15; fe, ২০ অক্টোবর ২০২৬), হোম আর ফোনের একই উৎস ([[SalesMetrics::net()]])
            'net' => SalesMetrics::net($from, $to),
        ];
    }

    /**
     * সরাসরি বিক্রয় — যে বিলের মাল কোনো আদেশ ছাড়াই গেছে।
     *
     * ── কেন "আদেশহীন চালান" ─────────────────────────────────────────
     * মালিকের নিয়ম (২১ সেপ্টেম্বর ২০২৬): বিলের দরজা দুইটা — আদেশ, আর
     * সরাসরি বিক্রয়। সরাসরি বিক্রয় চালান কাটে আদেশ ছাড়া
     * ([[DirectSaleService]]), তাই চালানের `sales_order_id` খালি থাকাটাই
     * তার চিহ্ন। ⓘ নতুন কোনো কলাম লাগেনি, আর পুরনো বিলগুলোও ঠিক ভাগে পড়ে।
     *
     * @return array{count: int, amount: string}
     */
    public function directSales(string $from, string $to): array
    {
        $row = $this->posted($from, $to)
            ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')
                ->from('sal_invoice_lines as il')
                ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
                ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
                ->whereColumn('il.sales_invoice_id', 'sal_invoices.id')
                ->whereNull('c.sales_order_id'))
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as amount')
            ->toBase()
            ->first();

        return ['count' => (int) ($row->n ?? 0), 'amount' => Money::of($row->amount ?? '0')];
    }

    /** সময়কালে নিশ্চিত হওয়া আদেশ কয়টা — খসড়া আর বাতিল বাদ। */
    public function orders(string $from, string $to): int
    {
        return SalesOrder::query()->posted()->whereBetween('trx_date', [$from, $to])->count();
    }

    /**
     * মাল এখনো পুরো যায়নি এমন আদেশ — আদেশ-অনুসরণের পাতার হুবহু সংখ্যা।
     *
     * ⓘ সময়কাল মানে না, ইচ্ছাকৃত: "মাল যায়নি" একটা **অবস্থা**, ঘটনা নয় —
     * গত মাসের আদেশ আজও ঝুলে থাকলে সেটাই আজকের কাজ। ⚠️ সংখ্যাটা
     * [[OrderTracking::counts()]] থেকেই, যাতে কার্ডে ক্লিক করে ট্যাবে
     * একই সংখ্যা দেখা যায়।
     */
    public function pendingDelivery(): int
    {
        $counts = $this->tracking->counts(null);

        return $counts[OrderTracking::PLACED] + $counts[OrderTracking::PARTIAL];
    }

    /** @return array{count: int, amount: string} */
    public function returns(string $from, string $to): array
    {
        $row = SalesReturn::query()->posted()
            ->whereBetween('trx_date', [$from, $to])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as amount')
            ->toBase()
            ->first();

        return ['count' => (int) ($row->n ?? 0), 'amount' => Money::of($row->amount ?? '0')];
    }

    /** আদায় — [[SalesMetrics::collectionTotal()]], আর কিছু নয়। */
    public function collection(string $from, string $to): string
    {
        return SalesMetrics::collectionTotal($from, $to);
    }

    /**
     * বাজারে পাওনা, শেষ তারিখ পর্যন্ত — ফোনের `dues`-এর একই উৎস।
     *
     * @return array{amount: string, shops: int}
     */
    public function receivable(User $user, string $asOf): array
    {
        return $this->customers->dues($user, $asOf);
    }

    /**
     * এই সময়ে পাওনা কত বাড়ল — বিক্রি নয়, গ্রাহকের পাওনার নড়াচড়া ([[SalesMetrics::receivableMovement()]], fe, ২১ অক্টোবর ২০২৬):
     * বিল (VAT সহ) − ফেরত (VAT সহ, ফেরতের তারিখে) − আদায়। ⓘ গ্রাহক VAT-ও দেন, তাই এখানে VAT থাকে; এটা নিট বিক্রি নয়।
     * ⚠️ ঋণাত্মক হতে পারে, আর সেটা সুখবর: পাওনা কমল — পুরনো বকেয়া এই সময়ে উঠে এসেছে। ⛔ আগে বিলের মোট − আদায়:
     * ফেরত ধরা হত না, তাই ফেরত আসা মাল নতুন বাকি দেখাত।
     */
    public function outstanding(string $from, string $to): string
    {
        return SalesMetrics::receivableMovement($from, $to);
    }

    /**
     * টার্গেটের অর্জন — শেষ তারিখের মাস, টার্গেটের পাতার হুবহু।
     *
     * ⓘ অর্জন কেবল তাঁদের, যাঁদের টার্গেট বসানো — যাঁর টার্গেট নেই তাঁর
     * বিক্রয় যোগ করলে শতাংশটা ফুলে উঠত। ⚠️ অর্জন ভ্যাট বাদে
     * ([[SalesTargetService::achievedByUser()]]), তাই নিট বিক্রয়ের সমান নয়।
     *
     * @return array{month: string, target: ?string, achieved: string, percent: ?string}
     */
    public function targetAchievement(Carbon $day): array
    {
        $first = $day->copy()->startOfMonth();

        $targets = SalesTarget::query()->forMonth($first)->pluck('amount', 'user_id');

        if ($targets->isEmpty()) {
            return ['month' => $first->format('Y-m'), 'target' => null, 'achieved' => '0.0000', 'percent' => null];
        }

        $byUser = $this->targets->achievedByUser($first, $first->copy()->endOfMonth());

        $target = '0';
        $achieved = '0';

        foreach ($targets as $userId => $amount) {
            $target = bcadd($target, (string) $amount, 4);
            $achieved = bcadd($achieved, (string) ($byUser[(int) $userId] ?? '0'), 4);
        }

        return [
            'month' => $first->format('Y-m'),
            'target' => $target,
            'achieved' => $achieved,
            'percent' => $this->targets->percent($achieved, $target),
        ];
    }

    // ── ধারা ───────────────────────────────────────────────────────────

    /**
     * দিনে দিনে বিক্রয় — শেষ তারিখ থেকে ৩০ দিন পেছনে, এক কোয়েরিতে।
     *
     * ⓘ খালি দিনও থাকে, শূন্য নিয়ে — বাদ দিলে বন্ধের দিনটা অদৃশ্য হত
     * ([[SalesWidgets::lastSevenDays()]]-এর একই কারণ)।
     *
     * @return list<array{label: string, date: string, value: string}>
     */
    public function daily(Carbon $end): array
    {
        $start = $end->copy()->subDays(self::DAYS - 1);

        // ⭐ দিনের নিট বিক্রি — VAT আর ফেরত বাদে, ফেরত নিজের দিনে ([[SalesMetrics::netByDay()]]; fe, ২১ অক্টোবর ২০২৬)
        $byDay = SalesMetrics::netByDay($start->toDateString(), $end->toDateString());

        $out = [];

        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            $key = $day->toDateString();
            $out[] = ['label' => $day->format('d'), 'date' => $key, 'value' => Money::of($byDay[$key] ?? '0')];
        }

        return $out;
    }

    /**
     * মাসে মাসে বিক্রয়, দাম আর ছাড় — এক কোয়েরিতে; ছাড়ের বিশ্লেষণও এটাই।
     *
     * ⚠️ প্রতিটা মাসের নিট [[SalesMetrics::net()]]-এর সমান হতে
     * বাধ্য — একই ছাঁকনি, কেবল মাস ধরে ভাগ। পরীক্ষাটা মাসে মাসে মেলায়।
     *
     * @return list<array{label: string, ym: string, from: string, to: string, count: int, gross: string, discount: string, net: string, discount_percent: ?string}>
     */
    public function monthly(Carbon $end): array
    {
        $months = $this->monthWindow($end);
        $expr = "DATE_FORMAT(trx_date, '%Y-%m')";

        $rows = $this->posted($months[0]['from'], $end->toDateString())
            ->selectRaw("{$expr} as ym, COUNT(*) as n, COALESCE(SUM(subtotal), 0) as gross, "
                .'COALESCE(SUM(discount), 0) as discount, COALESCE(SUM(total), 0) as net')
            ->groupByRaw($expr)
            ->toBase()
            ->get()
            ->keyBy('ym');

        // ⭐ মাসের নিট বিক্রি — VAT আর ফেরত বাদে, [[SalesMetrics::netByMonth()]] (দুই GROUP BY); দাম আর ছাড় আগের মতো বিল থেকে
        $nets = SalesMetrics::netByMonth($months[0]['from'], $end->toDateString());

        return array_map(function (array $m) use ($rows, $nets): array {
            $row = $rows->get($m['ym']);
            $gross = Money::of($row->gross ?? '0');
            $discount = Money::of($row->discount ?? '0');

            return $m + [
                'count' => (int) ($row->n ?? 0),
                'gross' => $gross,
                'discount' => $discount,
                'net' => $nets[$m['ym']]['net'] ?? '0.0000',
                'discount_percent' => self::share($discount, $gross),
            ];
        }, $months);
    }

    /**
     * মাসে মাসে আদায় — প্রতিটা মাস [[SalesMetrics::collectionTotal()]]।
     *
     * ⓘ বারোটা ডাক, কারণ আদায়ের সংজ্ঞা দুই উৎসের (আদায় + রসিদ ভাউচার),
     * আর মাস ধরে আলাদা করে লিখলে সেটা দ্বিতীয় সংজ্ঞা হত।
     *
     * @return array<string, string> ym => টাকা
     */
    public function monthlyCollection(Carbon $end): array
    {
        $out = [];

        foreach ($this->monthWindow($end) as $m) {
            $out[$m['ym']] = SalesMetrics::collectionTotal($m['from'], $m['to']);
        }

        return $out;
    }

    /**
     * মাসে মাসে ফেরত — এক কোয়েরিতে।
     *
     * @return list<array{label: string, ym: string, from: string, to: string, count: int, amount: string}>
     */
    public function returnTrend(Carbon $end): array
    {
        $months = $this->monthWindow($end);
        $expr = "DATE_FORMAT(trx_date, '%Y-%m')";

        $rows = SalesReturn::query()->posted()
            ->whereBetween('trx_date', [$months[0]['from'], $end->toDateString()])
            ->selectRaw("{$expr} as ym, COUNT(*) as n, COALESCE(SUM(total), 0) as amount")
            ->groupByRaw($expr)
            ->toBase()
            ->get()
            ->keyBy('ym');

        return array_map(fn (array $m): array => $m + [
            'count' => (int) ($rows->get($m['ym'])->n ?? 0),
            'amount' => Money::of($rows->get($m['ym'])->amount ?? '0'),
        ], $months);
    }

    // ── ভাগ ────────────────────────────────────────────────────────────

    /**
     * সবচেয়ে বড় ক্রেতা — নিট বিক্রি ধরে (VAT আর ফেরত বাদে, [[SalesMetrics::netBy()]]; fe, ২১ অক্টোবর ২০২৬); ভাগ নিট বিক্রির মোটে।
     * ⓘ নাম "নাম · পয়েন্ট · শাখা" ([[PartyRegistry::labelsOf()]], মালিকের স্থায়ী নিয়ম)। ⓘ সমান হলে আগের id আগে।
     *
     * @return list<array{id: int, name: string, count: int, amount: string, share: ?string}>
     */
    public function topCustomers(string $from, string $to): array
    {
        $rows = collect(SalesMetrics::netBy($from, $to, 'sal_invoices.customer_id', 'sal_returns.customer_id'))
            ->map(fn (array $p, int|string $id): array => ['id' => (int) $id, 'count' => $p['count'], 'amount' => $p['net']])
            ->sort(fn (array $a, array $b): int => bccomp($b['amount'], $a['amount'], 4) ?: $a['id'] <=> $b['id'])
            ->take(self::TOP)
            ->values();

        $names = $rows->isEmpty() ? [] : app(PartyRegistry::class)->labelsOf($rows->map(fn (array $r) => ['customer', $r['id']])->all());
        $net = SalesMetrics::net($from, $to);

        return $rows->map(fn (array $r): array => [
            'id' => $r['id'],
            'name' => (string) ($names['customer:'.$r['id']] ?? '—'),
            'count' => $r['count'],
            'amount' => $r['amount'],
            'share' => self::share($r['amount'], $net),
        ])->all();
    }

    /**
     * সবচেয়ে বেশি বিকোনো পণ্য — নিট বিক্রি ধরে ([[SalesMetrics::netByProduct()]]; fe, ২১ অক্টোবর ২০২৬)।
     *
     * ⓘ পণ্যের নিট = বিলের সারির নিট ([[LineNetSale]]: VAT আর বিলের ছাড়ের ভাগ বাদে) − ফেরতের সারি, ফেরতের দিনে; পরিমাণও
     * ফেরত বাদে — পণ্যভিত্তিক রিপোর্টের নিয়ম ([[SalesReports::byProduct()]])। ⛔ আগে `amount − tax`: ফেরত আর বিলের ছাড় ধরা হত না।
     *
     * @return list<array{id: int, name: string, qty: string, amount: string}>
     */
    public function topProducts(string $from, string $to): array
    {
        $rows = SalesMetrics::netByProduct($from, $to)
            ->orderByRaw('SUM(x.amount) desc')
            ->orderBy('x.product_id')
            ->limit(self::TOP)
            ->get();

        return $this->withProductNames($rows);
    }

    /**
     * ধীর পণ্য — চালু পণ্যের মধ্যে সবচেয়ে কম বিকোনো, একটাও না বিকোনোগুলো আগে।
     *
     * ⚠️ `leftJoinSub`, সাধারণ join নয় — শূন্য বিক্রয়ের পণ্যটাই সবচেয়ে
     * জরুরি সারি, আর সাধারণ join ঠিক ওটাকেই বাদ দিত।
     *
     * @return list<array{id: int, name: string, qty: string, amount: string}>
     */
    public function slowProducts(string $from, string $to): array
    {
        // ⭐ নিট — ফেরতও বাদ ([[SalesMetrics::netByProduct()]]); যে পণ্যের সবটা ফেরত এসেছে সে শূন্যে, ধীরের তালিকায়
        $sold = SalesMetrics::netByProduct($from, $to);

        $rows = Product::query()
            ->where('inv_products.is_active', true)
            ->leftJoinSub($sold, 'sold', 'sold.product_id', '=', 'inv_products.id')
            ->orderByRaw('COALESCE(sold.qty, 0) asc')
            ->orderBy('inv_products.id')
            ->limit(self::TOP)
            ->get(['inv_products.*', 'sold.qty as sold_qty', 'sold.amount as sold_amount']);

        return $rows->map(fn (Product $p): array => [
            'id' => (int) $p->id,
            'name' => $p->code.' - '.$p->name(),
            'qty' => Money::of($p->getAttribute('sold_qty') ?? '0'),
            'amount' => Money::of($p->getAttribute('sold_amount') ?? '0'),
        ])->values()->all();
    }

    /**
     * কে কত বিক্রি করেছেন — নিট বিক্রি (VAT আর ফেরত বাদে), বিলের `created_by` ধরে; ফেরত কমে মূল বিলের মানুষের ঘর থেকে
     * ([[SalesMetrics::sellerOfReturnSql()]], [[SalespersonReports]]-এর একই নিয়ম; fe, ২১ অক্টোবর ২০২৬)। `count` = বিলের সংখ্যা।
     *
     * ⓘ টার্গেটও এই মানুষটাকেই মাপে (লক্ষ্যমাত্রার মাইগ্রেশনে লেখা
     * কারণ) — কর্মী তালিকা আর লগইনের কোনো বাঁধা সম্পর্ক নেই।
     *
     * @return list<array{id: ?int, name: string, count: int, amount: string, share: ?string}>
     */
    public function bySeller(string $from, string $to): array
    {
        $rows = collect(SalesMetrics::netBy($from, $to, 'sal_invoices.created_by', SalesMetrics::sellerOfReturnSql()))
            ->map(fn (array $p, int|string $id): array => [
                'id' => (string) $id === '' ? null : (int) $id, 'count' => $p['count'], 'amount' => $p['net'],
            ])
            ->sort(fn (array $a, array $b): int => bccomp($b['amount'], $a['amount'], 4) ?: (int) $a['id'] <=> (int) $b['id'])
            ->values();

        $names = User::query()->whereIn('id', $rows->pluck('id')->filter())->pluck('name', 'id');
        $net = SalesMetrics::net($from, $to);

        return $rows->map(fn (array $r): array => [
            'id' => $r['id'],
            'name' => (string) ($r['id'] === null ? __('sales::overview.nobody') : ($names[$r['id']] ?? __('sales::overview.nobody'))),
            'count' => $r['count'],
            'amount' => $r['amount'],
            'share' => self::share($r['amount'], $net),
        ])->all();
    }

    /**
     * এরিয়া ধরে — গ্রাহকের জায়গা থেকে মই বেয়ে উপরে।
     *
     * ⓘ ধাপটা `territory` (মালিকের নামে "এরিয়া"); সেটা বন্ধ থাকলে `area`
     * ("রিজিয়ন")। ⚠️ গাছটা একবারে আনা হয় আর মইটা PHP-তে বাওয়া হয় —
     * গ্রাহক ধরে ধরে `ancestors()` ডাকলে প্রতি ধাপে একটা কোয়েরি হত।
     *
     * @return array{level: string, rows: list<array{name: string, count: int, amount: string, share: ?string}>}
     */
    public function byTerritory(string $from, string $to): array
    {
        // ⓘ নিয়মটা [[SalesArea]]-য় — হোমের ছাঁকনিও ওটাই ডাকে, যাতে দুই উত্তর না হয় (৪ অক্টোবর ২০২৬)
        $level = SalesArea::level();

        // ⭐ গ্রাহক ধরে নিট বিক্রি (VAT আর ফেরত বাদে, [[SalesMetrics::netBy()]]; fe, ২১ অক্টোবর ২০২৬), তারপর গ্রাহকের জায়গার মই
        $perCustomer = SalesMetrics::netBy($from, $to, 'sal_invoices.customer_id', 'sal_returns.customer_id');

        $placeOf = Customer::query()->whereIn('id', array_keys($perCustomer))->pluck('location_id', 'id');
        $tree = SalesArea::tree();

        $buckets = [];

        foreach ($perCustomer as $customerId => $p) {
            $node = SalesArea::of($placeOf[$customerId] ?? null, $tree, $level);

            $key = $node?->id ?? 0;
            $buckets[$key] ??= ['name' => $node?->name() ?? __('sales::overview.unplaced'), 'count' => 0, 'amount' => '0'];
            $buckets[$key]['count'] += $p['count'];
            $buckets[$key]['amount'] = bcadd($buckets[$key]['amount'], $p['net'], 4);
        }

        return ['level' => $level, 'rows' => $this->ranked($buckets, $from, $to)];
    }

    /**
     * শাখা ধরে নিট বিক্রি (VAT আর ফেরত বাদে; ফেরত নিজের শাখায় আর তারিখে — [[SalesMetrics::netBy()]], fe, ২১ অক্টোবর ২০২৬) —
     * শাখায় সীমিত মানুষ কেবল নিজের শাখাগুলো (আর শাখাহীন বিল) পান।
     *
     * @return list<array{name: string, count: int, amount: string, share: ?string}>
     */
    public function byBranch(string $from, string $to): array
    {
        $rows = SalesMetrics::netBy($from, $to, 'sal_invoices.branch_id', 'sal_returns.branch_id');

        $names = Branch::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->whereIn('id', array_values(array_filter(array_map('intval', array_keys($rows)))))
            ->get()
            ->mapWithKeys(fn (Branch $b) => [$b->id => $b->name()]);

        $buckets = [];

        foreach ($rows as $branchId => $p) {
            $buckets[(int) $branchId] = [
                'name' => (string) ($names[(int) $branchId] ?? __('sales::overview.no_branch')),
                'count' => $p['count'],
                'amount' => $p['net'],
            ];
        }

        return $this->ranked($buckets, $from, $to);
    }

    // ── ভিত ────────────────────────────────────────────────────────────

    /**
     * খাতায় বসা বিল, সময়কালে — প্রতিটা ভাগের একটাই ভিত।
     *
     * ⓘ [[SalesMetrics::invoiceTotal()]]-এর হুবহু ছাঁকনি: `posted()` আর
     * `trx_date`। মডেল দিয়ে, তাই কোম্পানি আর শাখার দেয়াল নিজেই বসে।
     *
     * @return Builder<SalesInvoice>
     */
    private function posted(string $from, string $to): Builder
    {
        return SalesInvoice::query()->posted()->whereBetween('trx_date', [$from, $to]);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array{id: int, name: string, qty: string, amount: string}>
     */
    private function withProductNames($rows): array
    {
        $products = Product::query()->whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');

        return $rows->map(fn ($r): array => [
            'id' => (int) $r->product_id,
            'name' => ($p = $products->get($r->product_id)) ? $p->code.' - '.$p->name() : '—',
            'qty' => Money::of($r->qty),
            'amount' => Money::of($r->amount),
        ])->values()->all();
    }

    /**
     * টাকা ধরে সাজানো, আর নিট বিক্রিতে কার কত ভাগ — ভিত [[SalesMetrics::net()]] (VAT আর ফেরত বাদে; fe, ২১ অক্টোবর ২০২৬)।
     *
     * @param  array<int|string, array{name: string, count: int, amount: string}>  $buckets
     * @return list<array{name: string, count: int, amount: string, share: ?string}>
     */
    private function ranked(array $buckets, string $from, string $to): array
    {
        $net = SalesMetrics::net($from, $to);
        $rows = array_values($buckets);

        usort($rows, fn (array $a, array $b): int => bccomp($b['amount'], $a['amount'], 4));

        return array_map(fn (array $r): array => $r + ['share' => self::share($r['amount'], $net)], $rows);
    }

    /**
     * শেষ মাসসহ বারো মাসের জানালা, পুরনোটা আগে।
     *
     * @return list<array{label: string, ym: string, from: string, to: string}>
     */
    private function monthWindow(Carbon $end): array
    {
        $out = [];
        $cursor = $end->copy()->startOfMonth()->subMonths(self::MONTHS - 1);

        for ($i = 0; $i < self::MONTHS; $i++) {
            $last = $cursor->copy()->endOfMonth();

            $out[] = [
                'label' => $cursor->translatedFormat('M y'),
                'ym' => $cursor->format('Y-m'),
                'from' => $cursor->toDateString(),
                'to' => ($last->greaterThan($end) ? $end : $last)->toDateString(),
            ];

            $cursor->addMonth();
        }

        return $out;
    }

    /** অংশ ÷ মোট × ১০০, এক দশমিক — মোট শূন্য হলে কিছুই নয়। */
    public static function share(string $part, string $whole): ?string
    {
        if (bccomp($whole, '0', 4) <= 0) {
            return null;
        }

        return bcdiv(bcmul($part, '100', 6), $whole, 1);
    }
}
