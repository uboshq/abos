<?php

declare(strict_types=1);

namespace App\Modules\Approval\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Models\Approval;
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
                    value: (string) Approval::query()->where('status', Approval::PENDING)->count(),
                    hint: __('approval::dashboard.pending_hint'),
                    href: route('approval.inbox.index'),
                    tone: Stat::WARN,
                ),
                new Stat(
                    label: __('approval::dashboard.approved'),
                    value: (string) Approval::query()->where('status', Approval::APPROVED)->count(),
                    hint: __('approval::dashboard.approved_hint'),
                    href: route('approval.inbox.index'),
                    tone: Stat::GOOD,
                ),
                new Stat(
                    label: __('approval::dashboard.rejected'),
                    value: (string) Approval::query()->where('status', Approval::REJECTED)->count(),
                    hint: __('approval::dashboard.rejected_hint'),
                    href: route('approval.inbox.index'),
                    tone: Stat::BAD,
                ),
            ],

            panels: [self::howLongWaiting(), ...self::byModule(), ...self::byPerson()],

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
                    rows: Approval::query()->where('status', Approval::PENDING)->latest('id')->limit(8)->get(),
                    empty: __('approval::dashboard.nothing_waiting'),
                    href: route('approval.inbox.index'),
                ),
            ],
        );
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

        $row = Approval::query()->where('status', Approval::PENDING)
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

        $rows = Approval::query()->where('status', Approval::PENDING)
            ->selectRaw('assigned_to, COUNT(*) as n')
            ->groupBy('assigned_to')
            ->orderByDesc('n')
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = \App\Models\User::query()->whereKey($rows->pluck('assigned_to')->filter()->all())->pluck('name', 'id');

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

        $rows = Approval::query()->where('status', Approval::PENDING)
            ->selectRaw('module, COUNT(*) as n')
            ->groupBy('module')
            ->orderByDesc('n')
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $registry = app(\App\Core\Module\ModuleRegistry::class);
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
}
