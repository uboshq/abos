<?php

declare(strict_types=1);

namespace App\Modules\Governance\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Viz\Card;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Models\AuditTrail;
use App\Models\DocumentDelivery;
use App\Models\ExportLog;
use App\Models\LoginAttempt;
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
                new Tile(label: __('governance::menu.audit_trail'), href: route('governance.audit.index'),
                    permission: 'governance.audit.view', icon: 'eye'),
                new Tile(label: __('governance::menu.login_history'), href: route('governance.login.index'),
                    permission: 'governance.login.view', icon: 'lock'),
                new Tile(label: __('governance::menu.export_log'), href: route('governance.export.index'),
                    permission: 'governance.export.view', icon: 'download'),
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
                    /*
                     * ⛔ চাবিটা রপ্তানির, নিরীক্ষার নয় (৩০ সেপ্টেম্বর ২০২৬) — ১৮
                     * সেপ্টেম্বরে চাবি চারটায় ভাগ হয়েছিল, এই সংখ্যাটা তখন বাদ পড়ে
                     * কোনো চাবিই চাইত না। টাইল দুটোও একই দিনে নিজের চাবিতে ফিরল।
                     */
                    permission: 'governance.export.view',
                ),
            ],

            // ⓘ প্রথম চার্ট আগের জায়গাতেই — হোমে মডিউলের প্রথম চার্টটা বসে; নতুনটা তার পরে (৬ অক্টোবর ২০২৬)
            panels: [...self::whatWasDone(), ...self::loginsToday(), ...self::papersThisMonth()],

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

                ...self::latestLogins(),
            ],

            // ⭐ খোলার পর্দায় ছয়টা গ্রাফ — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা boards15 'gov'); যে সংখ্যা-চার্ট গ্রাফে এল, পাতায় আর আলাদা নয়
            sections: [Card::board(fn (): array => self::charts(), covers: [
                __('governance::dashboard.changes_today'), __('governance::dashboard.this_month_actions'),
                __('governance::dashboard.logins_today'), __('governance::dashboard.papers_this_month'),
            ])],
        );
    }

    /**
     * ⭐ নিরীক্ষার গ্রাফের বোর্ড — এ মাসের কাজ (আড়াআড়ি কলাম), আজকের বদল ঘণ্টা ধরে (হিটম্যাপ), আজকের লগইন (ফানেল), কাগজ কীভাবে
     *   বেরোল (ডোনাট), 30 দিনের বদল (রেখা), আইপি ধরে ঢোকার চেষ্টা (বাবল)।
     * ⛔ চাবি আগের মতোই: লগইনের দুই গ্রাফ কেবল `governance.login.view`-এ ([[CompanylessRows::logins()]]-এর দেয়ালে), কাগজের গ্রাফ কেবল
     *   `governance.audit.view`-এ — চাবি না থাকলে কার্ডটাই নেই। বাকি তিনটা আগের "আজকের বদল" আর মাসের চার্টের একই খাতা, একই দরজা।
     *
     * @return list<array<string, mixed>>
     */
    private static function charts(): array
    {
        $viewer = auth()->user();
        $today = Carbon::today();
        $cards = [];
        $parts = fn (array $breakdowns) => $breakdowns === [] ? [] : $breakdowns[0]->parts;

        // এ মাসের কাজ — আগের মাসের চার্টের হুবহু ভাগ
        // ⛔ লাইভ 1.3.7 (২১ নভেম্বর ২০২৬): বোর্ড সুইচ বন্ধেও আঁকা হয়, অথচ এই চার গ্রাফ সুইচ দেখত — বন্ধে ছয়টার জায়গায় তিনটা, তারও দুটো ফাঁকা
        $month = $parts(self::whatWasDone(board: true));
        $cards[] = Card::make(__('governance::dashboard.audit_month'), 'hbars', [
            'v' => array_map(fn ($p) => (int) $p['value'], $month),
            'l' => array_map(fn ($p) => $p['label'], $month),
        ], value: number_format(array_sum(array_map(fn ($p) => (int) $p['value'], $month))),
            sub: __('governance::dashboard.by_kind_of_action'), href: route('governance.audit.index'), colour: '#2160d8');

        // আজকের বদল — মডিউল × ঘণ্টা; ঘণ্টা পিএইচপির ঘড়িতে (অ্যাপের সময়), SQL-এর নয়
        $todayRows = AuditTrail::query()->where('created_at', '>=', $today)->toBase()->get(['auditable_type', 'created_at']);
        $hours = range(9, 17);
        $byModule = [];
        foreach ($todayRows as $row) {
            $name = (new AuditTrail(['auditable_type' => (string) $row->auditable_type]))->moduleLabel()
                ?? __('governance::dashboard.other_modules');
            $hour = Carbon::parse($row->created_at)->hour;
            $col = max(0, min(count($hours) - 1, $hour - 9));
            $byModule[$name] ??= array_fill(0, count($hours), 0);
            $byModule[$name][$col]++;
        }
        // বড় চারটা মডিউল নিজের সারিতে, বাকি সব (চেনা-না-যাওয়া সহ) এক "অন্যান্য" সারিতে
        $other = __('governance::dashboard.other_modules');
        $rest = $byModule[$other] ?? null;
        unset($byModule[$other]);
        uasort($byModule, fn ($a, $b) => array_sum($b) <=> array_sum($a));
        foreach (array_slice($byModule, 4, null, true) as $line) {
            $rest ??= array_fill(0, count($hours), 0);
            foreach ($line as $k => $n) {
                $rest[$k] += $n;
            }
        }
        $byModule = array_slice($byModule, 0, 4, true);
        if ($rest !== null) {
            $byModule[$other] = $rest;
        }
        $cols = array_map(fn (int $h) => (string) ($h > 12 ? $h - 12 : $h), $hours);
        $cols[0] = '≤9';
        $cols[count($cols) - 1] = '5+';
        $cards[] = Card::make(__('governance::dashboard.today_by_hour'), 'heat', [
            'rows' => array_map(fn ($n) => mb_strimwidth((string) $n, 0, 10, '…'), array_keys($byModule)),
            'cols' => $cols,
            'v' => array_values($byModule),
        ], value: number_format($todayRows->count()), sub: __('governance::dashboard.today_by_hour_hint'),
            href: route('governance.audit.index'), colour: '#7c3aed');

        // আজকের লগইন — আগের চার্টের হুবহু ভাগ, মাথায় মোট চেষ্টা
        $logins = $parts(self::loginsToday(board: true));
        if ($logins !== []) {
            $tried = array_sum(array_map(fn ($p) => (int) $p['value'], $logins));
            $cards[] = Card::make(__('governance::dashboard.logins_today'), 'funnel', ['p' => array_merge(
                [[__('governance::dashboard.login_attempts'), $tried, number_format($tried)]],
                array_map(fn ($p) => [$p['label'], (int) $p['value'], number_format((int) $p['value'])],
                    array_values(array_filter($logins, fn ($p) => (int) $p['value'] > 0))),
            )], value: number_format($tried), sub: __('governance::dashboard.logins_funnel_hint'),
                href: route('governance.login.index'), colour: '#e11d48');
        }

        // কাগজ কীভাবে বেরোল — আগের চার্টের হুবহু ভাগ
        $papers = $parts(self::papersThisMonth(board: true));
        if ($papers !== []) {
            $out = array_sum(array_map(fn ($p) => (int) $p['value'], $papers));
            $cards[] = Card::make(__('governance::dashboard.papers_out'), 'donut', [
                'c' => number_format($out), 'cs' => __('governance::dashboard.papers'),
                'p' => array_map(fn ($p) => [$p['label'], (int) $p['value'], null, number_format((int) $p['value'])], $papers),
            ], sub: __('governance::dashboard.papers_out_hint'), colour: '#0ea5e9');
        }

        // 30 দিনের বদল — দিন ধরে মোট
        $from = $today->copy()->subDays(29);
        $daily = AuditTrail::query()->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as n')->groupBy('d')->toBase()->pluck('n', 'd');
        $values = [];
        $labels = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $from->copy()->addDays($i);
            $values[] = (int) ($daily[$day->toDateString()] ?? 0);
            $labels[] = $i === 29 ? __('governance::dashboard.today') : (in_array($i, [0, 10, 20], true) ? $day->format('d M') : '');
        }
        $cards[] = Card::make(__('governance::dashboard.changes_30'), 'line', [
            'v' => $values, 'l' => $labels, 'color' => '#14b8a6',
        ], sub: __('governance::dashboard.changes_30_hint'), href: route('governance.audit.index'), colour: '#14b8a6');

        // ঢোকার চেষ্টা আইপি ধরে — গত 30 দিন, বড় ছয়টা আইপি; ঢোকার খাতার একই দেয়াল
        if ($viewer?->can('governance.login.view')) {
            $ips = app(\App\Modules\Governance\Services\CompanylessRows::class)->logins($viewer)
                ->where('created_at', '>=', $from)
                ->selectRaw('ip_address, COUNT(*) as n, SUM(CASE WHEN succeeded THEN 0 ELSE 1 END) as bad, COUNT(DISTINCT identifier) as people')
                ->groupBy('ip_address')->orderByDesc('n')->limit(6)->toBase()->get();
            $most = max(1, (int) ($ips->max('n') ?? 0));
            $cards[] = Card::make(__('governance::dashboard.logins_by_ip'), 'bubble', [
                'x' => __('governance::dashboard.axis_attempts'), 'y' => __('governance::dashboard.axis_wrong'),
                'p' => $ips->map(fn ($r) => [
                    (string) ($r->ip_address ?: '—'),
                    6 + intdiv(80 * (int) $r->n, $most),
                    intdiv(100 * (int) $r->bad, max(1, (int) $r->n)),
                    min(36, 8 + 3 * (int) $r->people),
                ])->values()->all(),
            ], sub: __('governance::dashboard.logins_by_ip_hint'), href: route('governance.login.index'), colour: '#f97316');
        }

        return $cards;
    }

    /**
     * ⭐ নিরীক্ষা কার্যকলাপ — এ মাসে কী ধরনের কাজ কতবার (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ উপরের "আজ বদল" ঘরের একই খাতা ([[AuditTrail]]), এ মাসের শুরু থেকে; কাজের নাম খাতার নিজের ভাষায়
     * ([[AuditTrail::actionInWords()]]) — চাবি কখনো নয়। বড় থেকে ছোট, প্রথম ছয়টা; বাকি "অন্যান্য"-তে, যোগফল মাসের মোট।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function whatWasDone(bool $board = false): array
    {
        // ⓘ গ্রাফের বোর্ড (`$board`) সুইচ ছাড়াই — সে দুই অবস্থাতেই আঁকা হয়; আগের চার্ট সুইচ মানে আগের মতো (লাইভ 1.3.7)
        if (! $board && ! config('abos.dashboards_v2')) {
            return [];
        }

        $rows = AuditTrail::query()
            ->where('created_at', '>=', Carbon::today()->startOfMonth())
            ->selectRaw('action, COUNT(*) as n')
            ->groupBy('action')
            ->orderByDesc('n')
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $parts = $rows->take(6)->map(fn ($r) => ['label' => AuditTrail::actionInWords($r->action), 'value' => (string) (int) $r->n])->all();
        $rest = (int) $rows->slice(6)->sum('n');

        if ($rest > 0) {
            $parts[] = ['label' => __('governance::dashboard.other_actions'), 'value' => (string) $rest];
        }

        return [new Breakdown(
            label: __('governance::dashboard.this_month_actions'),
            parts: $parts,
            hint: __('governance::dashboard.this_month_actions_hint', ['count' => (int) $rows->sum('n')]),
            // ⓘ সময়ের চার্ট — কোন দিন থেকে কোন দিন (মালিক, ৫ অক্টোবর ২০২৬; বসানো ৬ অক্টোবর ২০২৬)
            range: DateRange::label(Carbon::today()->startOfMonth(), Carbon::today()),
        )];
    }

    /**
     * ⭐ লগইন নিরাপত্তা — আজ কতজন ঢুকলেন, কত চেষ্টা কেন আটকাল (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⛔ সারিগুলো কেবল [[CompanylessRows::logins()]] দিয়ে — ঢোকার খাতার পর্দার একই দেয়াল: এই কোম্পানির সারি, এই
     * কোম্পানির মানুষের নামে কোম্পানিহীন চেষ্টা, আর অচেনা নামের চেষ্টা কেবল সুপার অ্যাডমিনকে। নিজে ছাঁকনি লিখলে
     * অন্য কোম্পানির আক্রমণকারীর আইপি-র গোনা এখানে চলে আসত।
     * ⛔ কেবল `governance.login.view` — খাতা দেখার চাবি ছাড়া সংখ্যাও নয়।
     * ⓘ নকশায় এটা সিস্টেম প্রশাসনের পাতায় ছিল; ঢোকার খাতার দেয়ালটা এই মডিউলের, তাই এখানে।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function loginsToday(bool $board = false): array
    {
        $viewer = auth()->user();

        // ⓘ গ্রাফের বোর্ড (`$board`) সুইচ ছাড়াই — সে দুই অবস্থাতেই আঁকা হয়; আগের চার্ট সুইচ মানে আগের মতো (লাইভ 1.3.7)
        if ((! $board && ! config('abos.dashboards_v2')) || ! $viewer?->can('governance.login.view')) {
            return [];
        }

        $rows = app(\App\Modules\Governance\Services\CompanylessRows::class)->logins($viewer)
            ->where('created_at', '>=', Carbon::today())
            ->selectRaw('succeeded, reason, COUNT(*) as n')
            ->groupBy('succeeded', 'reason')
            ->toBase()->get();

        $count = fn (callable $match) => (int) $rows->filter($match)->sum('n');
        $failed = fn (array $reasons) => $count(fn ($r) => ! $r->succeeded && in_array($r->reason, $reasons, true));

        $parts = [
            ['label' => __('governance::dashboard.login_ok'), 'value' => (string) $count(fn ($r) => (bool) $r->succeeded)],
            ['label' => __('governance::dashboard.login_wrong_password'), 'value' => (string) $failed([\App\Models\LoginAttempt::WRONG_PASSWORD])],
            ['label' => __('governance::dashboard.login_unknown'), 'value' => (string) $failed([\App\Models\LoginAttempt::UNKNOWN])],
            ['label' => __('governance::dashboard.login_code'), 'value' => (string) $failed([\App\Models\LoginAttempt::NEEDS_CODE, \App\Models\LoginAttempt::WRONG_CODE])],
            ['label' => __('governance::dashboard.login_locked'), 'value' => (string) $failed([\App\Models\LoginAttempt::LOCKED, \App\Models\LoginAttempt::INACTIVE])],
        ];

        $known = [\App\Models\LoginAttempt::WRONG_PASSWORD, \App\Models\LoginAttempt::UNKNOWN, \App\Models\LoginAttempt::NEEDS_CODE, \App\Models\LoginAttempt::WRONG_CODE, \App\Models\LoginAttempt::LOCKED, \App\Models\LoginAttempt::INACTIVE];
        $other = $count(fn ($r) => ! $r->succeeded && ! in_array($r->reason, $known, true));

        if ($other > 0) {
            $parts[] = ['label' => __('governance::dashboard.login_other'), 'value' => (string) $other];
        }

        return [new Breakdown(
            label: __('governance::dashboard.logins_today'),
            parts: $parts,
            hint: __('governance::dashboard.logins_today_hint'),
            range: DateRange::label(Carbon::today(), Carbon::today()),
        )];
    }

    /**
     * ⭐ এ মাসে কাগজ কীভাবে বেরোল — ছাপা, নামানো, লিংকে পাঠানো, লিংক খোলা (মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ খাতাটা কোরের ([[DocumentDelivery]], `doc_deliveries`) — প্রতিটা মডিউলের ছাপার পর্দা এখানেই লেখে, আর মডেলটা
     * নিজেই কোম্পানির দেয়ালে বসা (`BelongsToCompany`)। ⛔ `sal_print_jobs` নেওয়া হয়নি: ওটা বিক্রয়ের টেবিল, আর এই মডিউল
     * বিক্রয় চেনে না (`depends_on`) — তাছাড়া ওটা কেবল বিক্রয়ের বিল গোনে, এটা সব কাগজ।
     * ⓘ চারটা ভাগ সবসময় ([[DocumentDelivery::WAYS]]) — শূন্যও দেখায়, কারণ "এ মাসে একটাও লিংক খোলা হয়নি" নিজেই একটা খবর।
     * ⛔ কেবল `governance.audit.view` — নিরীক্ষার খাতা যে চাবিতে খোলে। ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function papersThisMonth(bool $board = false): array
    {
        // ⓘ গ্রাফের বোর্ড (`$board`) সুইচ ছাড়াই — সে দুই অবস্থাতেই আঁকা হয়; আগের চার্ট সুইচ মানে আগের মতো (লাইভ 1.3.7)
        if ((! $board && ! config('abos.dashboards_v2')) || ! auth()->user()?->can('governance.audit.view')) {
            return [];
        }

        $from = Carbon::today()->startOfMonth();

        $rows = DocumentDelivery::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('how, COUNT(*) as n')
            ->groupBy('how')
            ->toBase()->pluck('n', 'how');

        $parts = array_map(fn (string $how) => [
            'label' => __('governance::dashboard.paper_'.$how),
            'value' => (string) (int) ($rows[$how] ?? 0),
        ], DocumentDelivery::WAYS);

        return [new Breakdown(
            label: __('governance::dashboard.papers_this_month'),
            parts: $parts,
            hint: __('governance::dashboard.papers_this_month_hint', ['count' => array_sum(array_map(fn ($p) => (int) $p['value'], $parts))]),
            chart: 'columns',
            range: DateRange::label($from, Carbon::today()),
        )];
    }

    /**
     * ⭐ শেষ ঢোকার চেষ্টাগুলো — কে, কখন, পারলেন কি না, আর কোন আইপি থেকে (মালিকের ড্যাশবোর্ড নকশা, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ আইপি ঢোকার খাতার নিজের ঘর (`login_history.ip_address`) — ঢোকার খাতার পর্দাও ঠিক এটাই দেখায়।
     * ⛔ সারিগুলো কেবল [[CompanylessRows::logins()]] দিয়ে — উপরের লগইন চার্টের একই দেয়াল; অচেনা নামের চেষ্টা (আর তার
     * আইপি) কেবল সুপার অ্যাডমিন দেখেন। ⛔ কেবল `governance.login.view`।
     * ⓘ পুরনো "সর্বশেষ বদল" তালিকা হুবহু থাকে — সুইচ বন্ধে পুরনো পাতা বদলায় না; এটা নতুন ড্যাশবোর্ডে আলাদা তালিকা।
     *
     * @return list<Listing>
     */
    private static function latestLogins(): array
    {
        $viewer = auth()->user();

        if (! config('abos.dashboards_v2') || ! $viewer?->can('governance.login.view')) {
            return [];
        }

        $known = [LoginAttempt::WRONG_PASSWORD, LoginAttempt::UNKNOWN, LoginAttempt::NEEDS_CODE, LoginAttempt::WRONG_CODE, LoginAttempt::LOCKED, LoginAttempt::INACTIVE];

        return [new Listing(
            label: __('governance::dashboard.latest_logins'),
            columns: [
                ['key' => 'when', 'label' => __('governance::field.when'), 'width' => '11rem',
                    'render' => fn (LoginAttempt $a) => $a->created_at?->format('d M Y, H:i') ?? '—'],
                ['key' => 'who', 'label' => __('governance::field.who'),
                    'render' => fn (LoginAttempt $a) => $a->who()],
                ['key' => 'result', 'label' => __('governance::field.result'), 'width' => '11rem',
                    'render' => fn (LoginAttempt $a) => $a->succeeded
                        ? __('governance::message.got_in')
                        : (in_array($a->reason, $known, true) ? __('governance::message.why_'.$a->reason) : __('governance::message.refused'))],
                ['key' => 'ip', 'label' => __('governance::field.where_from'), 'width' => '9rem',
                    'render' => fn (LoginAttempt $a) => $a->ip_address ?: '—'],
            ],
            rows: app(\App\Modules\Governance\Services\CompanylessRows::class)->logins($viewer)
                ->with('user')->latestFirst()->limit(10)->get(),
            empty: __('governance::message.nothing_yet'),
            href: route('governance.login.index'),
        )];
    }
}
