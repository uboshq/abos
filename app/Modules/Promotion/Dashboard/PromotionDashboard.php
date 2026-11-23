<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Viz\Card;
use App\Core\Dashboard\Viz\Svg;
use App\Core\Services\DataScope;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Series;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Support\Money;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Models\PromotionCoupon;
use App\Modules\Promotion\Models\PromotionCouponRedemption;
use App\Modules\Promotion\Models\PromotionGiftIssue;
use App\Modules\Promotion\Services\BudgetGuard;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * অফার মডিউলের ড্যাশবোর্ড।
 *
 * ── ⚠️ কেন এখানে আজ টাকার সংখ্যা নেই ────────────────────────────────
 * ⓘ স্পেকের §৪-এ পনেরোটা KPI, আর তার অর্ধেকই টাকার: কত ছাড় দেওয়া
 * হয়েছে, কত উপহার বেরিয়েছে, বাজেটের কতটা বাকি। ⛔ কিন্তু আজ একটাও
 * অফার কোনো বিলে বসেনি, তাই ঐ সংখ্যাগুলোর উত্তর **শূন্য** হত।
 *
 * ⚠️ আর একটা শূন্য KPI মিথ্যা বলে: মানুষ পড়েন *"এ মাসে কোনো ছাড় দেওয়া
 * হয়নি"*, অথচ সত্যিটা হলো *"গোনার যন্ত্রটাই এখনো নেই"*। ⭐ তাই ঘরগুলো
 * বসবে যেদিন গোনার জিনিসটা সত্যিই থাকবে — একদিন আগে নয়।
 *
 * ⭐ সেই দিন এসেছে (৬ অক্টোবর ২০২৬): অফার এখন বিলে বসে, উপহার গুদাম থেকে বেরোয়, কুপন ভাঙানো হয়।
 * ⓘ তাই নতুন ড্যাশবোর্ডে এ মাসের ছাড় আর উপহার, কুপন দেওয়া বনাম ভাঙানো, বাজেটের কতটা গেছে, আর চালু
 * অফারের তালিকা — সবই এই মডিউলের নিজের খাতা থেকে; ⛔ বিক্রয় এখানে আসে না (`depends_on`-এ নেই)।
 */
final class PromotionDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $today = Carbon::today();

        return new DashboardDefinition(
            title: __('promotion::dashboard.title'),
            subtitle: __('promotion::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('promotion::menu.promotions'), href: route('promotion.index'),
                    permission: 'promotion.view', icon: 'star'),
            ],

            stats: [
                new Stat(
                    label: __('promotion::dashboard.active'),
                    value: (string) Promotion::query()->liveOn($today)->count(),
                    hint: __('promotion::dashboard.active_hint'),
                ),

                /*
                 * ⭐ *"সামনে কী আসছে"* — অনুমোদিত, কিন্তু শুরু হয়নি।
                 *
                 * ⓘ সংখ্যাটা কাজের: বিক্রয়ের লোক আগে থেকে জানলে ক্রেতাকে
                 * বলে রাখতে পারেন। ⚠️ আর না জানলে অফার শুরুর দিনটাই
                 * নষ্ট হয়, কারণ কেউ জানে না ওটা শুরু হয়েছে।
                 */
                new Stat(
                    label: __('promotion::dashboard.upcoming'),
                    value: (string) self::upcoming($today)->count(),
                    hint: __('promotion::dashboard.upcoming_hint'),
                ),

                /*
                 * ⛔ মেয়াদ পেরিয়েছে, অথচ অবস্থা এখনো বলছে চলছে।
                 *
                 * ⚠️ এটা একটা **সতর্কতা**, গোনা নয়: ⓘ সংখ্যাটা শূন্যের
                 * বেশি মানে কোনো নির্ধারিত কাজ চলতে ভুলে গেছে, আর
                 * তালিকার পর্দা তখন সত্যি কথা বলছে না।
                 */
                new Stat(
                    label: __('promotion::dashboard.lapsed'),

                    /*
                     * ⓘ শর্তটা এখানে আবার লেখা হয় না — [[Promotion::scopeLapsed()]]।
                     * ⚠️ প্রথম খসড়ায় একই শর্ত হাতে SQL-এ দ্বিতীয়বার লেখা ছিল,
                     * আর দুই কপি একদিন আলাদা হত: মডেল বলত "পেরিয়েছে", পর্দা
                     * বলত "পেরোয়নি", আর কোনটা ঠিক তা কেউ জানত না।
                     */
                    value: (string) Promotion::query()->lapsed()->count(),
                    hint: __('promotion::dashboard.lapsed_hint'),
                    tone: Stat::WARN,
                ),

                ...self::givenThisMonth($today),
            ],

            // ⓘ প্রথম চার্ট আগের জায়গাতেই — হোমে মডিউলের প্রথম চার্টটা বসে; নতুনগুলো তার পরে (৬ অক্টোবর ২০২৬)
            panels: [...self::usedThisMonth(), ...self::endingSoon($today), ...self::coupons($today), ...self::budgets()],

            listings: self::liveOffers($today),

            // ⭐ খোলার পর্দায় ছয়টা গ্রাফ — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা boards15 "ok"); যে সংখ্যা-চার্ট গ্রাফে এল, পাতায় আর আলাদা নয়
            sections: [Card::board(fn (): array => self::charts($today), covers: [
                // ⓘ "শেষ হতে যাচ্ছে" (7/15/30 দিনের গোনা) আর বাজেটের টাকা গ্রাফে নেই — নিচে থাকে (নিরীক্ষা, ২৩ নভেম্বর ২০২৬: কিছু হারায় না)
                __('promotion::dashboard.active'), __('promotion::dashboard.upcoming'), __('promotion::dashboard.lapsed'),
                __('promotion::dashboard.coupons'),
                __('promotion::dashboard.discount_this_month'), __('promotion::dashboard.used_this_month'),
            ])],
        );
    }

    /**
     * ⭐ অফারের গ্রাফের বোর্ড — অফারগুলো কোথায় (ডোনাট), চালু অফার কবে শেষ (সময়রেখা), বাজেট বনাম খরচ (বুলেট), কুপন দেওয়া →
     *   ভাঙানো (ফানেল), ছয় মাসের ছাড় (স্তূপ-এলাকা), এ মাসে কোন অফার কতবার লাগল (লোলিপপ)।
     * ⓘ প্রতিটা গ্রাফ নিচের পুরনো ঘর-চার্টের একই কোয়েরি পড়ে; ⛔ কুপনের গ্রাফ কেবল `promotion.coupon`-এ, ছাড় দেখার শাখায়।
     *
     * @return list<array<string, mixed>>
     */
    private static function charts(Carbon $today): array
    {
        $cards = [];

        // ── অফারগুলো কোথায় — উপরের তিন সংখ্যা, আর থামানো ও শেষ হওয়া ───────────────────
        $count = fn (PromotionStatus $s) => Promotion::query()->where('status', $s->value)->count();
        $where = [
            [__('promotion::dashboard.active'), Promotion::query()->liveOn($today)->count(), Svg::STATUS[0]],
            [__('promotion::dashboard.upcoming'), self::upcoming($today)->count(), '#2160d8'],
            [__('promotion::dashboard.viz_paused'), $count(PromotionStatus::PAUSED), Svg::STATUS[1]],
            [__('promotion::dashboard.lapsed'), Promotion::query()->lapsed()->count(), Svg::STATUS[2]],
            [__('promotion::dashboard.viz_ended'), $count(PromotionStatus::EXPIRED), '#94a3b8'],
        ];
        $offers = array_sum(array_column($where, 1));
        $cards[] = Card::make(__('promotion::dashboard.viz_where'), 'donut', [
            'c' => number_format($offers), 'cs' => __('promotion::dashboard.viz_offers'),
            'p' => array_map(fn (array $p) => [$p[0], $p[1], $p[2], number_format($p[1])], $where),
        ], value: $offers === 0 ? '' : __('promotion::dashboard.viz_n_offers', ['n' => number_format($offers)]),
            sub: __('promotion::dashboard.viz_where_sub'), href: route('promotion.index'), colour: '#7c3aed');

        // ── চালু অফার কবে শেষ — আজ থেকে ছয় সপ্তাহের ছকে, আগে যেটা শেষ হবে সেটা উপরে ──────────────
        $live = Promotion::query()->liveOn($today)->orderBy('ends_on')->orderBy('id')->limit(6)->get();
        $cards[] = Card::make(__('promotion::dashboard.viz_ends'), 'gantt', $live->isEmpty() ? [] : [
            'ticks' => [__('promotion::dashboard.viz_today'), ...array_map(fn (int $w) => __('promotion::dashboard.viz_week', ['n' => $w]), range(1, 6))],
            'today' => 0,
            'p' => $live->map(function (Promotion $p) use ($today) {
                $days = (int) $today->diffInDays(Carbon::parse($p->ends_on));

                return [Str::limit($p->name(), 16), 0, min(1, max($days, 1) / 42), $days <= 7 ? Svg::STATUS[3] : null];
            })->all(),
        ], sub: __('promotion::dashboard.viz_ends_sub'), href: route('promotion.index'), colour: '#2160d8');

        // ── বাজেট বনাম খরচ — বাজেটের পাহারার নিজের হিসাব ([[BudgetGuard::usage()]]) ─────────────────
        $budgets = array_slice(self::budgetRows(), 0, 6);
        // ⓘ শিরোনাম নমুনার; টাকার অঙ্ক (বাজেট আর খরচ) নিচের "বাজেট ও খরচ — টাকায়" কলামে ([[budgets()]])
        $cards[] = Card::make(__('promotion::dashboard.viz_budget'), 'bullet', $budgets === [] ? [] : [
            'p' => array_map(fn (array $r) => [Str::limit($r['label'], 14), $r['percent'] / 100, 1], $budgets),
        ], sub: __('promotion::dashboard.viz_budget_sub'), href: route('promotion.index'), colour: '#f97316');

        // ── কুপন — ছয় মাসে দেওয়া → ভাঙানো (নিচের পুরনো রেখার একই দুই গোনা) ─────────────────────
        $coupons = auth()->user()?->can('promotion.coupon') ? self::couponMonths($today) : null;
        if ($coupons !== null) {
            $issued = array_sum(array_column($coupons, 'first'));
            $redeemed = array_sum(array_column($coupons, 'second'));
            $cards[] = Card::make(__('promotion::dashboard.coupons'), 'funnel', [
                'p' => [
                    [__('promotion::dashboard.coupons_issued'), $issued, number_format($issued)],
                    [__('promotion::dashboard.coupons_redeemed'), $redeemed, number_format($redeemed)],
                ],
            ], value: $issued > 0 ? round($redeemed * 100 / $issued).'%' : '', sub: __('promotion::dashboard.viz_coupons_sub'), colour: '#0ea5e9');
        }

        // ── ছাড় ও উপহার — ছয় মাস: শতকরা ছাড়, টাকার ছাড়, আর উপহারের মাল (উপরের "এ মাসে ছাড়"-এর একই নিয়ম, দেখার শাখায়) ────
        // ⛔ উপহারের `worth` মানে মালের কেনা দাম — কেবল খরচের চাবিতে (`inventory.cost.view`); চাবি না থাকলে উপহারের দাগ নেই,
        //   উপহারের গোনা ("এ মাসে উপহার বেরিয়েছে") নিচে আগের মতো
        $gifts = (bool) auth()->user()?->can('inventory.cost.view');
        $start = $today->copy()->startOfMonth()->subMonths(5);
        $expr = "DATE_FORMAT(created_at, '%Y-%m')";
        $kinds = [BenefitKind::PERCENT->value, BenefitKind::AMOUNT->value, ...($gifts ? [BenefitKind::GOODS->value] : [])];
        $given = self::applicationsSeen()
            ->whereNull('reversed_at')
            ->whereIn('benefit_kind', $kinds)
            ->where('created_at', '>=', $start)
            ->selectRaw("{$expr} as ym, benefit_kind as k, COALESCE(SUM(worth), 0) as total")
            ->groupByRaw("{$expr}, benefit_kind")
            ->toBase()->get();
        $series = array_fill_keys($kinds, []);
        $months = [];
        for ($month = $start->copy(); $month->lessThanOrEqualTo($today); $month->addMonth()) {
            $ym = $month->format('Y-m');
            foreach ($kinds as $k) {
                $series[$k][] = (string) ($given->first(fn ($r) => $r->ym === $ym && $r->k === $k)?->total ?? '0');
            }
            $months[] = $month->translatedFormat('M');
        }
        $percent = $series[BenefitKind::PERCENT->value];
        $amount = $series[BenefitKind::AMOUNT->value];
        $thisMonth = bcadd((string) end($percent), (string) end($amount), 4);
        $legend = [[__('promotion::dashboard.viz_percent'), Svg::STATUS[0]], [__('promotion::dashboard.viz_amount'), '#f59e0b']];
        if ($gifts) {
            $legend[] = [__('promotion::dashboard.viz_gifts'), '#7c3aed'];
        }
        $cards[] = Card::make(__('promotion::dashboard.viz_discount'), 'stackedArea', [
            'series' => array_values($series), 'colors' => [Svg::STATUS[0], '#f59e0b', '#7c3aed'], 'l' => $months,
        ], value: bccomp($thisMonth, '0', 4) === 0 ? '' : self::short($thisMonth),
            sub: __($gifts ? 'promotion::dashboard.viz_discount_sub' : 'promotion::dashboard.viz_discount_sub_no_cost'), legend: $legend, colour: '#16a34a');

        // ── ক্যাম্পেইনের ফল — এ মাসে কোন অফার কতবার লাগল ───────────────────────────────
        $used = self::usedRows();
        $cards[] = Card::make(__('promotion::dashboard.viz_used'), 'lollipop', [
            'v' => array_column($used, 'value'), 'l' => array_map(fn (array $u) => Str::limit($u['label'], 16), $used),
        ], sub: __('promotion::dashboard.viz_used_sub'), href: route('promotion.index'), colour: '#e8175d');

        return $cards;
    }

    /** ⭐ "আসছে" — অনুমোদিত বা চালু, কিন্তু শুরুর দিন সামনে; উপরের সংখ্যা আর বোর্ডের ডোনাটের একই শর্ত */
    private static function upcoming(Carbon $today): \Illuminate\Database\Eloquent\Builder
    {
        return Promotion::query()
            ->whereIn('status', [PromotionStatus::APPROVED->value, PromotionStatus::ACTIVE->value])
            ->whereDate('starts_on', '>', $today->toDateString());
    }

    /**
     * ⛔ অফারের প্রয়োগ, দেখার শাখায় — হেডারের শাখা আর নাগাল, প্রয়োগের নিজের শাখা ধরে (Promotion লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬)।
     */
    private static function applicationsSeen(): \Illuminate\Database\Eloquent\Builder
    {
        return app(DataScope::class)->inView(PromotionApplication::query(), 'promotion_applications.branch_id');
    }

    /** ⓘ নমুনার রূপ: কোটির ওপরে "৳X.XX কো", লাখের ওপরে "৳X.X লা", নিচে হাজারে "৳Xk" — ইংরেজি অঙ্ক, bcmath */
    private static function short(string $amount): string
    {
        $amount = $amount === '' ? '0' : $amount;
        $a = ltrim($amount, '-');
        $sign = bccomp($amount, '0', 4) < 0 ? '−' : '';

        return match (true) {
            bccomp($a, '10000000', 4) >= 0 => $sign.'৳'.bcdiv($a, '10000000', 2).' '.__('promotion::dashboard.crore'),
            bccomp($a, '100000', 4) >= 0 => $sign.'৳'.bcdiv($a, '100000', 1).' '.__('promotion::dashboard.lakh'),
            bccomp($a, '1000', 4) >= 0 => $sign.'৳'.bcdiv($a, '1000', 0).'k',
            default => $sign.'৳'.bcadd($a, '0', 0),
        };
    }

    /**
     * ⭐ এ মাসে কত টাকার ছাড় গেল, আর কয়টা উপহার বেরোল (মালিকের ড্যাশবোর্ড নকশা §৪, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ ছাড় = টাকার আর শতকরা ছাড়ের যে অফার বিলে লেগেছে ([[PromotionApplication]]), ফিরিয়ে নেওয়া (`reversed_at`) বাদ —
     * বাজেটের পাহারা ([[BudgetGuard]]) যে `worth` গোনে, ঠিক সেটাই, তাই দুই জায়গার অঙ্ক কখনো আলাদা হয় না।
     * ⓘ উপহার = এ মাসে গুদাম থেকে বেরোনো উপহারের চালান ([[PromotionGiftIssue]]); পুরোটা ফেরত এসেছে এমন চালান বাদ।
     * ⚠️ উপহার গোনা হয়, টাকায় নয় — উপহারের দাম মানে ক্রয়মূল্য, আর সেটা খরচের চাবির পেছনে।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Stat>
     */
    private static function givenThisMonth(Carbon $today): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $from = $today->copy()->startOfMonth();
        $range = DateRange::label($from, $today);

        /*
         * ⛔ দেখার শাখা — হেডারের শাখা আর নাগাল, প্রয়োগের নিজের শাখা ধরে (Promotion লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬;
         * [[ThePromotionDashboardStaysInMyBranchTest]])। ⓘ আগে ময়মনসিংহে সীমিত মানুষও গোটা কোম্পানির ছাড়ের টাকা আর উপহার দেখতেন।
         * ⓘ উপহারের পর্দার একই নিয়ম ([[PromotionGiftController::index()]])।
         */
        $seen = fn () => self::applicationsSeen();

        $discount = (string) $seen()
            ->whereNull('reversed_at')
            ->whereIn('benefit_kind', [BenefitKind::PERCENT->value, BenefitKind::AMOUNT->value])
            ->where('created_at', '>=', $from)
            ->sum('worth');

        $gifts = PromotionGiftIssue::query()
            ->whereIn('promotion_application_id', $seen()->select('promotion_applications.id'))
            ->where('issued_at', '>=', $from)
            ->whereColumn('returned_qty', '<', 'qty')
            ->count();

        return [
            new Stat(
                label: __('promotion::dashboard.discount_this_month'),
                value: Money::format($discount),
                hint: __('promotion::dashboard.discount_this_month_hint', ['range' => $range]),
            ),
            new Stat(
                label: __('promotion::dashboard.gifts_this_month'),
                value: (string) $gifts,
                hint: __('promotion::dashboard.gifts_this_month_hint', ['range' => $range]),
                href: route('promotion.gift.index'),
            ),
        ];
    }

    /**
     * ⭐ কুপন — মাসে মাসে কতগুলো দেওয়া হলো, কতবার ভাঙানো হলো, গত ছয় মাস (মালিকের ড্যাশবোর্ড নকশা §৪, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ দেওয়া = কুপনটা যে মাসে তৈরি ([[PromotionCoupon]]); ভাঙানো = যে মাসে বিলে বসেছে ([[PromotionCouponRedemption]]),
     * ফিরিয়ে নেওয়া (`reversed_at`) বাদ। ⓘ একটা কুপন কয়েকবার ভাঙানো যায় (`max_uses`), তাই ভাঙানো দেওয়ার চেয়ে বেশি হতে পারে।
     * ⛔ কেবল `promotion.coupon` — কুপনের পর্দা যে চাবিতে খোলে। ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Series>
     */
    private static function coupons(Carbon $today): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('promotion.coupon')) {
            return [];
        }

        $start = $today->copy()->startOfMonth()->subMonths(5);

        return [new Series(
            label: __('promotion::dashboard.coupons'),
            points: array_map(fn (array $p) => ['label' => $p['label'], 'first' => (string) $p['first'], 'second' => (string) $p['second']], self::couponMonths($today)),
            firstLabel: __('promotion::dashboard.coupons_issued'),
            secondLabel: __('promotion::dashboard.coupons_redeemed'),
            chart: 'line',
            range: DateRange::label($start, $today),
        )];
    }

    /**
     * কুপন, মাস ধরে, গত ছয় মাস — `first` দেওয়া, `second` ভাঙানো (পূর্ণসংখ্যা)। নিচের রেখা আর বোর্ডের ফানেলের একই গোনা।
     *
     * @return list<array{label: string, first: int, second: int}>
     */
    private static function couponMonths(Carbon $today): array
    {
        $start = $today->copy()->startOfMonth()->subMonths(5);

        $issued = PromotionCoupon::query()
            ->where('created_at', '>=', $start->toDateTimeString())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as n")
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
            ->toBase()->pluck('n', 'ym');

        $redeemed = PromotionCouponRedemption::query()
            ->whereNull('reversed_at')
            ->where('redeemed_at', '>=', $start->toDateTimeString())
            ->selectRaw("DATE_FORMAT(redeemed_at, '%Y-%m') as ym, COUNT(*) as n")
            ->groupByRaw("DATE_FORMAT(redeemed_at, '%Y-%m')")
            ->toBase()->pluck('n', 'ym');

        $points = [];

        for ($month = $start->copy(); $month->lessThanOrEqualTo($today); $month->addMonth()) {
            $key = $month->format('Y-m');
            $points[] = [
                'label' => $month->translatedFormat('M'),
                'first' => (int) ($issued[$key] ?? 0),
                'second' => (int) ($redeemed[$key] ?? 0),
            ];
        }

        return $points;
    }

    /**
     * ⭐ বাজেট বনাম খরচ — যে অফারের টাকার ছাদ আছে, তার কতটা গেছে (মালিকের ড্যাশবোর্ড নকশা §৪, ৬ অক্টোবর ২০২৬)।
     *
     * ⛔ "কতটা গেছে" এখানে নতুন করে গোনা হয় না — [[BudgetGuard::usage()]] বলে, আর সে-ই বিল থামায়; বাজেটের রিপোর্টও
     * একই পথে গোনে। ⚠️ আলাদা SUM লিখলে একদিন পর্দা বলত "বাকি আছে" অথচ পাহারা বিল আটকাত।
     * ⓘ কেবল অনুমোদিত, চালু আর থামানো অফার; কেবল গোটা অফারের ছাদ (`per = offer`) — বিল, গ্রাহক বা দিনের ছাদের
     * "গেছে" একটা সংখ্যা নয়। ⓘ পরিমাণের ছাদ (পিস) বাদ: টাকা আর পিস এক দণ্ডে বসে না।
     * ⓘ সবচেয়ে বেশি ভরা ছয়টা। ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Series>
     */
    private static function budgets(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $rows = self::budgetRows();

        if ($rows === []) {
            return [];
        }

        return [new Series(
            label: __('promotion::dashboard.budget_used'),
            points: array_map(fn (array $r) => ['label' => $r['label'], 'first' => $r['first'], 'second' => $r['second']], array_slice($rows, 0, 6)),
            firstLabel: __('promotion::dashboard.budget_ceiling'),
            secondLabel: __('promotion::dashboard.budget_spent'),
            // ⓘ টাকা — মাউস/কিবোর্ডে পুরো অঙ্কের পরে "টাকা" ([[Series::$money]], ২০ অক্টোবর ২০২৬)
            money: true,
            chart: 'bars',
        )];
    }

    /**
     * টাকার ছাদের অফার, সবচেয়ে বেশি ভরা আগে — নিচের পুরনো কলাম আর বোর্ডের বুলেটের একই সারি ([[BudgetGuard::usage()]])।
     *
     * @return list<array{label: string, first: string, second: string, percent: int}>
     */
    private static function budgetRows(): array
    {
        $offers = Promotion::query()
            ->whereIn('status', [PromotionStatus::APPROVED->value, PromotionStatus::ACTIVE->value, PromotionStatus::PAUSED->value])
            ->whereIn('id', PromotionBudget::query()->select('promotion_id'))
            ->get();

        $guard = app(BudgetGuard::class);
        $rows = [];

        foreach ($offers as $offer) {
            $money = array_values(array_filter(
                $guard->usage($offer),
                fn (array $u) => $u['used'] !== null && $u['budget']->kind !== PromotionBudget::QUANTITY,
            ));

            foreach ($money as $u) {
                $rows[] = [
                    'label' => count($money) > 1
                        ? $offer->code.' ('.__('promotion::budget.'.$u['budget']->kind).')'
                        : (string) $offer->code,
                    'first' => Money::round((string) $u['budget']->ceiling),
                    'second' => Money::round((string) $u['used']),
                    'percent' => (int) $u['percent'],
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => [$b['percent'], $a['label']] <=> [$a['percent'], $b['label']]);

        return $rows;
    }

    /**
     * ⭐ এখন চলছে — চালু অফারের তালিকা, যেটা আগে শেষ হবে সেটা আগে (মালিকের ড্যাশবোর্ড নকশা §৪, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ "চালু" মানে উপরের "এখন চলছে" ঘরের একই শর্ত ([[Promotion::scopeLiveOn()]]), তাই তালিকা আর সংখ্যা মেলে।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Listing>
     */
    private static function liveOffers(Carbon $today): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        return [new Listing(
            label: __('promotion::dashboard.live_list'),
            columns: [
                ['key' => 'code', 'label' => __('promotion::dashboard.col_code'), 'width' => '7rem',
                    'render' => fn (Promotion $p) => (string) $p->code],
                ['key' => 'name', 'label' => __('promotion::dashboard.col_name'),
                    'render' => fn (Promotion $p) => $p->name()],
                ['key' => 'type', 'label' => __('promotion::dashboard.col_type'), 'width' => '10rem',
                    'render' => fn (Promotion $p) => $p->type?->label() ?? '—'],
                ['key' => 'ends', 'label' => __('promotion::dashboard.col_ends'), 'width' => '8rem',
                    'render' => fn (Promotion $p) => DateRange::label($p->ends_on, $p->ends_on)],
                ['key' => 'left', 'label' => __('promotion::dashboard.col_days_left'), 'width' => '6rem',
                    'render' => fn (Promotion $p) => (string) (int) $today->diffInDays(Carbon::parse($p->ends_on))],
            ],
            rows: Promotion::query()->liveOn($today)->orderBy('ends_on')->orderBy('id')->limit(10)->get(),
            empty: __('promotion::dashboard.live_list_empty'),
            href: route('promotion.index'),
        )];
    }

    /**
     * ⭐ ক্যাম্পেইনের ফল — এ মাসে কোন অফার কতবার লাগল (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ অফার যে খাতায় লেগেছে সেটাই ([[PromotionApplication]]) — ফিরিয়ে নেওয়া (`reversed_at`) বাদ। গোনা, টাকা নয়:
     * অফারের `worth` গিফটের ক্রয়মূল্য থেকে আসে, আর ক্রয়মূল্য খরচের চাবির পেছনে।
     * ⓘ "আগে বনাম চলাকালীন বিক্রি" এখানে নয় — এই মডিউল বিক্রয় চেনে না (`depends_on`), চেনা উচিতও নয়।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function usedThisMonth(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $rows = self::usedRows();

        if ($rows === []) {
            return [];
        }

        return [new Breakdown(
            label: __('promotion::dashboard.used_this_month'),
            parts: array_map(fn (array $r) => ['label' => $r['label'], 'value' => (string) $r['value']], $rows),
            hint: __('promotion::dashboard.used_this_month_hint'),
            // ⓘ সময়ের চার্ট — কোন দিন থেকে কোন দিন, শিরোনামের পাশে (মালিক, ৫ অক্টোবর ২০২৬; বসানো ৬ অক্টোবর ২০২৬)
            range: DateRange::label(Carbon::today()->startOfMonth(), Carbon::today()),
        )];
    }

    /**
     * এ মাসে কোন অফার কতবার লাগল, বড় ছয়টা — নিচের পুরনো চার্ট আর বোর্ডের লোলিপপের একই গোনা।
     * ⛔ দেখার শাখা — উপরের ছাড়ের ঘরের একই দেয়াল (Promotion লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬)।
     *
     * @return list<array{label: string, value: int}>
     */
    private static function usedRows(): array
    {
        $rows = self::applicationsSeen()
            ->whereNull('reversed_at')
            ->where('created_at', '>=', Carbon::today()->startOfMonth())
            ->selectRaw('promotion_id, COUNT(*) as n')
            ->groupBy('promotion_id')
            ->orderByDesc('n')
            ->limit(6)
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = Promotion::query()->whereKey($rows->pluck('promotion_id')->all())->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'label' => $names[$r->promotion_id]?->name() ?? '—',
            'value' => (int) $r->n,
        ])->values()->all();
    }

    /**
     * ⭐ শেষ হতে যাচ্ছে — চালু অফার কত দিনের মধ্যে ফুরাবে (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ "চালু" মানে উপরের "এখন চলছে" ঘরের একই শর্ত ([[Promotion::scopeLiveOn()]]); প্রতিটা অফার ঠিক একটা ভাগে।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function endingSoon(Carbon $today): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $days = Promotion::query()->liveOn($today)->pluck('ends_on')
            ->map(fn ($end) => (int) $today->diffInDays(Carbon::parse($end)));

        return [new Breakdown(
            label: __('promotion::dashboard.ending_soon'),
            parts: [
                ['label' => __('promotion::dashboard.within_7'), 'value' => (string) $days->filter(fn ($d) => $d <= 7)->count()],
                ['label' => __('promotion::dashboard.within_15'), 'value' => (string) $days->filter(fn ($d) => $d > 7 && $d <= 15)->count()],
                ['label' => __('promotion::dashboard.within_30'), 'value' => (string) $days->filter(fn ($d) => $d > 15 && $d <= 30)->count()],
                ['label' => __('promotion::dashboard.later'), 'value' => (string) $days->filter(fn ($d) => $d > 30)->count()],
            ],
            hint: __('promotion::dashboard.ending_soon_hint'),
        )];
    }
}
