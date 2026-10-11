<?php

declare(strict_types=1);

namespace App\Modules\Approval\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\DataScope;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\ApprovalDelegation;
use App\Models\User;
use App\Models\UserDataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * অনুমোদন মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন এখানে "অপেক্ষমাণ" সবচেয়ে বড় সংখ্যা ──────────────────────────
 * অনুমোদনের পুরো কাজটাই **কেউ অপেক্ষা করছেন** — একটা ছাড়, একটা বিল,
 * একটা ছুটি আটকে আছে কারও সিদ্ধান্তের জন্য। বাকি সংখ্যাগুলো ইতিহাস;
 * এটাই আজকের কাজ।
 */
final class ApprovalDashboard implements ProvidesDashboard
{
    /**
     * ⭐ সইয়ের অপেক্ষায় কয়টা — এই পাতার প্রথম সংখ্যার একমাত্র সংজ্ঞা।
     *
     * ⓘ মালিকের কেন্দ্র ([[Figures]]) ঠিক এটাই ডাকে, প্রতিটা কোম্পানির ভিতরে — ⛔ নিজের COUNT লিখলে
     * একদিন দুই পর্দা দুই সংখ্যা বলত (৮ অক্টোবর ২০২৬)। ⓘ গোনা হয় [[seen()]]-এর ভিতরে, তাই ৯ অক্টোবরের
     * নিয়মটাও সাথে আসে: রিপোর্টের চাবি থাকলে গোটা কোম্পানি, নাহলে নিজের অনুরোধ আর নিজের সারি।
     */
    public static function pendingCount(): int
    {
        return self::seen()->where('status', Approval::PENDING)->count();
    }

    public static function dashboard(): DashboardDefinition
    {
        return new DashboardDefinition(
            title: __('approval::dashboard.title'),
            subtitle: __('approval::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('approval::menu.inbox'), href: route('approval.inbox.index'),
                    permission: 'approval.view', icon: 'inbox'),
                new Tile(label: __('approval::menu.flows'), href: route('approval.flow.index'),
                    permission: 'approval.flow.manage', icon: 'settings'),
            ],

            stats: [
                new Stat(
                    label: __('approval::dashboard.pending'),
                    value: (string) self::pendingCount(),
                    hint: __('approval::dashboard.pending_hint'),
                    href: route('approval.inbox.index'),
                    tone: Stat::WARN,
                ),
                new Stat(
                    label: __('approval::dashboard.approved'),
                    value: (string) self::seen()->where('status', Approval::APPROVED)->count(),
                    hint: __('approval::dashboard.approved_hint'),
                    href: route('approval.inbox.index'),
                    tone: Stat::GOOD,
                ),
                new Stat(
                    label: __('approval::dashboard.rejected'),
                    value: (string) self::seen()->where('status', Approval::REJECTED)->count(),
                    hint: __('approval::dashboard.rejected_hint'),
                    href: route('approval.inbox.index'),
                    tone: Stat::BAD,
                ),
                ...self::decisionsAndDelays(),
            ],

            panels: [self::howLongWaiting(), ...self::byModule(), ...self::byPerson(), ...self::myQueue()],

            listings: [
                new Listing(
                    label: __('approval::dashboard.waiting_now'),
                    columns: [
                        ['key' => 'module', 'label' => __('approval::dashboard.module'), 'width' => '9rem',
                            'render' => fn ($a) => $a->module],
                        ['key' => 'action', 'label' => __('approval::dashboard.action'),
                            'render' => fn ($a) => $a->action],
                        ['key' => 'amount', 'label' => __('approval::dashboard.amount'), 'width' => '9rem',
                            'render' => fn ($a) => $a->amount],
                    ],
                    rows: self::seen()->where('status', Approval::PENDING)->latest('id')->limit(8)->get(),
                    empty: __('approval::dashboard.nothing_waiting'),
                    href: route('approval.inbox.index'),
                ),
            ],
        );
    }

    /**
     * ⛔ কোন অনুরোধগুলো এই মানুষের চোখে পড়ে — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
     *
     * ── কী ভুল ছিল ───────────────────────────────────────────────────
     * ⚠️ পাতাটা কেবল `approval.view` চাইত, অথচ প্রতিটা সংখ্যা আর "এখন
     * অপেক্ষায়" তালিকা গোটা কোম্পানির — অর্থাৎ নিজের ছুটির খবর নিতে আসা
     * একজন কর্মীও দেখতেন কোন মডিউলে কত টাকার কী আটকে আছে, কার টেবিলে।
     *
     * ⭐ এখন দুই রকম চোখ:
     *   · রিপোর্টের চাবি (`approval.report`) আর শাখার সীমা নেই — গোটা কোম্পানি,
     *     অনুমোদনের রিপোর্টগুলোর হুবহু নিয়মে।
     *   · বাকি সবাই — নিজের: যা নিজে চেয়েছেন, যা নিজের হাতে দেওয়া, যাতে
     *     নিজে সই দিয়েছেন, আর ইনবক্সের হুবহু সইয়ের তালিকা
     *     ([[ApprovalEngine::pendingQueryFor()]])।
     *
     * ⓘ শাখার সীমা কেন রিপোর্টের চাবিকেও নামিয়ে আনে: অনুমোদনের সারিতে শাখার
     * ঘর নেই, তাই শাখায় ভাগ করা যায় না — রিপোর্টগুলো তাই `WHOLE_COMPANY`,
     * আর শাখায় আটকানো মানুষকে ইঞ্জিন ফেরায়। ⛔ এখানে ছাড় দিলে ঐ দেয়ালটা
     * ড্যাশবোর্ড দিয়ে টপকানো যেত।
     *
     * @return Builder<Approval>
     */
    private static function seen(): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return Approval::query()->whereRaw('1 = 0');
        }

        if (self::seesTheWholeCompany($user)) {
            return Approval::query();
        }

        $queue = $user->can('approval.decide')
            ? app(ApprovalEngine::class)->pendingQueryFor($user)->reorder()->select('approvals.id')
            : null;

        return Approval::query()->where(fn (Builder $q) => $q
            ->where('approvals.requested_by', $user->id)
            ->orWhere('approvals.assigned_to', $user->id)
            ->orWhereHas('decisions', fn (Builder $d) => $d->where('user_id', $user->id))
            ->when($queue !== null, fn (Builder $w) => $w->orWhereIn('approvals.id', $queue)));
    }

    /**
     * ভারের হিসাবও একই নিয়মে — গোটা কোম্পানির চোখ না থাকলে কেবল নিজের দেওয়া বা পাওয়া ভার।
     *
     * @return Builder<ApprovalDelegation>
     */
    private static function delegationsSeen(): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return ApprovalDelegation::query()->whereRaw('1 = 0');
        }

        if (self::seesTheWholeCompany($user)) {
            return ApprovalDelegation::query();
        }

        return ApprovalDelegation::query()->where(fn (Builder $q) => $q
            ->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id));
    }

    private static function seesTheWholeCompany(User $user): bool
    {
        return $user->can('approval.report')
            && ! app(DataScope::class)->isLimited($user, UserDataScope::BRANCH);
    }

    /**
     * ⭐ কত দিন ধরে অপেক্ষায় — মালিকের ড্যাশবোর্ড নকশা (২ অক্টোবর ২০২৬)।
     *
     * ⓘ "অপেক্ষমাণ" সংখ্যাটা বলে কত; এটা বলে **কতক্ষণ** — আর আটকে থাকার আসল খরচ সময়ে। উপরের ঘরের একই
     * ছাঁকনি (অবস্থা অপেক্ষমাণ), এক কোয়েরিতে বয়স ধরে ভাগ — বয়স জমা দেওয়ার মুহূর্ত (`requested_at`) থেকে; যোগফল সবসময় ঐ সংখ্যার সমান।
     */
    private static function howLongWaiting(): Breakdown
    {
        $now = Carbon::now();
        $day = $now->copy()->subDay();
        $three = $now->copy()->subDays(3);
        $seven = $now->copy()->subDays(7);

        $row = self::seen()->where('status', Approval::PENDING)
            ->selectRaw('SUM(CASE WHEN requested_at >= ? THEN 1 ELSE 0 END) as fresh', [$day])
            ->selectRaw('SUM(CASE WHEN requested_at < ? AND requested_at >= ? THEN 1 ELSE 0 END) as days', [$day, $three])
            ->selectRaw('SUM(CASE WHEN requested_at < ? AND requested_at >= ? THEN 1 ELSE 0 END) as week', [$three, $seven])
            ->selectRaw('SUM(CASE WHEN requested_at < ? THEN 1 ELSE 0 END) as stale', [$seven])
            ->toBase()->first();

        return new Breakdown(
            label: __('approval::dashboard.how_long'),
            parts: [
                ['label' => __('approval::dashboard.under_a_day'), 'value' => (string) (int) ($row->fresh ?? 0)],
                ['label' => __('approval::dashboard.one_to_three'), 'value' => (string) (int) ($row->days ?? 0)],
                ['label' => __('approval::dashboard.three_to_seven'), 'value' => (string) (int) ($row->week ?? 0)],
                ['label' => __('approval::dashboard.over_a_week'), 'value' => (string) (int) ($row->stale ?? 0)],
            ],
            hint: __('approval::dashboard.how_long_hint'),
        );
    }

    /**
     * ⭐ কার কাছে আটকে — অপেক্ষমাণ সই, যাঁর টেবিলে পড়ে আছে তাঁর নামে (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ বাঁয়ের দুই চার্টের একই ছাঁকনি (অবস্থা অপেক্ষমাণ); নাম `assigned_to`-র মানুষের — কাউকে বসানো না থাকলে
     * "নির্দিষ্ট কেউ নন" (যে কেউ চাবিধারী সই দিতে পারেন)। বড় থেকে ছোট, প্রথম ছয়জন; বাকিরা "অন্যরা"-তে, যোগফল এক।
     * ⓘ নাম কেবল এই কোম্পানির মানুষের — অনুমোদনের সারি কোম্পানির স্কোপে, তাই অন্য কোম্পানির টেবিল এখানে আসে না।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function byPerson(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $rows = self::seen()->where('status', Approval::PENDING)
            ->selectRaw('assigned_to, COUNT(*) as n')
            ->groupBy('assigned_to')
            ->orderByDesc('n')
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = User::query()->whereKey($rows->pluck('assigned_to')->filter()->all())->pluck('name', 'id');

        $parts = $rows->take(6)->map(fn ($r) => [
            'label' => $r->assigned_to === null ? __('approval::dashboard.anyone') : ($names[$r->assigned_to] ?? '—'),
            'value' => (string) (int) $r->n,
        ])->all();

        $rest = (int) $rows->slice(6)->sum('n');

        if ($rest > 0) {
            $parts[] = ['label' => __('approval::dashboard.others'), 'value' => (string) $rest];
        }

        return [new Breakdown(
            label: __('approval::dashboard.by_person'),
            parts: $parts,
            hint: __('approval::dashboard.by_person_hint'),
        )];
    }

    /**
     * ⭐ কোন মডিউলে কতটা আটকে — মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬।
     *
     * ⓘ বাঁয়ের "কত দিন ধরে"-র একই ছাঁকনি (অবস্থা অপেক্ষমাণ), তাই দুই ভাগের যোগফল সবসময় এক। নাম মডিউলের নিজের
     * নাম ([[ModuleRegistry]]) — অনুমোদনের সারির `module` থেকে; চেনা না গেলে কোডটাই। বড় থেকে ছোট।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function byModule(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $rows = self::seen()->where('status', Approval::PENDING)
            ->selectRaw('module, COUNT(*) as n')
            ->groupBy('module')
            ->orderByDesc('n')
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $registry = app(ModuleRegistry::class);
        $locale = app()->getLocale();

        return [new Breakdown(
            label: __('approval::dashboard.by_module'),
            parts: $rows->map(function ($r) use ($registry, $locale) {
                $module = $r->module !== null ? $registry->get((string) $r->module) : null;

                return [
                    'label' => $module ? ($module->name[$locale] ?? $module->name['en']) : (string) ($r->module ?? '—'),
                    'value' => (string) (int) $r->n,
                ];
            })->all(),
            hint: __('approval::dashboard.by_module_hint'),
        )];
    }

    /**
     * ⭐ আজকের সিদ্ধান্ত, গতি, দেরি আর ভার — মালিকের ড্যাশবোর্ড নকশা (৬ অক্টোবর ২০২৬)।
     *
     * ⓘ আজ অনুমোদিত / আজ নাকচ = চূড়ান্ত অবস্থা আর `decided_at` আজকের দিনে (দিনের শুরু-শেষ PHP থেকে, SQL-এর ঘড়ি নয়)।
     * ⓘ গড় সময় = এ মাসে যেগুলোর শেষ সিদ্ধান্ত (হ্যাঁ বা না) হয়েছে, জমা (`requested_at`) থেকে সিদ্ধান্ত (`decided_at`) পর্যন্ত;
     * ৪৮ ঘণ্টার কম হলে ঘণ্টায়, নইলে দিনে। কোনো সিদ্ধান্ত না থাকলে "—", শূন্য নয় — শূন্য মানে "সাথে সাথে"।
     * ⓘ সময় পার = অপেক্ষমাণ আর ছকের দেওয়া সময় (`due_at`) পেরিয়ে গেছে; সময়সীমা ছাড়া ছকের অনুরোধ এতে নেই, ঘরের ব্যাখ্যাতেও তাই লেখা।
     * ⓘ চালু ভার = আজকের তারিখে চালু, হাতে বন্ধ হয়নি ([[ApprovalDelegation::scopeActive()]]-এর একই তিন শর্ত)।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Stat>
     */
    private static function decisionsAndDelays(): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $now = Carbon::now();
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();

        $decidedToday = fn (string $status): string => (string) self::seen()
            ->where('status', $status)
            ->whereBetween('decided_at', [$today, $today->copy()->endOfDay()])
            ->count();

        $speed = self::seen()
            ->whereIn('status', [Approval::APPROVED, Approval::REJECTED])
            ->whereBetween('decided_at', [$monthStart, $monthStart->copy()->endOfMonth()])
            ->selectRaw('COUNT(*) as n, AVG(TIMESTAMPDIFF(SECOND, requested_at, decided_at)) as secs')
            ->toBase()->first();

        $decided = (int) ($speed->n ?? 0);

        $overdue = self::seen()
            ->where('status', Approval::PENDING)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->count();

        return [
            new Stat(
                label: __('approval::dashboard.approved_today'),
                value: $decidedToday(Approval::APPROVED),
                hint: __('approval::dashboard.approved_today_hint'),
                href: route('approval.inbox.index'),
                tone: Stat::GOOD,
            ),
            new Stat(
                label: __('approval::dashboard.rejected_today'),
                value: $decidedToday(Approval::REJECTED),
                hint: __('approval::dashboard.rejected_today_hint'),
                href: route('approval.inbox.index'),
                tone: Stat::BAD,
            ),
            new Stat(
                label: __('approval::dashboard.average_time'),
                value: $decided > 0 ? self::duration((string) $speed->secs) : '—',
                hint: __('approval::dashboard.average_time_hint', ['count' => $decided]),
            ),
            new Stat(
                label: __('approval::dashboard.overdue'),
                value: (string) $overdue,
                hint: __('approval::dashboard.overdue_hint'),
                href: route('approval.inbox.index'),
                tone: $overdue > 0 ? Stat::BAD : Stat::NEUTRAL,
            ),
            new Stat(
                label: __('approval::dashboard.delegations_today'),
                value: (string) self::delegationsSeen()->active($today->toDateString())->count(),
                hint: __('approval::dashboard.delegations_today_hint'),
                href: route('approval.delegation.index'),
            ),
        ];
    }

    /**
     * সেকেন্ড থেকে পড়ার মতো সময় — ৪৮ ঘণ্টার কম হলে ঘণ্টায়, নইলে দিনে; এক দশমিক (৬ অক্টোবর ২০২৬)।
     *
     * ⓘ SQL-এর AVG দশমিক লেখা ফেরত দেয়; ভাগ আর গোল করা bcmath-এ ([[Money::round()]]), টাকার মতোই।
     */
    private static function duration(string $seconds): string
    {
        $seconds = bccomp($seconds, '0', 4) < 0 ? '0' : $seconds;
        $hours = bcdiv($seconds, '3600', 6);

        return bccomp($hours, '48', 6) < 0
            ? __('approval::dashboard.hours', ['n' => Money::round($hours, 1)])
            : __('approval::dashboard.days', ['n' => Money::round(bcdiv($hours, '24', 6), 1)]);
    }

    /**
     * ⭐ আমার সইয়ের অপেক্ষায়, মডিউল ধরে — মালিকের ড্যাশবোর্ড নকশা (৬ অক্টোবর ২০২৬)।
     *
     * ⓘ ইনবক্সের হুবহু একই তালিকা ([[ApprovalEngine::pendingQueryFor()]]) — হাতে দেওয়া, ছকে আমার ধাপ, সময় পেরিয়ে উপরে আসা;
     * এখানে আলাদা নিয়ম লিখলে ইনবক্স আর চার্ট দুই কথা বলত। যোগফল হোমের "আমার সিদ্ধান্তের অপেক্ষায়" সংখ্যার সমান।
     * ⓘ ইনবক্সের ক্রম (`requested_at`) গোনায় লাগে না, আর ONLY_FULL_GROUP_BY-তে ভাঙত — তাই `reorder()`।
     * ⛔ সই দেওয়ার চাবি (`approval.decide`) ছাড়া চার্টই নেই — হোমের ঘরের একই চাবি।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @return list<Breakdown>
     */
    private static function myQueue(): array
    {
        $user = auth()->user();

        if (! config('abos.dashboards_v2') || $user === null || ! $user->can('approval.decide')) {
            return [];
        }

        $rows = app(ApprovalEngine::class)->pendingQueryFor($user)
            ->reorder()
            ->selectRaw('module, COUNT(*) as n')
            ->groupBy('module')
            ->orderByDesc('n')
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $registry = app(ModuleRegistry::class);
        $locale = app()->getLocale();

        return [new Breakdown(
            label: __('approval::dashboard.my_queue'),
            parts: $rows->map(function ($r) use ($registry, $locale) {
                $module = $r->module !== null ? $registry->get((string) $r->module) : null;

                return [
                    'label' => $module ? ($module->name[$locale] ?? $module->name['en']) : (string) ($r->module ?? '—'),
                    'value' => (string) (int) $r->n,
                ];
            })->all(),
            hint: __('approval::dashboard.my_queue_hint', ['count' => (int) $rows->sum('n')]),
            chart: 'hbars',
        )];
    }
}
