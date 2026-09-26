<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
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
        );
    }
}
