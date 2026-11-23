<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Viz\Card;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Security\LoginLock;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\ErrorEvent;
use App\Models\LoginAttempt;
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

            /*
             * ⭐ ভাগের কার্ড — মেনুর নকল চারটা বোতামের বদলে বাঁয়ের মেনুর ভাগগুলোই, প্রতিটা নিজের প্রথম পর্দায় (কন্ট্রোল প্যানেলের পরিকল্পনা,
             * দফা ৪: "হোমে মেনুর নকল বোতাম → ভাগের কার্ড"; Microsoft 365 Admin Center)। ⓘ চাবি না থাকলে কার্ডটাই নেই ([[DashboardEngine]])।
             */
            tiles: [
                new Tile(label: __('system_admin::menu.section_org'), href: route('system_admin.company.index'),
                    permission: 'system_admin.company.manage', icon: 'building'),
                new Tile(label: __('system_admin::menu.section_people'), href: route('system_admin.user.index'),
                    permission: 'system_admin.user.manage', icon: 'people'),
                new Tile(label: __('system_admin::menu.section_modules'), href: route('system_admin.control-panel'),
                    permission: 'system_admin.settings.manage', icon: 'settings'),
                new Tile(label: __('system_admin::menu.section_comms'), href: route('system_admin.notice.index'),
                    permission: 'system_admin.notice.manage', icon: 'bell'),
                new Tile(label: __('system_admin::menu.section_data'), href: route('system_admin.import.index'),
                    permission: 'system_admin.import.manage', icon: 'drawer'),
                // ⭐ সপ্তম ভাগ — নিরীক্ষা: কে কবে কী বদলালেন (কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ১ আর ৪)
                new Tile(label: __('system_admin::menu.section_audit'), href: route('governance.audit.index'),
                    permission: 'governance.audit.view', icon: 'governance'),
            ],

            search: [
                'action' => route('system_admin.control-panel'),
                'name' => 'find',
                'placeholder' => __('system_admin::control.find'),
                'permission' => 'system_admin.settings.manage',
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
                            'render' => fn ($u) => \App\Core\Support\ContactMask::email($u->email)], // ⭐ দফা ১২: হোম খোলে সেটিংসের চাবিতে — ঠিকানা আংশিক ঢাকা
                        ['key' => 'joined', 'label' => __('system_admin::dashboard.joined'), 'width' => '10rem',
                            'render' => fn ($u) => $u->created_at ? \App\Core\Support\DateFormat::spokenDate($u->created_at) : '—'],
                    ],
                    // ⛔ "নতুন ব্যবহারকারী" — অন্য কোম্পানির নাম এখানে বসত
                    rows: User::query()->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))->latest('id')->limit(8)->get(),
                    empty: __('system_admin::dashboard.no_users'),
                    href: route('system_admin.user.index'),
                ),
            ],

            // ⭐ খোলার পর্দায় ছয়টা গ্রাফ — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা boards15 'sys'); যে সংখ্যা-চার্ট গ্রাফে এল, পাতায় আর আলাদা নয়
            // ⛔ ঢাকা কেবল সেটুকু, যার গ্রাফ এই দর্শক সত্যিই দেখেন — চাবি না থাকলে আগের সংখ্যাটা নিচে থেকে যায়
            sections: [Card::board(fn (): array => self::charts($backup), covers: self::covers())],
        );
    }

    /** @return list<string> যে আগের সংখ্যা-চার্ট এই দর্শকের গ্রাফে এসেছে */
    private static function covers(): array
    {
        $viewer = auth()->user();
        // ⛔ লাইভ 1.3.7 (২১ নভেম্বর ২০২৬): বোর্ড সুইচ বন্ধেও আঁকা হয়, অথচ ছয়টার পাঁচটা কার্ড সুইচ দেখত — বন্ধে কেবল "শেষ ব্যাকআপ", এক কার্ড।
        //   ⭐ এখন কার্ড আর তার ঢাকা কেবল চাবি মানে, সুইচ নয় — দুই অবস্থায় একই ছয়টা, একই অঙ্ক দুইবার নয় ([[charts()]] একই শর্ত)
        $v2 = true;
        $covers = [__('system_admin::dashboard.last_backup')];

        if ($v2 && $viewer?->can('system_admin.user.manage')) {
            // ⓘ "কী ধরনের বদল" ভাগটা ঢাকা নয় — গ্রাফে কেবল দিন ধরে মোট, ধরন নিচের কলামে (নিরীক্ষা, ২৩ নভেম্বর ২০২৬)
            array_push($covers, __('system_admin::menu.users'), __('system_admin::dashboard.who_gets_in'),
                __('system_admin::dashboard.two_step'));
        }
        if ($v2 && self::superAdminHere()) {
            array_push($covers, __('system_admin::dashboard.jobs_queued'), __('system_admin::dashboard.jobs_failed'));
        }
        if ($v2 && $viewer?->can('system_admin.settings.manage')) {
            $covers[] = __('system_admin::dashboard.errors_week');
        }

        return $covers;
    }

    /**
     * ⭐ সিস্টেম প্রশাসনের গ্রাফের বোর্ড — ব্যবহারকারীর অবস্থা (ডোনাট), রোল ধরে দ্বিতীয় ধাপ (রেডিয়াল), এ সপ্তাহের অনুমতির বদল (কলাম),
     *   পেছনের কাজ (ফানেল), ব্যবস্থার ভুল এ সপ্তাহ বনাম গত সপ্তাহ (জোড়া রেখা), প্রতিদিনের ব্যাকআপ (ক্যালেন্ডার)।
     * ⛔ প্রতিটা গ্রাফ আগের সংখ্যার একই চাবিতে: মানুষ আর অনুমতি `system_admin.user.manage`, পেছনের কাজ কেবল এই কোম্পানির সুপার
     *   অ্যাডমিন, ভুল `system_admin.settings.manage` — চাবি না থাকলে কার্ডটাই নেই।
     *
     * @return list<array<string, mixed>>
     */
    private static function charts(?int $backup): array
    {
        $viewer = auth()->user();
        // ⓘ সুইচ নয় — বোর্ড দুই অবস্থাতেই আঁকা হয় ([[covers()]]-এর একই কথা; লাইভ 1.3.7)
        $v2 = true;
        $today = Carbon::today();
        $company = CompanyContext::id();
        $cards = [];
        $weekday = fn (Carbon $d) => $d->locale(app()->getLocale())->translatedFormat('D');

        if ($v2 && $viewer?->can('system_admin.user.manage')) {
            // ব্যবহারকারীর অবস্থা — আগের চার্টের চার ভাগ, আর নমুনার "তালাবদ্ধ": এখন লগইনের তালায় আটকা ([[LoginLock]]) চালু মানুষ
            //   নিজের ভাগ থেকে সরে তালাবদ্ধে — প্রতিজন ঠিক একটা ভাগে, যোগফল একই
            $locked = self::lockedUsers();
            $who = self::whoCanGetIn(board: true)[0]->parts;
            $lockedFrom = $locked === [] ? null : self::userStates($locked);
            $slices = [];
            foreach (['live', 'idle', 'never'] as $k => $state) {
                $slices[] = [$who[$k]['label'], (int) $who[$k]['value'] - (int) ($lockedFrom->{$state} ?? 0), ['#16a34a', '#f59e0b', '#0ea5e9'][$k]];
            }
            $slices[] = [__('system_admin::dashboard.users_locked'), $lockedFrom === null ? 0 : (int) $lockedFrom->live + (int) $lockedFrom->idle + (int) $lockedFrom->never, '#e11d48'];
            $slices[] = [$who[3]['label'], (int) $who[3]['value'], '#94a3b8'];
            $everyone = array_sum(array_column($slices, 1));
            $cards[] = Card::make(__('system_admin::dashboard.who_gets_in'), 'donut', [
                'c' => number_format($everyone), 'cs' => __('system_admin::dashboard.users_word'),
                'p' => array_map(fn ($p) => [$p[0], $p[1], $p[2], number_format($p[1])], $slices),
            ], value: number_format($everyone), sub: __('system_admin::dashboard.who_gets_in_board_hint'),
                href: route('system_admin.user.index'), colour: '#2160d8');

            // দ্বিতীয় ধাপ — রোল ধরে, বড় চারটা রোল; মাথায় সবার মিলিয়ে
            $two = self::twoStep(board: true)[0]->parts;
            $on = (int) $two[0]['value'];
            $all = $on + (int) $two[1]['value'];
            $roles = DB::table('model_has_roles as mhr')
                ->join('roles as r', 'r.id', '=', 'mhr.role_id')
                ->join('users as u', 'u.id', '=', 'mhr.model_id')
                ->where('mhr.company_id', $company)
                ->where('mhr.model_type', (new User)->getMorphClass())
                ->whereIn('u.id', DB::table('company_user')->where('company_user.company_id', $company)->where('company_user.is_active', true)->select('company_user.user_id'))
                ->groupBy('r.name')
                ->selectRaw('r.name as name, COUNT(DISTINCT u.id) as n')
                ->selectRaw('COUNT(DISTINCT CASE WHEN u.mfa_secret IS NOT NULL AND u.mfa_confirmed_at IS NOT NULL THEN u.id END) as on_')
                ->orderByDesc('n')->limit(4)->get();
            $cards[] = Card::make(__('system_admin::dashboard.two_step'), 'radial', [
                'p' => $roles->map(fn ($r) => [
                    mb_strimwidth(\App\Core\Support\RoleLabel::for((string) $r->name), 0, 18, '…'),
                    (int) $r->n === 0 ? 0 : (int) $r->on_ / (int) $r->n,
                ])->all(),
            ], value: $all === 0 ? '' : intdiv(100 * $on, $all).'%', sub: __('system_admin::dashboard.two_step_board_hint', ['on' => $on, 'all' => $all]),
                href: route('system_admin.user.index'), colour: '#16a34a');

            // ব্যবহারকারী আর অনুমতির বদল — শেষ সাত দিন, দিন ধরে (আগের চার্টের একই খাতা আর ছাঁকনি)
            $from = $today->copy()->subDays(6);
            $byDay = AuditTrail::query()->where('auditable_type', (new User)->getMorphClass())->where('created_at', '>=', $from)
                ->selectRaw('DATE(created_at) as d, COUNT(*) as n')->groupBy('d')->toBase()->pluck('n', 'd');
            $days = array_map(fn ($i) => $from->copy()->addDays($i), range(0, 6));
            $changes = array_map(fn (Carbon $d) => (int) ($byDay[$d->toDateString()] ?? 0), $days);
            $cards[] = Card::make(__('system_admin::dashboard.viz_access_changes'), 'bars', [
                'v' => $changes, 'l' => array_map($weekday, $days), 'color' => '#7c3aed',
            ], value: number_format(array_sum($changes)), sub: __('system_admin::dashboard.access_changes_board_hint'), colour: '#7c3aed');
        }

        if ($v2 && self::superAdminHere()) {
            // ⚠️ কোম্পানিহীন টেবিল — company_id নেই; দরজাটা উপরের সুপার অ্যাডমিনের প্রশ্ন। কেবল গোনা, payload কখনো নয়।
            $jobs = DB::table('jobs')
                ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND attempts = 0 THEN 1 ELSE 0 END) as waiting')
                ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as running')
                ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND attempts > 0 THEN 1 ELSE 0 END) as retrying')
                ->first();
            $failed = DB::table('failed_jobs')->count();
            // ⓘ সারিতে মোট — নিচের পুরনো "পেছনের কাজ — অপেক্ষায়" ঘরের একই গোনা (jobs টেবিলের সব সারি), কার্ডের মাথায়
            $queued = (int) ($jobs->waiting ?? 0) + (int) ($jobs->running ?? 0) + (int) ($jobs->retrying ?? 0);
            $steps = [
                [__('system_admin::dashboard.jobs_waiting'), (int) ($jobs->waiting ?? 0)],
                [__('system_admin::dashboard.jobs_running'), (int) ($jobs->running ?? 0)],
                [__('system_admin::dashboard.jobs_retrying'), (int) ($jobs->retrying ?? 0)],
                [__('system_admin::dashboard.jobs_failed_short'), $failed],
            ];
            $cards[] = Card::make(__('system_admin::dashboard.jobs'), 'funnel', [
                'p' => array_map(fn ($s) => [$s[0], $s[1], number_format($s[1])], $steps),
            ], value: __('system_admin::dashboard.jobs_in_queue', ['n' => number_format($queued)]),
                sub: __('system_admin::dashboard.jobs_board_hint'), colour: '#0ea5e9');
        }

        if ($v2 && $viewer?->can('system_admin.settings.manage')) {
            // ব্যবস্থার ভুল — শেষবার দেখা দেওয়ার দিন ধরে (আগের চার্টের একই শর্ত), এ সপ্তাহ বনাম আগের সপ্তাহ
            $from = $today->copy()->subDays(13);
            $seen = ErrorEvent::query()->where('company_id', $company)->where('last_seen_at', '>=', $from)
                ->selectRaw('DATE(last_seen_at) as d, COUNT(*) as n, SUM(CASE WHEN acknowledged_at IS NULL THEN 1 ELSE 0 END) as unseen')
                ->groupBy('d')->toBase()->get()->keyBy('d');
            $count = fn (int $i) => (int) ($seen[$from->copy()->addDays($i)->toDateString()]->n ?? 0);
            $thisWeek = array_map($count, range(7, 13));
            $lastWeek = array_map($count, range(0, 6));
            $unseen = (int) $seen->filter(fn ($r) => $r->d >= $from->copy()->addDays(7)->toDateString())->sum('unseen');
            $cards[] = Card::make(__('system_admin::dashboard.errors_week'), 'line', [
                'v' => $thisWeek, 'v2' => $lastWeek, 'color' => '#e11d48', 'c2' => '#94a3b8',
                'l' => array_map(fn ($i) => $weekday($from->copy()->addDays($i)), range(7, 13)),
            ], value: number_format(array_sum($thisWeek)), sub: __('system_admin::dashboard.errors_board_hint', ['n' => $unseen]), legend: [
                [__('system_admin::dashboard.this_week'), '#e11d48'], [__('system_admin::dashboard.last_week'), '#94a3b8'],
            ], colour: '#e11d48');
        }

        // শেষ ব্যাকআপ — প্রতিদিন ফাইল আছে কি না, শনিবার থেকে পাঁচ সপ্তাহ, আজ পর্যন্ত (ফাইল দেখে, খাতা নয় — [[lastBackup()]]-এর নিয়ম)
        $start = $today->copy()->subDays((($today->dayOfWeek + 1) % 7) + 28);
        $perDay = [];
        $times = self::backupTimes();
        foreach ($times as $at) {
            $key = Carbon::createFromTimestamp($at, config('app.timezone'))->toDateString();
            $perDay[$key] = ($perDay[$key] ?? 0) + 1;
        }
        $calendar = [];
        for ($d = $start->copy(); $d->lte($today); $d->addDay()) {
            $calendar[] = [(int) $d->format('j'), $perDay[$d->toDateString()] ?? 0];
        }
        // ⓘ মাথায় ঘণ্টায়, নমুনার মতো ("2 ঘণ্টা আগে") — দুই দিনের বেশি হলে দিনে
        $newest = $times === [] ? null : max($times);
        $hours = $newest === null ? null : (int) Carbon::createFromTimestamp($newest, config('app.timezone'))->diffInHours(Carbon::now());
        $cards[] = Card::make(__('system_admin::dashboard.last_backup'), 'calendar', $perDay === [] ? [] : [
            'days' => $calendar, 'marks' => true, 'color' => '#16a34a',
        ], value: match (true) {
            $hours === null => __('system_admin::dashboard.never'),
            $hours < 48 => __('system_admin::dashboard.hours_ago', ['hours' => $hours]),
            default => __('system_admin::dashboard.days_ago', ['days' => (int) $backup]),
        },
            sub: __('system_admin::dashboard.backup_board_hint'), href: route('backup.index'), colour: '#14b8a6');

        return $cards;
    }

    /**
     * ব্যাকআপ ফাইলগুলোর সময় — [[lastBackup()]]-এর একই ফোল্ডার আর একই ধরন (*.sql.gz); ফোল্ডার না থাকলে ফাঁকা।
     *
     * @return list<int>
     */
    private static function backupTimes(): array
    {
        $path = (string) config('abos.backup.path', '');

        if ($path === '' || ! is_dir($path)) {
            return [];
        }

        $times = [];
        foreach (glob(rtrim($path, '/\\').'/*.sql.gz') ?: [] as $file) {
            $at = filemtime($file);
            if ($at !== false) {
                $times[] = $at;
            }
        }

        return $times;
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
    private static function whoCanGetIn(bool $board = false): array
    {
        // ⓘ গ্রাফের বোর্ড (`$board`) সুইচ ছাড়াই — আগের চার্ট সুইচ মানে আগের মতো
        if ((! $board && ! config('abos.dashboards_v2')) || ! auth()->user()?->can('system_admin.user.manage')) {
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
    private static function twoStep(bool $board = false): array
    {
        // ⓘ গ্রাফের বোর্ড (`$board`) সুইচ ছাড়াই — আগের চার্ট সুইচ মানে আগের মতো
        if ((! $board && ! config('abos.dashboards_v2')) || ! auth()->user()?->can('system_admin.user.manage')) {
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
            // ⓘ ধরন ধরে ভাগ — গ্রাফের "দিন ধরে" কার্ড থেকে আলাদা নাম, যাতে পাতায় নিচে থাকে
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
     * এখন লগইনের তালায় আটকা এই কোম্পানির মানুষ — [[LoginLock::locked()]]-এর হুবহু নিয়ম।
     *
     * ⓘ সস্তা পথে: আগে দেখা হয় শেষ তালার সময়ের (15 মিনিট) মধ্যে কার নামে ভুল চেষ্টা আছে (এক প্রশ্ন); কেবল তাঁদের নাম তালাকে জিজ্ঞেস করা।
     *
     * @return list<int>
     */
    private static function lockedUsers(): array
    {
        if (CompanyContext::id() === null) {
            return [];
        }

        // ⓘ চেষ্টার নিজের company_id নয় — তালা নাম ধরে, সব কোম্পানি মিলে; তাই ছাঁকনি "এই কোম্পানির সদস্য কি না"
        $recent = LoginAttempt::query()
            ->where('succeeded', false)
            ->whereNotNull('user_id')
            ->whereIn('user_id', DB::table('company_user')->where('company_user.company_id', CompanyContext::id())->select('company_user.user_id'))
            ->where('created_at', '>=', Carbon::now()->subMinutes(LoginLock::MINUTES))
            ->distinct()->get(['user_id', 'identifier']);

        $lock = app(LoginLock::class);

        return $recent->filter(fn (LoginAttempt $a) => $lock->locked((string) $a->identifier) !== null)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * কিছু মানুষের ভাগ — [[whoCanGetIn()]]-এর একই চার শর্ত, কেবল এই আইডিগুলোর।
     *
     * @param  list<int>  $ids
     */
    private static function userStates(array $ids): object
    {
        $since = Carbon::now()->subDays(30);

        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->whereKey($ids)
            ->selectRaw('SUM(CASE WHEN is_active = 1 AND last_login_at IS NULL THEN 1 ELSE 0 END) as never')
            ->selectRaw('SUM(CASE WHEN is_active = 1 AND last_login_at < ? THEN 1 ELSE 0 END) as idle', [$since])
            ->selectRaw('SUM(CASE WHEN is_active = 1 AND last_login_at >= ? THEN 1 ELSE 0 END) as live', [$since])
            ->toBase()->first() ?? (object) ['never' => 0, 'idle' => 0, 'live' => 0];
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
        // ⓘ কেবল config — env() config ক্যাশ থাকলে null দেয়; config/abos.php নিজেই ABOS_BACKUP_PATH পড়ে (phpstan L1)
        $path = (string) config('abos.backup.path', '');

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
