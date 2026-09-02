<?php

declare(strict_types=1);

namespace App\Modules\Governance\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Models\AuditTrail;
use App\Models\ExportLog;
use Illuminate\Support\Carbon;

/**
 * নিয়ন্ত্রণ ও নিরীক্ষা মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন এখানে সংখ্যাগুলো "কত হয়েছে", "কত টাকা" নয় ───────────────────
 * বাকি মডিউল ব্যবসার কথা বলে; এই মডিউলের প্রশ্ন **কে কী করল**। তাই
 * সংখ্যাগুলো ঘটনার: আজ কতগুলো বদল হয়েছে, কে কী নামিয়ে নিয়ে গেছে।
 */
final class GovernanceDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $today = Carbon::today()->toDateString();

        return new DashboardDefinition(
            title: __('governance::dashboard.title'),
            subtitle: __('governance::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('governance::menu.audit'), href: route('governance.audit.index'),
                    permission: 'governance.audit.view', icon: 'shield'),
                new Tile(label: __('governance::menu.logins'), href: route('governance.login.index'),
                    permission: 'governance.audit.view', icon: 'people'),
                new Tile(label: __('governance::menu.exports'), href: route('governance.export.index'),
                    permission: 'governance.audit.view', icon: 'report'),
            ],

            stats: [
                new Stat(
                    label: __('governance::dashboard.changes_today'),
                    value: (string) AuditTrail::query()->whereDate('created_at', $today)->count(),
                    hint: __('governance::dashboard.changes_hint'),
                    href: route('governance.audit.index'),
                ),
                new Stat(
                    label: __('governance::dashboard.changes_total'),
                    value: (string) AuditTrail::query()->count(),
                    hint: __('governance::dashboard.changes_total_hint'),
                    href: route('governance.audit.index'),
                ),
                /*
                 * রপ্তানি আলাদা করে গোনা হয়, কারণ প্রশ্নটা আলাদা:
                 * বদল বলে কে কী **লিখল**, রপ্তানি বলে কে কী **নিয়ে
                 * গেল**। দ্বিতীয়টা তথ্য বেরিয়ে যাওয়ার একমাত্র হিসাব।
                 */
                new Stat(
                    label: __('governance::dashboard.exports'),
                    value: (string) ExportLog::query()->count(),
                    hint: __('governance::dashboard.exports_hint'),
                    href: route('governance.export.index'),
                    tone: Stat::WARN,
                ),
            ],

            listings: [
                new Listing(
                    label: __('governance::dashboard.latest_changes'),
                    columns: [
                        ['key' => 'when', 'label' => __('governance::field.when'), 'width' => '11rem',
                            'render' => fn ($t) => $t->created_at?->format('d M Y, H:i') ?? '—'],
                        ['key' => 'who', 'label' => __('governance::field.who'), 'width' => '10rem',
                            'render' => fn ($t) => $t->user?->name ?? __('governance::message.system')],
                        ['key' => 'what', 'label' => __('governance::field.action'),
                            'render' => fn ($t) => $t->title()],
                    ],
                    rows: AuditTrail::query()->with('user')->latest('id')->limit(8)->get(),
                    empty: __('governance::message.nothing_yet'),
                    href: route('governance.audit.index'),
                ),
            ],
        );
    }
}
