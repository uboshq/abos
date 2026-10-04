<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Modules\Promotion\Models\Promotion;
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
            ],

            panels: [...self::usedThisMonth(), ...self::endingSoon($today)],
        );
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
