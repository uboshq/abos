<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\Viz\Card;
use App\Core\Engines\Assistant\AssistantEngine;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Module\ModuleRegistry;
use App\Models\AssistantEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * ⭐ কোপাইলটের ড্যাশবোর্ড — কে কতটা জিজ্ঞেস করে, কোপাইলট কতটা বোঝে আর উত্তর দেয় (মালিক, ২০ নভেম্বর ২০২৬: প্রতিটা মডিউলের
 *   ড্যাশবোর্ড ছয় রকম ছয়টা গ্রাফে খোলে)।
 *
 * ⓘ সব সংখ্যা কোপাইলটের নিজের খাতা থেকে ([[AssistantEvent]], `assistant_events`) — প্রশ্ন, খোঁজা, নজর, প্রস্তাব, খসড়া, আটকানো,
 *   মতামত; কিছুই বানানো নয়। পাতা খোলা (`visit`) কোপাইলটের ব্যবহার নয়, তাই কোথাও গোনা হয় না।
 * ⛔ কার চোখে কতটা: খাতার চাবি (`assistant.audit`, কেবল মালিক) থাকলে গোটা কোম্পানি আর "কে বেশি জিজ্ঞেস করেন"; বাকিরা
 *   (`assistant.use`) কেবল নিজের সারি — অন্য কারও নাম বা প্রশ্ন নয়, তাঁদের চতুর্থ গ্রাফ নিজের সবচেয়ে বেশি খোঁজা কথা।
 * ⓘ কোনো সংখ্যার ঘর নেই — হোমের মডিউল-টাইল আগের মতোই থাকে না (সবাই কোপাইলটের চাবি পান; হোমে নতুন ঘর মালিকের সিদ্ধান্ত)।
 */
final class AssistantDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        return new DashboardDefinition(
            title: __('assistant::dashboard.title', ['name' => app(AssistantEngine::class)->name()]),
            subtitle: __('assistant::dashboard.subtitle'),
            sections: [Card::board(fn (): array => self::charts(), permission: 'assistant.use')],
        );
    }

    /**
     * ⭐ ছয়টা গ্রাফ — দিনে কত প্রশ্ন (রেখা), জিজ্ঞাসা → বোঝা → উত্তর → 👍 (ফানেল), উত্তর কোন মডিউল থেকে (ডোনাট),
     *   কে বেশি জিজ্ঞেস করেন / আমার বেশি খোঁজা (লোলিপপ), কখন জিজ্ঞেস করা হয় (হিটম্যাপ), কোপাইলটের কোন অংশ কতটা (ট্রিম্যাপ)।
     *
     * @return list<array<string, mixed>>
     */
    private static function charts(): array
    {
        $user = auth()->user();
        $whole = $user instanceof User && $user->can('assistant.audit');
        $today = Carbon::today();
        $from = $today->copy()->subDays(29);
        $range = DateRange::label($from, $today);
        $seen = fn (): Builder => self::seen($user, $whole)->where('assistant_events.created_at', '>=', $from);
        $asked = fn (): Builder => $seen()->where(fn (Builder $q) => $q->where('kind', AssistantEvent::QUESTION)->orWhere('kind', AssistantEvent::BLOCKED));

        // দিনে দিনে — জিজ্ঞাসা আর উত্তর
        $perDay = $asked()->selectRaw("DATE(created_at) as d, COUNT(*) as n, SUM(CASE WHEN outcome = 'answered' THEN 1 ELSE 0 END) as ok")
            ->groupByRaw('DATE(created_at)')->toBase()->get()->keyBy('d');
        [$askedDays, $answeredDays, $labels] = [[], [], []];
        for ($i = 0, $day = $from->copy(); $day->lte($today); $day->addDay(), $i++) {
            $row = $perDay[$day->toDateString()] ?? null;
            $askedDays[] = (int) ($row->n ?? 0);
            $answeredDays[] = (int) ($row->ok ?? 0);
            $labels[] = in_array($i, [0, 10, 20], true) ? $day->format('d/m') : ($day->isToday() ? __('assistant::dashboard.today') : '');
        }
        $askedTotal = array_sum($askedDays);
        $answered = array_sum($answeredDays);

        // ফানেল — জিজ্ঞাসা → বোঝা গেছে (উত্তর বা চাবি নেই) → উত্তর → 👍
        $understood = $answered + $asked()->where('kind', AssistantEvent::BLOCKED)->where('outcome', 'like', 'no_key:%')->count();
        $liked = $asked()->where('kind', AssistantEvent::QUESTION)->where('feedback', 1)->count();

        // উত্তর কোন মডিউল থেকে — দরজার নাম থেকে তার মডিউল
        $toolModule = [];
        $locale = app()->getLocale();
        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->assistantTools as $class) {
                $toolModule[$class::name()] = (string) ($module->name[$locale] ?? $module->name['en']);
            }
        }
        $byModule = [];
        foreach ($asked()->where('outcome', 'answered')->pluck('tools') as $tools) {
            $tools = is_array($tools) ? $tools : (array) json_decode((string) $tools, true);
            $name = (string) ($tools[0]['name'] ?? '');
            $label = $toolModule[$name] ?? __('assistant::dashboard.other_tool');
            $byModule[$label] = ($byModule[$label] ?? 0) + 1;
        }
        arsort($byModule);

        // কে বেশি জিজ্ঞেস করেন (খাতার চাবি) — নইলে নিজের সবচেয়ে বেশি খোঁজা কথা
        if ($whole) {
            $rows = $asked()->whereNotNull('user_id')->selectRaw('user_id, COUNT(*) as n')->groupBy('user_id')->orderByDesc('n')->limit(7)->toBase()->get();
            $names = User::query()->whereKey($rows->pluck('user_id')->all())->pluck('name', 'id');
            $people = $rows->map(fn ($r) => [(string) ($names[$r->user_id] ?? '—'), (int) $r->n])->all();
        } else {
            $people = $seen()->where('kind', AssistantEvent::SEARCH)->whereNotNull('text')
                ->selectRaw('text, COUNT(*) as n')->groupBy('text')->orderByDesc('n')->limit(7)->toBase()->get()
                ->map(fn ($r) => [mb_strimwidth((string) $r->text, 0, 18, '…'), (int) $r->n])->all();
        }

        // কখন জিজ্ঞেস করা হয় — বার × সময়
        $weekdays = [6, 0, 1, 2, 3, 4, 5];
        $bands = [[6, 12], [12, 15], [15, 18], [18, 21], [21, 30]];
        $grid = array_fill_keys($weekdays, array_fill(0, count($bands), 0));
        foreach ($asked()->pluck('created_at') as $at) {
            $at = Carbon::parse($at);
            $hour = $at->hour < 6 ? $at->hour + 24 : $at->hour;
            foreach ($bands as $k => [$a, $b]) {
                if ($hour >= $a && $hour < $b) {
                    $grid[$at->dayOfWeek][$k]++;
                }
            }
        }
        $wd = (array) __('assistant::dashboard.weekdays');

        // কোপাইলটের কোন অংশ কতটা — খাতার ধরন ধরে, পাতা খোলা বাদ
        $kinds = $seen()->where('kind', '!=', AssistantEvent::VISIT)->selectRaw('kind, COUNT(*) as n')->groupBy('kind')->pluck('n', 'kind');
        $parts = [];
        foreach (AssistantEvent::KINDS as $kind) {
            if ($kind !== AssistantEvent::VISIT && (int) ($kinds[$kind] ?? 0) > 0) {
                $parts[] = [(string) __('assistant::audit.kinds.'.$kind), (int) $kinds[$kind]];
            }
        }

        $audit = $whole ? route('assistant.audit') : '';

        return [
            Card::make(__('assistant::dashboard.per_day'), 'line', [
                'v' => $askedDays, 'v2' => $answeredDays, 'l' => $labels, 'color' => '#2160d8', 'c2' => '#16a34a',
            ], value: (string) $askedTotal, sub: __('assistant::dashboard.per_day_hint', ['range' => $range]), legend: [
                [__('assistant::dashboard.asked'), '#2160d8'], [__('assistant::dashboard.answered'), '#16a34a'],
            ], href: route('assistant.ask'), colour: '#2160d8'),
            Card::make(__('assistant::dashboard.funnel'), 'funnel', ['p' => [
                [__('assistant::dashboard.asked'), $askedTotal, number_format($askedTotal)],
                [__('assistant::dashboard.understood'), $understood, number_format($understood)],
                [__('assistant::dashboard.answered'), $answered, number_format($answered)],
                [__('assistant::dashboard.liked'), $liked, number_format($liked)],
            ]], value: $askedTotal === 0 ? '' : intdiv($answered * 100, $askedTotal).'%',
                sub: __('assistant::dashboard.funnel_hint', ['range' => $range]), href: $audit, colour: '#16a34a'),
            Card::make(__('assistant::dashboard.by_module'), 'donut', [
                'c' => (string) $answered, 'cs' => __('assistant::dashboard.answers'),
                'p' => array_map(fn (string $label, int $n) => [$label, $n, null, (string) $n], array_keys($byModule), array_values($byModule)),
            ], sub: __('assistant::dashboard.by_module_hint', ['range' => $range]), colour: '#7c3aed'),
            Card::make($whole ? __('assistant::dashboard.who_asks') : __('assistant::dashboard.my_searches'), 'lollipop', [
                'v' => array_map(fn (array $p) => $p[1], $people), 'l' => array_map(fn (array $p) => $p[0], $people),
            ], sub: __($whole ? 'assistant::dashboard.who_asks_hint' : 'assistant::dashboard.my_searches_hint', ['range' => $range]),
                href: $audit, colour: '#e11d48'),
            Card::make(__('assistant::dashboard.when'), 'heat', [
                'rows' => array_map(fn (int $d) => (string) ($wd[$d] ?? $d), $weekdays),
                'cols' => (array) __('assistant::dashboard.bands'),
                'v' => array_values($grid), 'color' => '#f59e0b',
            ], sub: __('assistant::dashboard.when_hint', ['range' => $range]), colour: '#f59e0b'),
            Card::make(__('assistant::dashboard.parts'), 'treemap', ['items' => $parts],
                sub: __('assistant::dashboard.parts_hint', ['range' => $range]), href: $audit, colour: '#14b8a6'),
        ];
    }

    /**
     * ⛔ কার সারি দেখা যায় — খাতার চাবি থাকলে গোটা কোম্পানি (খাতার পর্দার একই চাবি), নইলে কেবল নিজের।
     *
     * @return Builder<AssistantEvent>
     */
    private static function seen(mixed $user, bool $whole): Builder
    {
        if (! $user instanceof User) {
            return AssistantEvent::query()->whereRaw('1 = 0');
        }

        return $whole ? AssistantEvent::query() : AssistantEvent::query()->where('assistant_events.user_id', $user->id);
    }
}
