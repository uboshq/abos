<?php

declare(strict_types=1);

namespace App\Modules\Governance\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
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
        );
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
    private static function whatWasDone(): array
    {
        if (! config('abos.dashboards_v2')) {
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
    private static function loginsToday(): array
    {
        $viewer = auth()->user();

        if (! config('abos.dashboards_v2') || ! $viewer?->can('governance.login.view')) {
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
    private static function papersThisMonth(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('governance.audit.view')) {
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
