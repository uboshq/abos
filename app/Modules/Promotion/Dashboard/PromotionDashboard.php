<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
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
                    value: (string) Promotion::query()
                        ->whereIn('status', [PromotionStatus::APPROVED->value, PromotionStatus::ACTIVE->value])
                        ->whereDate('starts_on', '>', $today->toDateString())
                        ->count(),
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
        );
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

        $discount = (string) PromotionApplication::query()
            ->whereNull('reversed_at')
            ->whereIn('benefit_kind', [BenefitKind::PERCENT->value, BenefitKind::AMOUNT->value])
            ->where('created_at', '>=', $from)
            ->sum('worth');

        $gifts = PromotionGiftIssue::query()
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
                'first' => (string) (int) ($issued[$key] ?? 0),
                'second' => (string) (int) ($redeemed[$key] ?? 0),
            ];
        }

        return [new Series(
            label: __('promotion::dashboard.coupons'),
            points: $points,
            firstLabel: __('promotion::dashboard.coupons_issued'),
            secondLabel: __('promotion::dashboard.coupons_redeemed'),
            chart: 'line',
            range: DateRange::label($start, $today),
        )];
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

        if ($rows === []) {
            return [];
        }

        usort($rows, fn (array $a, array $b) => [$b['percent'], $a['label']] <=> [$a['percent'], $b['label']]);

        return [new Series(
            label: __('promotion::dashboard.budget_used'),
            points: array_map(fn (array $r) => ['label' => $r['label'], 'first' => $r['first'], 'second' => $r['second']], array_slice($rows, 0, 6)),
            firstLabel: __('promotion::dashboard.budget_ceiling'),
            secondLabel: __('promotion::dashboard.budget_spent'),
            chart: 'bars',
        )];
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

        $rows = \App\Modules\Promotion\Models\PromotionApplication::query()
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

        return [new Breakdown(
            label: __('promotion::dashboard.used_this_month'),
            parts: $rows->map(fn ($r) => [
                'label' => $names[$r->promotion_id]?->name() ?? '—',
                'value' => (string) (int) $r->n,
            ])->all(),
            hint: __('promotion::dashboard.used_this_month_hint'),
            // ⓘ সময়ের চার্ট — কোন দিন থেকে কোন দিন, শিরোনামের পাশে (মালিক, ৫ অক্টোবর ২০২৬; বসানো ৬ অক্টোবর ২০২৬)
            range: DateRange::label(Carbon::today()->startOfMonth(), Carbon::today()),
        )];
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
