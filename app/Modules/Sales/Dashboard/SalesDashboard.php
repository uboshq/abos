<?php

declare(strict_types=1);

namespace App\Modules\Sales\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Widget;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Services\PartyRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * বিক্রয় মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন সংখ্যাগুলো [[SalesMetrics]] থেকে ─────────────────────────────
 * "আজকের বিক্রয়" এই রিপোতেই একবার চার জায়গায় গোনা হত, আর দুইটা আলাদা
 * উত্তর দিয়েছিল — খসড়া বিল গোনা হচ্ছিল কি না তা নিয়ে। তারপর সংজ্ঞাটা
 * এক জায়গায় আনা হয়েছে, আর এই পর্দাটা সেখান থেকেই নেয়।
 *
 * এখানে নতুন করে একটা `sum()` লিখলে সেটা হত **পঞ্চম** সংজ্ঞা।
 */
final class SalesDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        /*
         * ⛔ সংখ্যাগুলো **বাছা সময়কাল ধরে** — ৬ সেপ্টেম্বর ২০২৬।
         *
         * ── কী ভাঙা ছিল ─────────────────────────────────────────────
         * এখানে সবসময় *আজ* আর *এই মাস* বসত, সময়কাল যা-ই বাছা হোক।
         * ⚠️ ফলে ড্যাশবোর্ডে "বছর" বাছলেও **"আজকের বিক্রয়" কার্ডটা
         * থেকে যেত** — আর ঠিক ওটাই [[OneNumberIsNotADirectionTest::
         * test_only_the_chosen_period_is_shown]] ধরেছে।
         *
         * ⓘ কার্ডগুলো (`Widget`) ঠিকই ছাঁকা হত (`group` ধরে), কিন্তু
         * মডিউলের এই `Stat`-গুলো ছাঁকনির বাইরে ছিল। ⛔ পর্দায় দুইটা
         * সময়কালের সংখ্যা পাশাপাশি — আর পাঠক জানতেন না কোনটা কোনটার।
         *
         * ⚠️ **এটাই ঐ পুরনো দোষটার ফিরে আসা**, যেটার কথা নিচের মন্তব্যে
         * লেখা: *"আগে আজ ও এই মাস দুইটাই একসাথে দেখানো হত, আর পর্দার
         * উপরের অর্ধেকটা আটটা কার্ডে ভরে যেত।"* ⓘ কার্ডে সারানো
         * হয়েছিল, এখানে পৌঁছায়নি।
         *
         * ⭐ ডিফল্ট `today` — কেউ কিছু না বাছলে আচরণ আগের মতোই।
         */
        $period = in_array(request('period'), Widget::PERIODS, true)
            ? (string) request('period')
            : 'today';

        [$sold, $collected] = match ($period) {
            'year' => [SalesMetrics::salesThisYear(), SalesMetrics::collectedThisYear()],
            'month' => [SalesMetrics::salesThisMonth(), SalesMetrics::collectedThisMonth()],
            default => [SalesMetrics::salesToday(), SalesMetrics::collectedToday()],
        };

        $today = $sold;
        $month = SalesMetrics::salesThisMonth();
        $collectedToday = $collected;
        $collectedMonth = SalesMetrics::collectedThisMonth();

        // ⭐ লক্ষ্য বনাম আদায় — রিপোর্টের একই হিসাব (মালিক, ১৪ অক্টোবর ২০২৬; [[PartyTargetReport::thisMonth()]])
        // ⓘ কেবল যাঁর কাছে রিপোর্টটা খোলে (হিসাব বা বিক্রয়ের চাবি, যেকোনো একটা) — ঘর আর তালিকা দুইটাই তাঁর
        $versus = app(\App\Core\Engines\Report\ReportEngine::class)->get(\App\Modules\Sales\Reports\PartyTargetReport::KEY)->allows(auth()->user())
            ? \App\Modules\Sales\Reports\PartyTargetReport::thisMonth()
            : null;

        // ⭐ পিছিয়ে থাকা গ্রাহকের "পয়েন্ট · শাখা" — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম); পাঁচ সারির এক প্রশ্নে, সারি ধরে নয়
        $laggingPlaces = $versus === null ? [] : app(PartyRegistry::class)->pointAndBranchOf(
            $versus['lagging']->map(fn ($r) => [Customer::drillSourceType(), (int) $r->source_id])->all(),
        );

        return new DashboardDefinition(
            title: __('sales::dashboard.title'),
            subtitle: __('sales::dashboard.subtitle'),

            tiles: [
                /*
                 * ⭐ প্রথম বোতামটা সরাসরি বিক্রয় — মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬।
                 *
                 * ⓘ *"New invoice name kicui thakbena eta direct sales hobe"* —
                 * কাউন্টারে দিনের কাজটা বিল লেখা নয়, **মাল বেচা** — আর
                 * সরাসরি বিক্রয়ে এক চাপেই চালান, বিল আর জমা তিনটাই হয়।
                 * ⛔ বিলের ফর্ম আলাদা কাজ: মাল আগেই গেছে, এখন কেবল কাগজ।
                 *
                 * ⚠️ পর্দাটা বন্ধ রাখা যায় (`sales.screen_direct`), আর মেনুর সারিটা
                 * সেটা মানে — তাই টাইলটাও মানে। ⛔ নাহলে যে পর্দাটা লুকিয়ে
                 * রাখা হয়েছে, ড্যাশবোর্ড তার দরজা খুলে রাখত, আর সেটা
                 * মালিকের নিজের সিদ্ধান্তকেই অগ্রাহ্য করত। ⓘ বন্ধ থাকলে বিলের
                 * বোতামটাই থাকে, আগের মতো।
                 */
                self::firstAction(),
                new Tile(
                    label: __('sales::action.new_order'),
                    href: route('sales.order.create'),
                    permission: 'sales.order.create',
                    icon: 'plus',
                ),
                new Tile(
                    label: __('sales::action.new_collection'),
                    href: route('sales.collection.create'),
                    permission: 'sales.collection.create',
                    icon: 'cash',
                ),
                new Tile(
                    label: __('sales::menu.invoices'),
                    href: route('sales.invoice.index'),
                    permission: 'sales.invoice.view',
                    icon: 'reports',
                ),
            ],

            stats: [
                /*
                 * ── কেন আজ ও এই মাস, দুইটাই ──────────────────────────
                 * আজকের সংখ্যাটা দিনের কাজের, মাসেরটা লক্ষ্যের। একটা
                 * দেখালে সকালবেলা পর্দাটা প্রায় খালি দেখাত (দিন শুরু
                 * হয়নি), আর কেউ ভাবতেন ব্যবসা বন্ধ।
                 */
                new Stat(
                    label: $today->label,
                    value: Money::format($today->value()),
                    // ⭐ নিট বিক্রি হলে বিল আর ফেরত আলাদা (IFRS 15, ২০ অক্টোবর ২০২৬)
                    hint: $today->key === 'sales.today'
                        ? SalesMetrics::netPartsLine(Carbon::today()->toDateString(), Carbon::today()->toDateString())
                        : __('sales::dashboard.today_hint'),
                    href: route('sales.invoice.index', ['view' => 'today']),
                    tone: Stat::GOOD,
                ),

                new Stat(
                    label: $month->label,
                    value: Money::format($month->value()),
                    hint: __('sales::dashboard.month_hint'),
                    href: route('sales.invoice.index'),
                ),

                new Stat(
                    label: $collectedToday->label,
                    value: Money::format($collectedToday->value()),
                    hint: __('sales::dashboard.collected_hint'),
                    href: route('sales.collection.index'),
                    tone: Stat::GOOD,
                ),

                /*
                 * ⭐ এ মাসের লক্ষ্য বনাম আদায় — মালিক, ১৪ অক্টোবর ২০২৬: *"eta sales er dashboard eo daw"*। রঙ: ১০০% বা বেশি সবুজ,
                 * ৮০–৯৯ হলুদ, নিচে লাল; লক্ষ্য না থাকলে নিরপেক্ষ। চাপলে পুরো রিপোর্ট।
                 */
                ...($versus === null ? [] : [new Stat(
                    label: __('sales::party_target.tile'),
                    value: $versus['percent'] === null ? '—' : $versus['percent'].'%',
                    hint: __('sales::party_target.tile_hint', ['inflow' => Money::format($versus['inflow']), 'target' => Money::format($versus['target'])]),
                    href: route('accounts.report.show', ['slug' => 'party-target']),
                    tone: match (true) {
                        $versus['percent'] === null => Stat::NEUTRAL,
                        bccomp($versus['percent'], \App\Modules\Sales\Reports\PartyTargetReport::MET_AT, 1) >= 0 => Stat::GOOD,
                        bccomp($versus['percent'], \App\Modules\Sales\Reports\PartyTargetReport::NEAR_AT, 1) >= 0 => Stat::WARN,
                        default => Stat::BAD,
                    },
                )]),

                /*
                 * ⚠️ বকেয়া বাড়া খারাপ খবর, তাই `BAD` — আর সেটাই তীরের
                 * রংও ঠিক করে। দিক দেখে রং দিলে "বকেয়া ▲২১%" সবুজ হত।
                 */
                new Stat(
                    label: __('sales::dashboard.outstanding'),
                    value: Money::format(self::outstanding()),
                    hint: __('sales::dashboard.outstanding_hint'),
                    href: route('sales.invoice.index', ['view' => 'due']),
                    tone: Stat::BAD,
                ),
            ],

            panels: [
                new Series(
                    // ⭐ নতুন ড্যাশবোর্ডে জানুয়ারি–ডিসেম্বর (মালিক, ২ অক্টোবর ২০২৬); সুইচ বন্ধ থাকলে আগের মতো ছয় মাস
                    label: config('abos.dashboards_v2')
                        ? __('sales::dashboard.this_year_months', ['year' => Carbon::today()->year])
                        : __('sales::dashboard.six_months'),
                    points: self::monthly(),
                    firstLabel: __('sales::dashboard.billed'),
                    secondLabel: __('sales::dashboard.collected'),
                    // ⓘ বিল বনাম আদায় — পাশাপাশি স্তম্ভই আন্তর্জাতিক রীতি (মালিক, ৪ অক্টোবর ২০২৬: "vino rokomer graph")
                    chart: 'bars',
                    // ⭐ কোন তারিখ থেকে কোন তারিখ (মালিক, ৫ অক্টোবর ২০২৬) — নতুন রূপে বছরের শুরু, পুরনোয় ছয় মাস আগের ১ তারিখ
                    range: \App\Core\Engines\Dashboard\DateRange::label(
                        config('abos.dashboards_v2') ? Carbon::today()->startOfYear() : Carbon::today()->startOfMonth()->subMonths(5),
                        Carbon::today()),
                ),

                new Breakdown(
                    label: __('sales::dashboard.where_bills_stand'),
                    parts: self::byStatus(),
                    hint: __('sales::dashboard.status_hint'),
                ),
                // ⭐ নতুন চার্ট (বিক্রয়ের ফানেল) — আলাদা ফাইলে ([[SalesCharts]]), নতুন ড্যাশবোর্ডে
                ...SalesCharts::all(),
            ],

            listings: [
                new Listing(
                    label: __('sales::dashboard.biggest_dues'),
                    columns: [
                        ['key' => 'no', 'label' => __('sales::field.document_no'),
                            'render' => fn ($i) => $i->document_no],
                        ['key' => 'party', 'label' => __('sales::field.customer'),
                            'render' => fn ($i) => $i->customer?->name() ?? '—'],
                        // ⭐ গ্রাহকের পরে পয়েন্ট (মালিক, ২৮ সেপ্টেম্বর ও ১৯ অক্টোবর ২০২৬) — পাশের "পিছিয়ে থাকা" তালিকার একই কলাম
                        // ⭐ শাখাও — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম): "পয়েন্ট · শাখা" ([[Customer::placeName()]])
                        ['key' => 'point', 'label' => __('customer::field.place'),
                            'render' => fn ($i) => $i->customer?->placeName() ?? '—'],
                        ['key' => 'due', 'label' => __('sales::dashboard.outstanding'), 'width' => '9rem',
                            'render' => fn ($i) => Money::format($i->dueAmount())],
                    ],
                    rows: self::biggestDues(),
                    empty: __('sales::dashboard.nothing_due'),
                    href: route('sales.invoice.index', ['view' => 'due']),
                ),
                // ⭐ সবচেয়ে পিছিয়ে থাকা পাঁচ গ্রাহক — এ মাসের লক্ষ্য বনাম আদায় (মালিক, ১৪ অক্টোবর ২০২৬)
                ...($versus === null ? [] : [new Listing(
                    label: __('sales::party_target.lagging'),
                    columns: [
                        ['key' => 'customer', 'label' => __('sales::party_target.customer'), 'render' => fn ($r) => (string) $r->customer],
                        ['key' => 'point', 'label' => __('customer::field.place'),
                            'render' => fn ($r) => $laggingPlaces[Customer::drillSourceType().':'.(int) $r->source_id] ?? (string) ($r->point ?? '—')],
                        ['key' => 'inflow', 'label' => __('sales::party_target.inflow'), 'width' => '8rem', 'render' => fn ($r) => Money::format($r->inflow)],
                        ['key' => 'target', 'label' => __('sales::party_target.target'), 'width' => '8rem', 'render' => fn ($r) => Money::format($r->target)],
                        ['key' => 'percent', 'label' => __('sales::party_target.percent'), 'width' => '5rem',
                            'render' => fn ($r) => (\App\Modules\Sales\Reports\PartyTargetReport::percent((string) $r->inflow, (string) $r->target) ?? '—').'%'],
                    ],
                    rows: $versus['lagging'],
                    empty: __('sales::party_target.nobody_lagging'),
                    href: route('accounts.report.show', ['slug' => 'party-target']),
                )]),
            ],
        );
    }

    /**
     * মোট বকেয়া — গ্রাহকদের কাছে আজ সত্যিই যা পাওনা।
     *
     * ⛔ আগে "নিশ্চিত বিলের মোট" — আদায় আর ফেরত বাদ যেত না, তাই ডেমোতে ২৩,৭৮,৭৯০ দেখাত অথচ খাতার পাওনা ১৬,১০,২০৫
     * (অডিট ⛔১১, ৬ অক্টোবর ২০২৬)। ⭐ এখন হোমের "বাজারে বকেয়া" আর ফোনের একই উৎস ([[CustomerMetrics::dues()]]):
     * প্রতিটা দোকানের নিজের জের, তারপর ধনাত্মকগুলোর যোগ; শাখা আর বিক্রয়কর্মীর দেয়াল সেখানেই।
     */
    private static function outstanding(): string
    {
        return app(\App\Modules\Customer\Services\CustomerMetrics::class)
            ->dues(auth()->user(), Carbon::today()->toDateString())['amount'];
    }

    /**
     * ছয় মাসের বিল ও আদায়, পাশাপাশি।
     *
     * প্রতিটা মাস তালিকায় থাকে, লেনদেন না থাকলেও — নাহলে ফাঁকা মাস
     * চার্ট থেকে উধাও হত আর ছয়টার বদলে চারটা বার দেখা যেত।
     *
     * @return list<array{label: string, first: string, second: string}>
     */
    private static function monthly(): array
    {
        $out = [];

        /*
         * ⭐ নতুন ড্যাশবোর্ডে এ বছরের জানুয়ারি থেকে ডিসেম্বর — মালিকের নির্দেশ, ২ অক্টোবর ২০২৬
         * ("১২ মাসের দিবে January to Dec")। ভবিষ্যতের মাসগুলো শূন্য নিয়ে থাকে, যাতে বছরের ছকটা পুরো দেখা যায়।
         */
        $year = (bool) config('abos.dashboards_v2');
        $cursor = $year ? Carbon::today()->startOfYear() : Carbon::today()->startOfMonth()->subMonths(5);

        for ($i = 0; $i < ($year ? 12 : 6); $i++) {
            $from = $cursor->copy()->startOfMonth()->toDateString();
            $to = $cursor->copy()->endOfMonth()->toDateString();

            $billed = SalesInvoice::query()
                ->posted()
                ->whereBetween('trx_date', [$from, $to])
                ->sum('total');

            $out[] = [
                'label' => $cursor->translatedFormat('M'),
                'first' => (string) $billed,
                /*
                 * ⓘ কার্ডের গোনাই — আদায় + গ্রাহকের রসিদ ভাউচার, কেবল খাতায়
                 * বসা (১৯ সেপ্টেম্বর ২০২৬)। ⛔ আগে এখানে খসড়া আদায়ও গোনা হত।
                 */
                'second' => SalesMetrics::collectionTotal($from, $to),
            ];

            $cursor->addMonth();
        }

        return $out;
    }

    /**
     * কাগজগুলো কোন অবস্থায় দাঁড়িয়ে।
     *
     * ── কেন এটা দরকার ───────────────────────────────────────────────
     * "এই মাসে ৪২ লাখ বিক্রি" শুনতে ভালো, কিন্তু তার কতটা এখনো খসড়া?
     * খসড়া বিল কোনো টাকা নয় — মাল যায়নি, খাতায় বসেনি। ভাগটা না
     * দেখালে মাসের সংখ্যাটা প্রকৃতপক্ষের চেয়ে বড় শোনাত।
     *
     * @return list<array{label: string, value: string}>
     */
    private static function byStatus(): array
    {
        $rows = SalesInvoice::query()
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();

        $out = [];

        foreach (DocumentStatus::STAGES as $status) {
            $out[] = [
                'label' => __('core.status.'.$status),
                'value' => (string) ($rows[$status] ?? 0),
            ];
        }

        return $out;
    }

    /** সবচেয়ে বড় বকেয়াগুলো, উপরে। */
    /**
     * ⭐ সবচেয়ে বড় বকেয়া — বিলে এখনো যা বাকি, বড় থেকে ছোট (অডিট ⛔১১, ৬ অক্টোবর ২০২৬)।
     * ⛔ আগে বিলের মোট ধরে সাজানো আর মোটটাই দেখানো — শোধ হয়ে যাওয়া বড় বিলও তালিকার মাথায় বসত।
     * ⓘ বাকির হিসাব বিলের পাতার হুবহু ([[SalesInvoice::scopeWithCollected()]], [[SalesInvoice::dueAmount()]]):
     * মোট − আদায় − রসিদ ভাউচার − পাকা ফেরত; কোম্পানি আর শাখার দেয়াল ভেতরের কোয়েরিতেই।
     */
    private static function biggestDues()
    {
        $ranked = DB::query()
            ->fromSub(SalesInvoice::query()->posted()->withCollected(), 'i')
            ->selectRaw('i.id, (i.total - i.collected_total - i.voucher_total - i.returned_total - i.claimed_total) as due')
            ->whereRaw('(i.total - i.collected_total - i.voucher_total - i.returned_total - i.claimed_total) > 0')
            ->orderByDesc('due')
            ->orderBy('i.id')
            ->limit(8)
            ->pluck('id')
            ->all();

        return SalesInvoice::query()->withCollected()->with(['customer.location.parent', 'customer.branch'])->whereIn('sal_invoices.id', $ranked ?: [0])->get()
            ->sortBy(fn (SalesInvoice $i) => array_search($i->id, $ranked, true))
            ->values();
    }

    /**
     * প্রথম দ্রুত-কাজ — সরাসরি বিক্রয়, নয়তো নতুন বিল।
     *
     * ⓘ অনুমতিটা মেনুর সারির হুবহু একটাই (`sales.challan.create`) —
     * সরাসরি বিক্রয়ে মাল বেরোয়, তাই চালান কাটার চাবিটাই আসল।
     *
     * -- Why the fallback changed, 21 September 2026 -----------------
     * This tile used to fall back to a BLANK invoice form when the
     * direct-sale switch was off: no order behind it, no challan, and
     * nothing to reconcile the stock against.
     *
     * INV-0002 was made exactly that way -- an invoice for
     * 56,965,907,412 with no paper to check it against.
     *
     * The owner's rule: an invoice has two doors, an order and a
     * direct sale. So the fallback is now a new order, and the invoice
     * is born downstream of it (order -> challan -> invoice).
     */
    private static function firstAction(): Tile
    {
        $on = app(SettingsService::class)->get('sales.screen_direct', true);

        return $on
            ? new Tile(
                label: __('sales::menu.direct'),
                href: route('sales.direct.create'),
                permission: 'sales.challan.create',
                icon: 'sales',
            )
            : new Tile(
                label: __('sales::action.new_order'),
                href: route('sales.order.create'),
                permission: 'sales.order.create',
                icon: 'plus', // ⓘ সেটে 'clipboard' নেই — খালি ঘর আঁকত ([[IconSetTest]], ১১ অক্টোবর ২০২৬)
            );
    }
}
