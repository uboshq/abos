<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\ErrorEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
                    permission: 'system_admin.role.manage', icon: 'lock'),
                new Tile(label: __('system_admin::menu.backup'), href: route('backup.index'),
                    permission: 'system_admin.settings.manage', icon: 'drawer'),
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
                    href: route('backup.index'),
                    tone: ($backup === null || $backup > 1) ? Stat::BAD : Stat::GOOD,
                ),

                new Stat(
                    label: __('system_admin::menu.users'),
                    // ⛔ সংখ্যাটাও কেবল এই কোম্পানির — নাহলে কার্ডে সবার যোগফল বসত
                    value: (string) User::query()->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))->count(),
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

                ...self::backgroundJobs(),
            ],

            // ⓘ প্রথম চার্ট আগের জায়গাতেই — হোমে মডিউলের প্রথম চার্টটা বসে; নতুনগুলো তার পরে (৬ অক্টোবর ২০২৬)
            panels: [...self::whoCanGetIn(), ...self::twoStep(), ...self::accessChanges(), ...self::errorsThisWeek()],

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
                    // ⛔ "নতুন ব্যবহারকারী" — অন্য কোম্পানির নাম এখানে বসত
                    rows: User::query()->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))->latest('id')->limit(8)->get(),
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
    /**
     * ⭐ ব্যবহারকারীর অবস্থা — এই কোম্পানির মানুষেরা কে সত্যিই ঢোকেন (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ প্রতিজন ঠিক একটা ভাগে: বন্ধ → কখনো ঢোকেননি → ৩০ দিন ঢোকেননি → সক্রিয়; যোগফল উপরের "ব্যবহারকারী" সংখ্যার সমান
     * (একই ছাঁকনি — এই কোম্পানির পিভট)। ⚠️ "৩০ দিন ঢোকেননি" খোলা দরজা: চালু লগইন যা কেউ দেখছেন না।
     * ⛔ কেবল `system_admin.user.manage` — ব্যবহারকারীর তালিকা যে চাবিতে খোলে।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function whoCanGetIn(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('system_admin.user.manage')) {
            return [];
        }

        $since = Carbon::now()->subDays(30);

        $row = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->selectRaw('SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as off')
            ->selectRaw('SUM(CASE WHEN is_active = 1 AND last_login_at IS NULL THEN 1 ELSE 0 END) as never')
            ->selectRaw('SUM(CASE WHEN is_active = 1 AND last_login_at < ? THEN 1 ELSE 0 END) as idle', [$since])
            ->selectRaw('SUM(CASE WHEN is_active = 1 AND last_login_at >= ? THEN 1 ELSE 0 END) as live', [$since])
            ->selectRaw('SUM(CASE WHEN mfa_secret IS NOT NULL AND mfa_confirmed_at IS NOT NULL THEN 1 ELSE 0 END) as two_step')
            ->toBase()->first();

        return [new Breakdown(
            label: __('system_admin::dashboard.who_gets_in'),
            parts: [
                ['label' => __('system_admin::dashboard.users_live'), 'value' => (string) (int) ($row->live ?? 0)],
                ['label' => __('system_admin::dashboard.users_idle'), 'value' => (string) (int) ($row->idle ?? 0)],
                ['label' => __('system_admin::dashboard.users_never'), 'value' => (string) (int) ($row->never ?? 0)],
                ['label' => __('system_admin::dashboard.users_off'), 'value' => (string) (int) ($row->off ?? 0)],
            ],
            hint: __('system_admin::dashboard.who_gets_in_hint', ['count' => (int) ($row->two_step ?? 0)]),
        )];
    }

    /**
     * ⭐ পেছনের কাজ — সারিতে অপেক্ষায় কতগুলো, ব্যর্থ কতগুলো (মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬)।
     *
     * ⚠️ `jobs` আর `failed_jobs` ফ্রেমওয়ার্কের টেবিল — **company_id কলামই নেই** ([[EveryRawQueryNamesItsCompanyTest]]-এর
     * NO_COMPANY_COLUMN)। ⓘ তাই সংখ্যাটা গোটা সার্ভারের, এক কোম্পানির নয়। ⛔ সেজন্য কেবল এই কোম্পানির সুপার অ্যাডমিন
     * দেখেন — বাকিদের কাছে অন্য কোম্পানির কাজও গোনায় চলে আসত। ⓘ কেবল গোনা; কাজের ভেতরের লেখা (payload) কখনো পড়া হয় না।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Stat>
     */
    private static function backgroundJobs(): array
    {
        if (! config('abos.dashboards_v2') || ! self::superAdminHere()) {
            return [];
        }

        // ⚠️ কোম্পানিহীন টেবিল — company_id নেই, তাই ছাঁকনিও নেই; দরজাটা উপরের সুপার অ্যাডমিনের প্রশ্ন
        $queued = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();

        return [
            new Stat(
                label: __('system_admin::dashboard.jobs_queued'),
                value: (string) $queued,
                hint: __('system_admin::dashboard.jobs_queued_hint'),
            ),
            new Stat(
                label: __('system_admin::dashboard.jobs_failed'),
                value: (string) $failed,
                hint: __('system_admin::dashboard.jobs_failed_hint'),
                tone: $failed > 0 ? Stat::BAD : Stat::GOOD,
            ),
        ];
    }

    /**
     * ⭐ দ্বিতীয় ধাপ — কতজনের চালু, কতজনের নয় (মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ "চালু" মানে উপরের "ব্যবহারকারীর অবস্থা"-র ইঙ্গিতের একই শর্ত: গোপন চাবি বসানো **আর** নিশ্চিত করা
     * (`mfa_confirmed_at`) — বসানো কিন্তু নিশ্চিত না-করা মানে লগইনে কোড চাওয়াই হয় না। ⓘ এই কোম্পানির মানুষ (পিভট),
     * যোগফল উপরের "ব্যবহারকারী" সংখ্যার সমান। ⛔ কেবল `system_admin.user.manage`।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function twoStep(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('system_admin.user.manage')) {
            return [];
        }

        $row = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->selectRaw('SUM(CASE WHEN mfa_secret IS NOT NULL AND mfa_confirmed_at IS NOT NULL THEN 1 ELSE 0 END) as two_step')
            ->selectRaw('COUNT(*) as everyone')
            ->toBase()->first();

        $with = (int) ($row->two_step ?? 0);

        return [new Breakdown(
            label: __('system_admin::dashboard.two_step'),
            parts: [
                ['label' => __('system_admin::dashboard.two_step_on'), 'value' => (string) $with],
                ['label' => __('system_admin::dashboard.two_step_off'), 'value' => (string) ((int) ($row->everyone ?? 0) - $with)],
            ],
            hint: __('system_admin::dashboard.two_step_hint'),
            chart: 'donut',
        )];
    }

    /**
     * ⭐ ব্যবহারকারী আর অনুমতির বদল — এই সপ্তাহে কী কতবার (মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ উৎস নিরীক্ষার খাতা ([[AuditTrail]], কোম্পানির দেয়ালে বসা), কেবল ব্যবহারকারীর সারি: তৈরি, বদল, রোল বদল
     * (`roles_changed`), কোম্পানি বা নাগালের বদল, পাসওয়ার্ড, দ্বিতীয় ধাপ বন্ধ/নতুন করে — নাম খাতার নিজের ভাষায়
     * ([[AuditTrail::actionInWords()]])। ⚠️ রোলের ভেতরের চাবি বদল আজ খাতায় ওঠে না (রোলের পর্দা নিরীক্ষা লেখে না),
     * তাই এখানে কেবল "কার রোল বদলাল" — "রোলটা নিজে কী পেল" নয়।
     * ⓘ সপ্তাহ = আজসহ শেষ সাত দিন। ⛔ কেবল `system_admin.user.manage`। ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function accessChanges(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('system_admin.user.manage')) {
            return [];
        }

        $from = Carbon::today()->subDays(6);

        $rows = AuditTrail::query()
            ->where('auditable_type', (new User)->getMorphClass())
            ->where('created_at', '>=', $from)
            ->selectRaw('action, COUNT(*) as n')
            ->groupBy('action')
            ->orderByDesc('n')
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        return [new Breakdown(
            label: __('system_admin::dashboard.access_changes'),
            parts: $rows->map(fn ($r) => ['label' => AuditTrail::actionInWords($r->action), 'value' => (string) (int) $r->n])->all(),
            hint: __('system_admin::dashboard.access_changes_hint', ['count' => (int) $rows->sum('n')]),
            chart: 'hbars',
            range: DateRange::label($from, Carbon::today()),
        )];
    }

    /**
     * ⭐ ব্যবস্থার ভুল — এই সপ্তাহে কতগুলো, তার কয়টা কেউ দেখেননি (মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ উৎস কোরের ভুলের খাতা ([[ErrorEvent]], `error_events`); একই ভুল বারবার হলে একটাই সারি (`times` বাড়ে), তাই
     * গোনাটা আলাদা ভুলের। "এই সপ্তাহে" = শেষবার দেখা দিয়েছে আজসহ শেষ সাত দিনে।
     * ⛔ কেবল এই কোম্পানির সারি (`company_id`) — কোম্পানিহীন ভুল কে দেখবেন, সেই প্রশ্নের উত্তর কেবল নিরীক্ষা মডিউলের
     * [[CompanylessRows]]-এ, আর এই মডিউল সেটা চেনে না (`depends_on`)। ⓘ পুরো খাতা নিরীক্ষার ভুলের পর্দায়।
     * ⛔ কেবল `system_admin.settings.manage`। ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function errorsThisWeek(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('system_admin.settings.manage')) {
            return [];
        }

        $from = Carbon::today()->subDays(6);

        $row = ErrorEvent::query()
            ->where('company_id', CompanyContext::id())
            ->where('last_seen_at', '>=', $from)
            ->selectRaw('SUM(CASE WHEN acknowledged_at IS NULL THEN 1 ELSE 0 END) as unseen')
            ->selectRaw('SUM(CASE WHEN acknowledged_at IS NULL THEN 0 ELSE 1 END) as seen')
            ->toBase()->first();

        return [new Breakdown(
            label: __('system_admin::dashboard.errors_week'),
            parts: [
                ['label' => __('system_admin::dashboard.errors_unseen'), 'value' => (string) (int) ($row->unseen ?? 0)],
                ['label' => __('system_admin::dashboard.errors_seen'), 'value' => (string) (int) ($row->seen ?? 0)],
            ],
            hint: __('system_admin::dashboard.errors_week_hint'),
            chart: 'columns',
            range: DateRange::label($from, Carbon::today()),
        )];
    }

    /**
     * দর্শক কি **এই** কোম্পানির সুপার অ্যাডমিন — প্রতিবার ডাটাবেসে, চলতি কোম্পানি ধরে (৬ অক্টোবর ২০২৬)।
     *
     * ⚠️ `$user->roles` নয়: spatie teams-এ সম্পর্কটা যে কোম্পানিতে লোড হয়েছিল তার রোল ধরে রাখে, আর এখানে ভুল উত্তর
     * মানে গোটা সার্ভারের সংখ্যা ভুল মানুষের হাতে। ⓘ নিরীক্ষা মডিউলের একই প্রশ্নের ছাঁচ — এই মডিউল সেটা চেনে না, তাই এখানে।
     * ⛔ দর্শক বা কোম্পানি না থাকলে উত্তর "না"।
     */
    private static function superAdminHere(): bool
    {
        $viewer = auth()->user();
        $company = CompanyContext::id();

        if ($viewer === null || $company === null) {
            return false;
        }

        return DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', $viewer->getMorphClass())
            ->where('mhr.model_id', $viewer->getKey())
            ->where('mhr.company_id', $company)
            ->where('r.name', PermissionSyncer::SUPER_ADMIN_ROLE)
            ->exists();
    }

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

        if ($newest === null) {
            return null;
        }

        /*
         * ⚠️ `(int)` — Carbon 3-এ `diffInDays()` **float** ফেরত দেয়
         * (`3.0000086…`), আর এই পদ্ধতির ঘোষিত ধরন `?int`। ফলে যেখানে
         * একটাও ব্যাকআপ ফাইল আছে সেখানে TypeError, আর **গোটা পাতা ৫০০**।
         *
         * ── কেন এটা এই মেশিনে ধরা পড়েনি ─────────────────────────────
         * এখানে `ABOS_BACKUP_PATH` ফাঁকা, তাই উপরের `null` শাখাতেই
         * ফেরত চলে যেত — এই লাইনটা কোনোদিন চলেনি। **লাইভে ৭৩টা ফাইল
         * আছে**, তাই ওখানে প্রতিবার চলত।
         *
         * অর্থাৎ বাগটা কেবল ওই মেশিনেই দেখা দিত যেখানে জিনিসটা
         * **কাজ করছে** — আর সেটাই সবচেয়ে খারাপ ধরনের বাগ।
         * A3 একটা ফেলনা ডাটাবেসে সত্যিকারের ইনস্টল করে হেঁটে ধরেছে
         * (৩ সেপ্টেম্বর ২০২৬)।
         */
        /*
         * ঘড়িটা নাম ধরে বলা — `config('app.timezone')`।
         *
         * ⓘ **এই লাইনে আজকের ফল বদলায় না, আর সেটা লুকানো ঠিক নয়:**
         * `filemtime()` একটা পরম মুহূর্ত, আর `diffInDays()` দুইটা পরম
         * মুহূর্তের ব্যবধান গোনে — টাইমজোন যা-ই হোক, দিনের সংখ্যা এক।
         *
         * ── তাহলে বদলানো কেন ────────────────────────────────────────
         * ⚠️ **বিপদটা পরের লাইনে, আজকের লাইনে নয়।** কেউ একদিন এই
         * Carbon-টা ধরে সময়টা **দেখাতে** গেলে (যেমন "শেষ ব্যাকআপ কখন")
         * সেটা UTC-তে ছাপত — ছয় ঘণ্টা পিছিয়ে। ঠিক সেটাই ২৬ আগস্টে
         * ব্যাকআপের পর্দায় ঘটেছিল: ফাইলের নাম `…-02:30:12`, অথচ পর্দায়
         * লেখা "০৮:৩০ PM"।
         *
         * ঘড়িটা এখানেই নাম ধরে বলে দিলে ওই ফাঁদটা আর পাতা থাকে না।
         */
        return (int) Carbon::createFromTimestamp($newest, config('app.timezone'))
            ->diffInDays(Carbon::now());
    }
}
