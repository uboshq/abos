<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Viz\Card;
use App\Core\Dashboard\Viz\Svg;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ক্রয় মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন "দেনা" সবচেয়ে বড় সংখ্যা ─────────────────────────────────────
 * বিক্রয়ে প্রশ্নটা "কত এলো"; ক্রয়ে প্রশ্নটা **"কত দিতে হবে"**। ওই
 * সংখ্যাটাই নগদের পরিকল্পনা ঠিক করে, আর সেটা না জানলে মাস শেষে
 * অপ্রত্যাশিত চাপ আসে।
 */
final class PurchaseDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $month = Carbon::today()->startOfMonth()->toDateString();

        // ⓘ উপরের তিন সংখ্যা একবারই পড়া — ঘর আর গ্রাফের বোর্ড একই অঙ্ক দেখায়
        $payable = app(AccountsFacts::class)->payable();
        $bought = (string) (PurchaseBill::query()->whereIn('status', DocumentStatus::POSTED)
            ->where('trx_date', '>=', $month)->sum('total') ?: '0');
        $paid = self::paidFrom($month);
        $series = self::boughtAgainstPaid();
        $panels = PurchaseCharts::panels();

        return new DashboardDefinition(
            title: __('purchase::dashboard.title'),
            subtitle: __('purchase::dashboard.subtitle'),

            tiles: [
                /*
                 * ⭐ "নতুন বিল" নয়, "সরাসরি ক্রয়" — মালিক, ২১ সেপ্টেম্বর ২০২৬।
                 *
                 * ── ⛔ ১৯ সেপ্টেম্বরের সিদ্ধান্তটা এখানে পৌঁছায়নি ──────────
                 * সেদিন ক্রয় বিলের **তালিকা** থেকে "নতুন বিল" তুলে দেওয়া
                 * হয়েছিল, আর কারণটা লেখাও আছে: *বিল জন্মায় মাল গ্রহণে আর
                 * সরাসরি ক্রয়ে, আপনা থেকে*। ⚠️ কিন্তু ড্যাশবোর্ডের টালিটা
                 * রয়ে গিয়েছিল — এক পর্দায় দরজা বন্ধ, পাশের পর্দায় খোলা।
                 *
                 * ⓘ ফল রোজকার ভাষায়: এখান থেকে ঢুকলে একটা **খালি** বিল
                 * খুলত, যার পেছনে কোনো মাল গ্রহণ নেই — আর তখন মজুদ আর
                 * খাতা দুইটা আলাদা গল্প বলত।
                 *
                 * ⚠️ অনুমতিটা `purchase.bill.create`-ই থাকে, কারণ সরাসরি
                 * ক্রয়ও শেষে একটা বিলই জন্ম দেয় — [[DirectPurchaseController]]
                 * নিজেও ঠিক এই অনুমতিটাই দাবি করে।
                 */
                new Tile(label: __('purchase::menu.direct'), href: route('purchase.direct.create'),
                    permission: 'purchase.bill.create', icon: 'purchase'),
                new Tile(label: __('purchase::action.new_order'), href: route('purchase.order.create'),
                    permission: 'purchase.order.create', icon: 'plus'),
                new Tile(label: __('purchase::menu.payments'), href: route('purchase.payment.index'),
                    permission: 'purchase.payment.view', icon: 'cash'),
                new Tile(label: __('purchase::menu.bills'), href: route('purchase.bill.index'),
                    permission: 'purchase.bill.view', icon: 'reports'),
            ],

            stats: [
                /*
                 * ⭐ দেনা খাতা থেকে, বিলের যোগফল থেকে নয় — ২৭ সেপ্টেম্বর ২০২৬।
                 *
                 * ── ⛔ লাইভ QA-তে কী দেখা গেল ──────────────────────────────
                 * TCL-এ ৳৩,০০০ কেনা, ৳১,০০০ দেওয়া, ৳২০০ ফেরত — আসল দেনা
                 * ৳১,৮০০, অথচ এই ঘর বলছিল **৳৩,০০০**। ⓘ সংখ্যাটা নিশ্চিত
                 * বিলগুলোর `total` যোগ করত, আর বিলের মোট টাকা গেলেও কমে না,
                 * মাল ফেরত গেলেও না।
                 *
                 * ⚠️ হিসাবের ড্যাশবোর্ড, অর্থের ড্যাশবোর্ড আর CFO পাতা আগে
                 * থেকেই [[AccountsFacts::payable]] পড়ে — খাত ২১১১-এর জের।
                 * ⛔ এখানে নিজের `SUM` মানে একই প্রশ্নের দ্বিতীয় সংজ্ঞা, আর
                 * দুই পর্দায় দুই উত্তর। তাই সংজ্ঞা একটাই, ওখানেই।
                 *
                 * ⓘ পাহারা: [[ThePayableOnTheDashboardForgotThePaymentsTest]]
                 */
                new Stat(
                    label: __('purchase::dashboard.payable'),
                    value: Money::format($payable),
                    hint: __('purchase::dashboard.payable_hint'),
                    href: route('purchase.bill.index'),
                    tone: Stat::BAD,
                ),

                new Stat(
                    label: __('purchase::dashboard.bought_this_month'),
                    value: Money::format($bought),
                    hint: __('purchase::dashboard.bought_hint'),
                    href: route('purchase.bill.index'),
                ),

                new Stat(
                    label: __('purchase::dashboard.paid_this_month'),
                    // ⛔ কেবল খাতায় ওঠা পরিশোধ, সাথে বিলের বিপরীতে পরিশোধ-ভাউচার (কাউন্টার) — অডিট ⚠️১৪, ৬ অক্টোবর ২০২৬ ([[paidFrom()]])
                    value: Money::format($paid),
                    hint: __('purchase::dashboard.paid_hint'),
                    href: route('purchase.payment.index'),
                    tone: Stat::GOOD,
                ),

                /*
                 * ── কেন অপেক্ষমাণ ক্রয়াদেশ আলাদা সংখ্যা ──────────────────
                 * অর্ডার দেওয়া হয়েছে কিন্তু মাল আসেনি — এটা দেনা নয়,
                 * **প্রতিশ্রুতি**। দুইটা মিলিয়ে দিলে দেনার সংখ্যাটা বড়
                 * দেখাত, আর নগদের পরিকল্পনা ভুল হত।
                 */
                new Stat(
                    label: __('purchase::dashboard.open_orders'),
                    value: (string) PurchaseOrder::query()->where('status', DocumentStatus::CONFIRMED)->count(),
                    hint: __('purchase::dashboard.open_orders_hint'),
                    href: route('purchase.order.index'),
                    tone: Stat::WARN,
                ),

                // ⭐ এ মাসের ফেরত আর সইয়ের অপেক্ষার বিল — মালিকের নকশা, ৬ অক্টোবর ২০২৬ ([[PurchaseCharts]])
                ...PurchaseCharts::stats(),
            ],

            // ⚠️ প্রথম চার্টটা হোমে যায় — নতুনগুলো তার পরে (৬ অক্টোবর ২০২৬)
            panels: [$series, ...self::topSuppliers(), ...$panels],

            listings: [
                new Listing(
                    label: __('purchase::dashboard.biggest_payables'),
                    columns: [
                        ['key' => 'no', 'label' => __('purchase::field.document_no'),
                            'render' => fn ($b) => $b->document_no],
                        ['key' => 'party', 'label' => __('purchase::field.supplier'),
                            'render' => fn ($b) => $b->supplier?->name() ?? '—'],
                        ['key' => 'amount', 'label' => __('purchase::dashboard.payable'), 'width' => '9rem',
                            'render' => fn ($b) => Money::format($b->dueAmount())],
                    ],
                    rows: self::biggestPayable(),
                    empty: __('purchase::dashboard.nothing_payable'),
                    href: route('purchase.bill.index'),
                ),
                ...self::goodsNotYetIn(),
                ...PurchaseCharts::listings(),
                ...self::monthsBeforeTheChart(),
            ],

            /*
             * ⭐ খোলার পর্দায় ছয়টা গ্রাফ — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা boards15 "pur"): কেনা বনাম পরিশোধ (রেখা), সবচেয়ে বেশি কেনা
             *   (লোলিপপ), ক্রয়ের পথ (ফানেল), দেনা কবে দিতে হবে (রঙিন কলাম), মাল আসেনি (সময়রেখা), পণ্যের দলের ভাগ (100% স্তূপ)।
             *   যে সংখ্যা-চার্ট গ্রাফে এল, পাতায় আর আলাদা নয়; বাকি (ফেরত, অনুমোদনের অপেক্ষা, আদেশের অবস্থা, তালিকাগুলো) নিচে।
             */
            sections: [Card::board(fn (): array => self::charts($payable, $bought, $paid, $panels), covers: [
                __('purchase::dashboard.payable'), __('purchase::dashboard.bought_this_month'), __('purchase::dashboard.paid_this_month'),
                __('purchase::dashboard.open_orders'), $series->label,
                __('purchase::dashboard.top_suppliers', ['year' => Carbon::today()->year]), __('purchase::dashboard.funnel'),
            ])],
        );
    }

    /**
     * ⭐ ক্রয়ের গ্রাফের বোর্ড — প্রতিটা অঙ্ক আগের ঘর আর চার্টের একই সংজ্ঞা থেকে (একই কোয়েরি, একই চাবি)।
     * ⓘ টাকা স্ট্রিং হয়ে যায়; ছোট লেখায় (৳ 96 লা) কেবল পূর্ণ টাকার পূর্ণসংখ্যা — float নয়।
     *
     * @param  list<Breakdown>  $panels  [[PurchaseCharts::panels()]] — ফানেল এখান থেকেই, আবার গোনা হয় না
     * @return list<array<string, mixed>>
     */
    private static function charts(string $payable, string $bought, string $paid, array $panels): array
    {
        $user = auth()->user();
        $short = fn (string $amount): string => (bccomp($amount, '0', 4) < 0 ? '−' : '').'৳ '.Svg::short((int) ltrim(Money::round($amount, 0), '-'));
        $t = fn (string $key, array $with = []): string => (string) __('purchase::dashboard.'.$key, $with);
        $today = Carbon::today();

        // ── ১. কেনা বনাম পরিশোধ, ছয় মাস — উপরের দুই সংখ্যা আর বছরের চার্টের একই সংজ্ঞা ([[boughtAndPaidByMonth()]])
        $start = $today->copy()->startOfMonth()->subMonths(5);
        [$byMonthBought, $byMonthPaid] = self::boughtAndPaidByMonth($start);
        $line = ['v' => [], 'v2' => [], 'l' => []];
        for ($m = $start->copy(); $m->lessThanOrEqualTo($today); $m->addMonth()) {
            $line['v'][] = (string) ($byMonthBought[$m->format('Y-m')] ?? '0');
            $line['v2'][] = (string) ($byMonthPaid[$m->format('Y-m')] ?? '0');
            $line['l'][] = $m->translatedFormat('M');
        }

        // ── ২. সবচেয়ে বেশি কেনা — আগের চার্টের একই সারি (বিল দেখার চাবিতে)
        // ⛔ লাইভ 1.3.7 (২১ নভেম্বর ২০২৬): এখানে নতুন-ড্যাশবোর্ডের সুইচও দেখা হত — অথচ গ্রাফের বোর্ড দুই অবস্থাতেই আঁকা হয়, তাই সুইচ বন্ধে
        //   এ বছরের ৳30.4 লাখ কেনার পরেও কার্ড ফাঁকা ("এই সময়ে কিছু নেই")। ⭐ কার্ড কেবল চাবি মানে, সুইচ নয়।
        $top = $user?->can('purchase.bill.view') ? self::topSupplierRows() : collect();

        // ── ৩. ক্রয়ের পথ — [[PurchaseCharts::funnel()]]-এর হুবহু ভাগ
        // ⛔ লাইভ 1.3.7: `panels()` সুইচ বন্ধে খালি, তাই কার্ডটা ফাঁকা থাকত — বোর্ড দুই অবস্থাতেই আঁকা হয়, ফানেল তখন সরাসরি
        $funnel = collect($panels)->first(fn ($p) => $p->label === __('purchase::dashboard.funnel')) ?? PurchaseCharts::funnel();

        // ── ৪. দেনা কবে দিতে হবে — বিলে যা বাকি ([[biggestPayable()]]-এর একই হিসাব), শেষ তারিখ ধরে; শেষ তারিখ না থাকলে বিলের তারিখ
        $due = '(b.total - b.ait_amount - b.vds_amount - b.paid_total - b.voucher_paid_total - COALESCE(b.returned_total, 0))';
        $on = 'COALESCE(b.due_on, b.trx_date)';
        $soon = $today->copy()->addDays(7)->toDateString();
        $late30 = $today->copy()->subDays(30)->toDateString();
        $buckets = DB::query()
            ->fromSub(PurchaseBill::query()->whereIn('status', DocumentStatus::POSTED)->withPaid(), 'b')
            ->whereRaw("{$due} > 0")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$on} > ? THEN {$due} ELSE 0 END), 0) as later", [$soon])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$on} >= ? AND {$on} <= ? THEN {$due} ELSE 0 END), 0) as week", [$today->toDateString(), $soon])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$on} < ? AND {$on} >= ? THEN {$due} ELSE 0 END), 0) as late", [$today->toDateString(), $late30])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$on} < ? THEN {$due} ELSE 0 END), 0) as stale", [$late30])
            ->first();
        $dues = array_map(fn (string $k) => (string) ($buckets->{$k} ?? '0'), ['later', 'week', 'late', 'stale']);

        // ── ৫. মাল আসেনি — "অপেক্ষমাণ ক্রয়াদেশ"-এর একই ছাঁকনি; সবচেয়ে পুরনো ছয়টা, কত দিন ধরে
        $open = PurchaseOrder::query()->where('status', DocumentStatus::CONFIRMED);
        $openCount = (clone $open)->count();
        $oldest = (clone $open)->with('supplier')->orderBy('trx_date')->orderBy('id')->limit(6)->get();
        $ages = $oldest->map(fn (PurchaseOrder $o) => max(0, (int) $o->trx_date->diffInDays($today)));
        $window = max(7, (int) (ceil(max($ages->max() ?? 0, 1) / 7) * 7));
        $gantt = $oldest->values()->map(fn (PurchaseOrder $o, int $i) => [
            Str::limit($o->document_no.' '.($o->supplier?->name() ?? ''), 13),
            1 - $ages[$i] / $window, 1,
            $ages[$i] > 21 ? Svg::STATUS[3] : ($ages[$i] > 14 ? Svg::STATUS[2] : ($ages[$i] > 7 ? Svg::STATUS[1] : Svg::STATUS[0])),
        ])->all();
        $step = intdiv($window, 3);

        // ── ৬. পণ্যের দল — এ মাস বনাম গত মাস, বিলের সারির টাকা (বিল দেখার চাবিতে)
        $mix = $user?->can('purchase.bill.view') ? self::categoryMix() : ['rows' => [], 'names' => []];

        return [
            Card::make($t('viz_bought_paid'), 'line', $line + ['color' => Svg::PALETTE[0], 'c2' => '#f59e0b'],
                value: $short($bought), sub: $t('viz_bought_paid_sub', ['paid' => Money::format($paid)]),
                legend: [[$t('bought'), Svg::PALETTE[0]], [$t('paid'), '#f59e0b']], href: route('purchase.bill.index'), colour: Svg::PALETTE[0]),
            // ⓘ শিরোনাম নমুনার হুবহু ("সবচেয়ে বেশি কেনা — সরবরাহকারী"), বছরটা নিচের লাইনে — ড্যাশবোর্ড যাচাই, ২৩ নভেম্বর ২০২৬ (দফা ৯)
            Card::make($t('viz_top_suppliers'), 'lollipop', [
                'v' => $top->map(fn (PurchaseBill $r) => (string) $r->amount)->all(),
                'l' => $top->map(fn (PurchaseBill $r) => Str::limit($r->supplier?->name() ?? '—', 12))->all(),
                'top' => $top->map(fn (PurchaseBill $r) => $short((string) $r->amount))->all(),
            ], sub: $t('viz_top_suppliers_sub', ['year' => $today->year]), href: route('purchase.bill.index'), colour: Svg::PALETTE[1]),
            Card::make($t('viz_path'), 'funnel', ['p' => $funnel === null ? [] : array_map(
                fn (array $part) => [$part['label'], (int) $part['value'], (string) $part['value']], $funnel->parts,
            )], sub: $t('viz_path_sub'), href: route('purchase.order.index'), colour: Svg::PALETTE[2]),
            Card::make($t('viz_payable'), 'bars', [
                'v' => $dues, 'l' => [$t('viz_due_later'), $t('viz_due_week'), $t('viz_due_late'), $t('viz_due_stale')],
                'top' => array_map($short, $dues), 'colors' => [Svg::PALETTE[2], Svg::STATUS[1], Svg::STATUS[2], Svg::STATUS[3]],
            ], value: $short($payable), sub: $t('viz_due_sub'), href: route('purchase.bill.index'), colour: '#f97316'),
            Card::make($t('goods_not_in'), 'gantt', [
                'ticks' => [$t('viz_days_ago', ['n' => $window]), '−'.($step * 2), '−'.$step, $t('viz_today')], 'today' => 1, 'p' => $gantt,
            ], value: $t('viz_count', ['n' => $openCount]), sub: $t('viz_waiting_sub'), href: route('purchase.order.index'), colour: Svg::STATUS[3]),
            Card::make($t('viz_mix'), 'stack100', ['rows' => $mix['rows'], 'colors' => array_slice(Svg::PALETTE, 0, 5)],
                sub: $t('viz_mix_sub'), legend: array_map(fn (string $n, int $i) => [$n, Svg::PALETTE[$i]], $mix['names'], array_keys($mix['names'])),
                href: route('purchase.bill.index'), colour: Svg::PALETTE[7]),
        ];
    }

    /**
     * ⭐ পণ্যের দলের ভাগ — এ মাস আর গত মাসের নিশ্চিত বিলের সারির টাকা, পণ্যের দল (শ্রেণি) ধরে; এ মাসের বড় চারটা দল, বাকি "অন্যান্য"।
     * ⓘ কাঁচা কোয়েরি — কোম্পানি, মোছা বাদ আর হেডারে বাছা শাখা হাতে বসানো ([[DataScope::inView()]]), [[PurchaseCharts::priceChanges()]]-এর মতো।
     *
     * @return array{rows: list<array{0: string, 1: list<string>}>, names: list<string>}
     */
    private static function categoryMix(): array
    {
        $today = Carbon::today();
        $periods = [
            'now' => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
            'last' => [$today->copy()->startOfMonth()->subMonthNoOverflow()->toDateString(), $today->copy()->startOfMonth()->subDay()->toDateString()],
        ];
        $sums = [];

        foreach ($periods as $key => $range) {
            $query = DB::table('pur_bill_lines')
                ->join('pur_bills', 'pur_bills.id', '=', 'pur_bill_lines.purchase_bill_id')
                ->leftJoin((new Product)->getTable().' as p', 'p.id', '=', 'pur_bill_lines.product_id')
                ->where('pur_bills.company_id', CompanyContext::id())
                ->whereIn('pur_bills.status', DocumentStatus::POSTED)
                ->whereNull('pur_bills.deleted_at')
                ->whereBetween('pur_bills.trx_date', $range)
                ->selectRaw('p.category_id as category, COALESCE(SUM(pur_bill_lines.amount), 0) as amount')
                ->groupBy('p.category_id');

            app(DataScope::class)->inView($query, 'pur_bills.branch_id');
            $sums[$key] = $query->pluck('amount', 'category')->map(fn ($v) => (string) $v)->all();
        }

        $ranked = $sums['now'];
        uasort($ranked, fn (string $a, string $b) => bccomp($b, $a, 4));
        $ids = array_slice(array_keys($ranked), 0, 4);
        $names = ProductCategory::query()->whereKey(array_filter($ids))->get()->keyBy('id');

        // ⓘ বড় চারটার বাইরের সব দল এক ঘরে — দুই সারিতে একই ঘর, যাতে রং আর নাম মেলে
        $rests = [];
        foreach ($sums as $key => $byCategory) {
            $rests[$key] = '0';
            foreach ($byCategory as $id => $amount) {
                if (! in_array($id, $ids, true)) {
                    $rests[$key] = bcadd($rests[$key], $amount, 4);
                }
            }
        }
        $withRest = bccomp($rests['now'], '0', 4) > 0 || bccomp($rests['last'], '0', 4) > 0;

        $rows = [];
        foreach (['now' => __('purchase::dashboard.viz_this_month'), 'last' => __('purchase::dashboard.viz_last_month')] as $key => $label) {
            $values = array_map(fn ($id) => $sums[$key][$id] ?? '0', $ids);
            if ($withRest) {
                $values[] = $rests[$key];
            }
            $rows[] = [(string) $label, $values];
        }

        $legend = array_map(fn ($id) => $id === '' ? (string) __('purchase::dashboard.viz_no_group')
            : ($names->get($id)?->name() ?? '—'), $ids);
        if ($withRest) {
            $legend[] = (string) __('purchase::dashboard.viz_others');
        }

        return ['rows' => $ids === [] ? [] : $rows, 'names' => array_values($legend)];
    }

    /**
     * ⭐ মাসে মাসে কেনা বনাম পরিশোধ — গত ছয় মাস (মালিকের ড্যাশবোর্ড নকশা, ২ অক্টোবর ২০২৬)।
     *
     * ⓘ দুইটা দণ্ড পাশাপাশি দেখালেই বোঝা যায় দেনা বাড়ছে না কমছে: কেনা বেশি, পরিশোধ কম মানে দেনা জমছে।
     * ⓘ সংজ্ঞা উপরের দুইটা সংখ্যার মতোই — নিশ্চিত বিলের `total`, পরিশোধের `amount` — তাই এ মাসের দণ্ড আর
     * "এই মাসে কেনা / পরিশোধ" কখনো দুই কথা বলে না। দুইটা কোয়েরি, মাস ধরে ভাগ; ফাঁকা মাসও শূন্য নিয়ে থাকে।
     */
    /**
     * ⭐ মাল আসেনি — খোলা ক্রয়াদেশ, যেটার দেরি সবচেয়ে বেশি সেটা উপরে (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ উপরের "অপেক্ষমাণ ক্রয়াদেশ" সংখ্যার একই ছাঁকনি (নিশ্চিত, এখনো বন্ধ হয়নি) — সংখ্যা বলে কয়টা, এটা বলে কোনগুলো।
     * ⓘ আসার তারিখ না লেখা আদেশ শেষে, আদেশের তারিখ ধরে; দেরি দিনে, অ্যাপের ঘড়িতে (ডাটাবেজের নয়)।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Listing>
     */
    private static function goodsNotYetIn(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $today = Carbon::today();

        return [new Listing(
            // ⓘ গ্রাফের "মাল আসেনি — খোলা ক্রয়াদেশ"-এর নাম নয় — তালিকার নিজের নাম, নমুনার নিচের সারির (ড্যাশবোর্ড যাচাই, ২৩ নভেম্বর ২০২৬, দফা ১০)
            label: __('purchase::dashboard.open_orders_list'),
            columns: [
                ['key' => 'no', 'label' => __('purchase::field.document_no'),
                    'render' => fn ($o) => $o->document_no],
                ['key' => 'party', 'label' => __('purchase::field.supplier'),
                    'render' => fn ($o) => $o->supplier?->name() ?? '—'],
                ['key' => 'expected', 'label' => __('purchase::dashboard.expected_on'), 'width' => '10rem',
                    'render' => fn ($o) => $o->expected_on === null ? '—'
                        : ($o->expected_on->lt($today)
                            ? __('purchase::dashboard.late_by', ['days' => (int) $o->expected_on->diffInDays($today)])
                            : $o->expected_on->format('d M Y'))],
                ['key' => 'amount', 'label' => __('purchase::field.total'), 'width' => '9rem',
                    'render' => fn ($o) => Money::format($o->total)],
            ],
            rows: PurchaseOrder::query()->where('status', DocumentStatus::CONFIRMED)
                ->with('supplier')
                ->orderByRaw('expected_on IS NULL')->orderBy('expected_on')->orderBy('trx_date')
                ->limit(8)->get(),
            empty: __('purchase::dashboard.all_goods_in'),
            href: route('purchase.order.index'),
        )];
    }

    /**
     * ⭐ এ বছর কার কাছ থেকে সবচেয়ে বেশি কেনা — প্রথম পাঁচ সরবরাহকারী (মালিকের ড্যাশবোর্ড নকশা, ২ অক্টোবর ২০২৬)।
     *
     * ⓘ সংজ্ঞা পাশের চার্টের মতোই — নিশ্চিত বিলের `total`, একই কোয়েরির ভিত, তাই দুইটা কখনো দুই কথা বলে না।
     * ⛔ বিলের অঙ্ক — কেবল `purchase.bill.view` যাঁর আছে; চাবি না থাকলে চার্টটাই নেই।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function topSuppliers(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('purchase.bill.view')) {
            return [];
        }

        $rows = self::topSupplierRows();

        if ($rows->isEmpty()) {
            return [];
        }

        return [new Breakdown(
            label: __('purchase::dashboard.top_suppliers', ['year' => Carbon::today()->year]),
            parts: $rows->map(fn (PurchaseBill $row) => [
                'label' => $row->supplier?->name() ?? '—',
                'value' => Money::format((string) $row->amount),
            ])->all(),
            hint: __('purchase::dashboard.top_suppliers_hint'),
        )];
    }

    /**
     * এ বছর সবচেয়ে বেশি কেনা পাঁচ সরবরাহকারী — নিশ্চিত বিলের `total`; আগের চার্ট আর গ্রাফের বোর্ড দুইটাই এটা পড়ে।
     *
     * @return Collection<int, PurchaseBill>
     */
    private static function topSupplierRows(): Collection
    {
        return PurchaseBill::query()->whereIn('status', DocumentStatus::POSTED)
            ->where('trx_date', '>=', Carbon::today()->startOfYear()->toDateString())
            ->whereNotNull('supplier_id')
            ->selectRaw('supplier_id, COALESCE(SUM(total), 0) as amount')
            ->groupBy('supplier_id')
            ->orderByDesc('amount')
            ->limit(5)
            ->with('supplier')
            ->get()
            ->toBase();
    }

    /**
     * মাস ধরে কেনা আর পরিশোধ — `Y-m` => টাকা; একটা তারিখ থেকে। বছরের চার্ট আর গ্রাফের বোর্ডের ছয় মাস একই সংজ্ঞা পড়ে।
     *
     * @return array{0: \Illuminate\Support\Collection<string, mixed>, 1: \Illuminate\Support\Collection<string, mixed>}
     */
    private static function boughtAndPaidByMonth(Carbon $start): array
    {
        $expr = "DATE_FORMAT(trx_date, '%Y-%m')";

        $bought = PurchaseBill::query()->whereIn('status', DocumentStatus::POSTED)
            ->where('trx_date', '>=', $start->toDateString())
            ->selectRaw("{$expr} as ym, COALESCE(SUM(total), 0) as amount")->groupByRaw($expr)
            ->toBase()->pluck('amount', 'ym');

        // ⛔ কেবল খাতায় ওঠা পরিশোধ আর পরিশোধ-ভাউচার — খসড়া বা বাতিল নয় (অডিট ⚠️১৪)
        $paid = Payment::query()->posted()->where('trx_date', '>=', $start->toDateString())
            ->selectRaw("{$expr} as ym, COALESCE(SUM(amount), 0) as amount")->groupByRaw($expr)
            ->toBase()->pluck('amount', 'ym');
        $byVoucher = self::billVouchers()->where('trx_date', '>=', $start->toDateString())
            ->selectRaw("{$expr} as ym, COALESCE(SUM(amount), 0) as amount")->groupByRaw($expr)
            ->toBase()->pluck('amount', 'ym');
        foreach ($byVoucher as $ym => $amount) {
            $paid[$ym] = bcadd((string) ($paid[$ym] ?? '0'), (string) $amount, 4);
        }

        return [$bought, $paid];
    }

    /**
     * ⭐ এ বছরের যে মাসগুলো গ্রাফে নেই — জানুয়ারি থেকে গ্রাফের ছয় মাসের আগের মাস পর্যন্ত, মাসে কেনা আর পরিশোধ।
     *
     * ⛔ ড্যাশবোর্ড যাচাই, ২৩ নভেম্বর ২০২৬ (দফা ৭, "কিছু হারাবে না"): বছরের চার্ট (জানুয়ারি–ডিসেম্বর) গ্রাফের বোর্ডে ঢাকা পড়েছিল, অথচ
     *   গ্রাফ দেখায় কেবল শেষ ছয় মাস — জানুয়ারি থেকে মে হারিয়ে গিয়েছিল। ⓘ তালিকা, চার্ট নয়: হোম মডিউলের সব চার্ট দেখায়, সেখানে
     *   বছরের চার্টটা আগে থেকেই আছে — দ্বিতীয় চার্ট হলে সেখানে একই মাস দুইবার হত। একই সংজ্ঞা ([[boughtAndPaidByMonth()]])।
     * ⓘ বছরের চার্টের মতোই নতুন-ড্যাশবোর্ডের সুইচে — সুইচ বন্ধে চার্টটা ছয় মাসের, তাই হারানোর কিছু নেই। গ্রাফের ছয় মাস জানুয়ারিতে
     *   বা তার আগে শুরু হলে (জুন পর্যন্ত) তালিকাটাই নেই।
     *
     * @return list<Listing>
     */
    private static function monthsBeforeTheChart(): array
    {
        $yearStart = Carbon::today()->startOfYear();
        $window = Carbon::today()->startOfMonth()->subMonths(5);

        if (! config('abos.dashboards_v2') || $window->lessThanOrEqualTo($yearStart)) {
            return [];
        }

        [$bought, $paid] = self::boughtAndPaidByMonth($yearStart);
        $rows = collect();

        for ($month = $yearStart->copy(); $month->lessThan($window); $month->addMonth()) {
            $ym = $month->format('Y-m');
            $rows->push(['month' => $month->translatedFormat('F'), 'bought' => (string) ($bought[$ym] ?? '0'), 'paid' => (string) ($paid[$ym] ?? '0')]);
        }

        return [new Listing(
            label: __('purchase::dashboard.months_before_chart', [
                'year' => $yearStart->year,
                'from' => $yearStart->translatedFormat('F'),
                'to' => $window->copy()->subMonth()->translatedFormat('F'),
            ]),
            columns: [
                ['key' => 'month', 'label' => __('purchase::dashboard.month'), 'render' => fn (array $r) => $r['month']],
                ['key' => 'bought', 'label' => __('purchase::dashboard.bought'), 'width' => '10rem', 'numeric' => true,
                    'render' => fn (array $r) => Money::format($r['bought'])],
                ['key' => 'paid', 'label' => __('purchase::dashboard.paid'), 'width' => '10rem', 'numeric' => true,
                    'render' => fn (array $r) => Money::format($r['paid'])],
            ],
            rows: $rows,
            empty: __('purchase::dashboard.months_before_chart_empty'),
            href: route('purchase.bill.index'),
        )];
    }

    private static function boughtAgainstPaid(): Series
    {
        /*
         * ⭐ নতুন ড্যাশবোর্ডে এ বছরের জানুয়ারি–ডিসেম্বর — বিক্রয় ও মজুদের মতোই (মালিক, ২ অক্টোবর ২০২৬:
         * "১২ মাসের দিবে January to Dec")। ভবিষ্যতের মাসগুলো শূন্য নিয়ে। সুইচ বন্ধে আগের মতো ছয় মাস।
         */
        $year = (bool) config('abos.dashboards_v2');
        $start = $year ? Carbon::today()->startOfYear() : Carbon::today()->startOfMonth()->subMonths(5);
        $last = $year ? Carbon::today()->endOfYear() : Carbon::today();
        [$bought, $paid] = self::boughtAndPaidByMonth($start);

        $points = [];

        for ($month = $start->copy(); $month->lessThanOrEqualTo($last); $month->addMonth()) {
            $ym = $month->format('Y-m');
            $in = (string) ($bought[$ym] ?? '0');
            $out = (string) ($paid[$ym] ?? '0');

            $points[] = [
                'label' => $month->translatedFormat('M'),
                'first' => $in,
                'second' => $out,
                'firstTitle' => Money::format($in),
                'secondTitle' => Money::format($out),
            ];
        }

        return new Series(
            label: $year
                ? __('purchase::dashboard.bought_against_paid_year', ['year' => Carbon::today()->year])
                : __('purchase::dashboard.bought_against_paid'),
            points: $points,
            firstLabel: __('purchase::dashboard.bought'),
            secondLabel: __('purchase::dashboard.paid'),
            // ⓘ টাকা — মাউস/কিবোর্ডে পুরো অঙ্কের পরে "টাকা" ([[Series::$money]], ২০ অক্টোবর ২০২৬)
            money: true,
            // ⭐ কোন তারিখ থেকে কোন তারিখ (মালিক, ৫ অক্টোবর ২০২৬)
            range: \App\Core\Engines\Dashboard\DateRange::label($start, Carbon::today()),
        );
    }

    /**
     * ⭐ একটা তারিখ থেকে আজ পর্যন্ত সরবরাহকারীকে যা দেওয়া হলো — খাতায় ওঠা পরিশোধ আর বিলের বিপরীতে পরিশোধ-ভাউচার।
     * ⛔ আগে অবস্থা না দেখে সব পরিশোধ (খসড়া, বাতিলও), আর কাউন্টারের পরিশোধ-ভাউচার বাদ (অডিট ⚠️১৪, ৬ অক্টোবর ২০২৬)।
     */
    private static function paidFrom(string $from): string
    {
        $payments = (string) (Payment::query()->posted()->where('trx_date', '>=', $from)->sum('amount') ?: '0');

        return bcadd($payments, (string) (self::billVouchers()->where('trx_date', '>=', $from)->sum('amount') ?: '0'), 4);
    }

    /** ⓘ ক্রয় বিলের বিপরীতে খাতায় ওঠা পরিশোধ-ভাউচার — [[PurchaseBill::paidByPaymentVouchers()]]-এর হুবহু শর্ত */
    private static function billVouchers()
    {
        $type = PurchaseBill::drillSourceType();

        // ⭐ একক "বিপরীতে" বা বহু বিলের সারি — দুই পথের পরিশোধই (cb-ma-5)
        return \App\Modules\Accounts\Models\Voucher::query()
            ->where('type', \App\Modules\Accounts\Models\Voucher::PAYMENT)
            ->where(fn ($q) => $q->where('against_type', $type)->orWhereHas('allocations', fn ($row) => $row->where('against_type', $type)))
            ->posted();
    }

    /**
     * ⭐ সবচেয়ে বড় দেনা — বিলে এখনো যা বাকি, বড় থেকে ছোট (অডিট ⚠️১৪)। ⛔ আগে বিলের মোট ধরে সাজানো আর মোটটাই দেখানো —
     * শোধ হয়ে যাওয়া বড় বিলও মাথায় বসত। ⓘ বাকির হিসাব বিলের পাতার হুবহু ([[PurchaseBill::scopeWithPaid()]]).
     */
    private static function biggestPayable()
    {
        $ranked = DB::query()
            ->fromSub(PurchaseBill::query()->whereIn('status', DocumentStatus::POSTED)->withPaid(), 'b')
            // ⭐ পাকা ফেরতও বাদ — [[PurchaseBill::dueAmount()]] (ক্রয় ⚠️৬, ৬ অক্টোবর ২০২৬)
            ->selectRaw('b.id, (b.total - b.ait_amount - b.vds_amount - b.paid_total - b.voucher_paid_total - COALESCE(b.returned_total, 0)) as due')
            ->whereRaw('(b.total - b.ait_amount - b.vds_amount - b.paid_total - b.voucher_paid_total - COALESCE(b.returned_total, 0)) > 0')
            ->orderByDesc('due')
            ->orderBy('b.id')
            ->limit(8)
            ->pluck('id')
            ->all();

        return PurchaseBill::query()->withPaid()->with('supplier')->whereIn('pur_bills.id', $ranked ?: [0])->get()
            ->sortBy(fn (PurchaseBill $b) => array_search($b->id, $ranked, true))
            ->values();
    }
}
