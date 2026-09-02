<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * সিস্টেম প্রশাসন মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন এখানে "শেষ ব্যাকআপ কবে" সবচেয়ে জরুরি ─────────────────────────
 * বাকি সব সংখ্যা বলে ব্যবস্থাটা কত বড়। এটা বলে **ব্যবস্থাটা হারালে কী
 * ফেরানো যাবে** — আর ওই একটা প্রশ্নের ভুল উত্তরের দাম বাকি সবগুলোর
 * যোগফলের চেয়ে বেশি।
 *
 * ⚠️ ব্যাকআপের ব্যর্থতা **নীরব**: কেউ অভিযোগ করে না, কারণ কিছুই ভাঙে
 * না — যতক্ষণ না ফেরানোর দিন আসে। তাই সংখ্যাটা পর্দার উপরে, আর পুরনো
 * হলে লাল।
 */
final class SystemAdminDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $backup = self::lastBackup();

        return new DashboardDefinition(
            title: __('system_admin::dashboard.title'),
            subtitle: __('system_admin::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('system_admin::menu.users'), href: route('system_admin.user.index'),
                    permission: 'system_admin.user.manage', icon: 'people'),
                new Tile(label: __('system_admin::menu.roles'), href: route('system_admin.role.index'),
                    permission: 'system_admin.role.manage', icon: 'shield'),
                new Tile(label: __('system_admin::menu.backups'), href: route('system_admin.backup.index'),
                    permission: 'system_admin.settings.manage', icon: 'accounts'),
                new Tile(label: __('system_admin::menu.control_panel'), href: route('system_admin.control-panel'),
                    permission: 'system_admin.settings.manage', icon: 'settings'),
            ],

            stats: [
                /*
                 * দিনের সংখ্যা, তারিখ নয় — "৩ দিন আগে" পড়েই বোঝা যায়
                 * সমস্যা আছে কি না, আর "৩১/০৮/২০২৬" পড়ে মাথায় বিয়োগ
                 * করতে হয়। যে সংখ্যাটা ভাবতে বাধ্য করে, সেটা কেউ
                 * তৃতীয়বার দেখে না।
                 */
                new Stat(
                    label: __('system_admin::dashboard.last_backup'),
                    value: $backup === null
                        ? __('system_admin::dashboard.never')
                        : __('system_admin::dashboard.days_ago', ['days' => $backup]),
                    hint: __('system_admin::dashboard.last_backup_hint'),
                    href: route('system_admin.backup.index'),
                    tone: ($backup === null || $backup > 1) ? Stat::BAD : Stat::GOOD,
                ),

                new Stat(
                    label: __('system_admin::menu.users'),
                    value: (string) User::query()->count(),
                    hint: __('system_admin::dashboard.users_hint'),
                    href: route('system_admin.user.index'),
                ),

                new Stat(
                    label: __('system_admin::menu.roles'),
                    value: (string) Role::query()->count(),
                    hint: __('system_admin::dashboard.roles_hint'),
                    href: route('system_admin.role.index'),
                ),

                new Stat(
                    label: __('system_admin::menu.companies'),
                    value: (string) Company::query()->count(),
                    hint: __('system_admin::dashboard.companies_hint'),
                    href: route('system_admin.company.index'),
                ),
            ],

            listings: [
                new Listing(
                    label: __('system_admin::dashboard.newest_users'),
                    columns: [
                        ['key' => 'name', 'label' => __('system_admin::dashboard.name'),
                            'render' => fn ($u) => $u->name],
                        ['key' => 'email', 'label' => __('system_admin::dashboard.email'),
                            'render' => fn ($u) => $u->email],
                        ['key' => 'joined', 'label' => __('system_admin::dashboard.joined'), 'width' => '10rem',
                            'render' => fn ($u) => $u->created_at?->format('d M Y') ?? '—'],
                    ],
                    rows: User::query()->latest('id')->limit(8)->get(),
                    empty: __('system_admin::dashboard.no_users'),
                    href: route('system_admin.user.index'),
                ),
            ],
        );
    }

    /**
     * শেষ ব্যাকআপ কত দিন আগে — না থাকলে `null`।
     *
     * ── কেন ফাইল দেখে, খাতা দেখে নয় ─────────────────────────────────
     * একটা টেবিলে "ব্যাকআপ হয়েছে" লিখে রাখা যেত, কিন্তু সেটা বলত
     * **চেষ্টা হয়েছিল**, আর প্রশ্নটা হলো **ফাইলটা আছে কি না**। ওই
     * দুইটার পার্থক্য ঠিক সেদিন ধরা পড়ত যেদিন ফেরাতে হত।
     */
    private static function lastBackup(): ?int
    {
        $path = (string) config('abos.backup.path', env('ABOS_BACKUP_PATH', ''));

        if ($path === '' || ! is_dir($path)) {
            return null;
        }

        $newest = null;

        foreach (glob(rtrim($path, '/\\').'/*.sql.gz') ?: [] as $file) {
            $at = filemtime($file);

            if ($at !== false && ($newest === null || $at > $newest)) {
                $newest = $at;
            }
        }

        return $newest === null ? null : Carbon::createFromTimestamp($newest)->diffInDays(Carbon::now());
    }
}
