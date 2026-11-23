{{--
    ⭐ খাতা — কে কী করল, কী খুঁজল, কোন পাতা খুলল, কী নামাল, কী বদলাল, কখন ঢুকল; কার আইডি থেকে, কখন, কোথা থেকে, কীভাবে
    (মালিক, ১০ ও ১১ নভেম্বর ২০২৬; পূর্ণ পরিকল্পনা অংশ ১২)। ⓘ তিন খাতা এক সময়-রেখায় ([[AssistantController::audit()]])।
    ⓘ কেবল মালিকের চাবি; ছাঁকা যায় — তারিখ, ধরন, ব্যক্তি, লেখা, IP; বদলানো বা মোছা যায় না।
--}}
@php
    $tone = [
        'change' => 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)',
        'login' => 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)',
        'search' => 'bg-(--color-surface-selected) text-(--color-link)',
        'blocked' => 'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)',
    ];
    $field = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm';

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
        \App\Core\ListFilter\Facets::pick('kind', __('assistant::audit.kind'),
            collect($kinds)->mapWithKeys(fn ($kind) => [$kind => __('assistant::audit.kinds.'.$kind)])->all(),
            __('assistant::audit.any')),
        \App\Core\ListFilter\Facets::pick('user_id', __('assistant::audit.who'),
            collect($people)->mapWithKeys(fn ($person) => [(string) $person->id => $person->name])->all(),
            __('assistant::audit.anyone')),
        \App\Core\ListFilter\Facets::text('q', __('assistant::audit.search')),
        \App\Core\ListFilter\Facets::text('ip', 'IP'),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('assistant::audit.title', ['name' => $name]) }}</x-slot:title>

    <div class="space-y-4" data-assistant-audit>
        <x-ui.toolbar :title="__('assistant::audit.title', ['name' => $name])" :info="__('assistant::audit.note')" :search="false" :export="false" :share="false" />

        {{-- ⭐ ব্যাখ্যা নামের পাশে ⓘ-এ, লাইনটা "ABOS"/"Dynamic"-এ লুকায় ([[ui/page-info]]; মালিক, ১৯ নভেম্বর ২০২৬ রাত) --}}
        <p data-page-subtitle class="text-sm text-(--color-ink-muted)">{{ __('assistant::audit.note') }}</p>

        <x-ui.filter-layout :filters="$facetPanel" form="list-form">
            <form id="list-form" method="GET" class="contents">
                <x-ui.filter-bar :facets="$facetPanel" />
            </form>
        </x-ui.filter-layout>

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('assistant::audit.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('assistant::audit.when') }}</th>
                            <th class="text-start">{{ __('assistant::audit.who') }}</th>
                            <th class="text-start">{{ __('assistant::audit.kind') }}</th>
                            <th class="text-start">{{ __('assistant::audit.what') }}</th>
                            <th class="text-start">{{ __('assistant::audit.page') }}</th>
                            <th class="text-start">{{ __('assistant::audit.how') }}</th>
                            <th class="text-start">{{ __('assistant::audit.where') }}</th>
                            <th class="text-start">{{ __('assistant::audit.outcome') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $person = $who->get($row->user_id);
                                $channel = $row->channel ?? null;
                            @endphp
                            <tr class="border-t border-(--color-border) align-top" data-audit-row="{{ $row->kind }}">
                                <td class="whitespace-nowrap text-2xs tabular-nums">{{ \App\Core\Support\DateFormat::formatWithTime(\Illuminate\Support\Carbon::parse($row->at)) }}</td>
                                <td class="text-2xs">
                                    @if ($row->user_id === null)
                                        {{ $name }}
                                    @else
                                        <span class="block font-medium">{{ $person?->name ?? '#'.$row->user_id }}</span>
                                        <span class="block text-(--color-ink-muted)">ID {{ $row->user_id }}@if ($person?->email) · {{ $person->email }}@endif</span>
                                    @endif
                                </td>
                                <td class="text-2xs"><span class="rounded-(--radius-badge) px-1.5 py-0.5 {{ $tone[$row->kind] ?? 'bg-(--color-surface-sunken) text-(--color-ink)' }}">{{ __('assistant::audit.kinds.'.$row->kind) }}</span></td>
                                <td class="max-w-md text-2xs">
                                    @if ($row->kind === 'visit' && str_starts_with((string) $row->what, '/') === false && isset($pages[strtok((string) $row->page, '?')]))
                                        {{ $pages[strtok((string) $row->page, '?')] }}
                                    @else
                                        {{ $row->what ?? __('assistant::audit.text_gone') }}
                                    @endif
                                    @if ($row->reason)<span class="block text-(--color-ink-muted)">{{ __('assistant::audit.reason') }}: {{ $row->reason }}</span>@endif
                                </td>
                                <td class="max-w-xs break-all text-2xs">{{ $row->kind === 'change' ? class_basename((string) $row->page) : ($row->page ?? '—') }}</td>
                                <td class="whitespace-nowrap text-2xs" title="{{ $row->user_agent }}">
                                    {{ \App\Modules\Assistant\Support\Device::name($row->user_agent, $channel) }}
                                    @if ($channel === 'app' || $channel === 'web')<span class="block text-(--color-ink-muted)">{{ __('assistant::audit.channels.'.$channel) }}</span>@endif
                                </td>
                                <td class="whitespace-nowrap text-2xs tabular-nums">
                                    {{ $row->ip_address ?? '—' }}
                                    @if ($row->place)<span class="block text-(--color-ink-muted)">{{ $row->place }}</span>@endif
                                    @if ($row->branch_id && isset($branches[$row->branch_id]))<span class="block text-(--color-ink-muted)">{{ $branches[$row->branch_id] }}</span>@endif
                                </td>
                                <td class="text-2xs">
                                    @if ($row->kind === 'search')
                                        {{ __('assistant::audit.hits', ['n' => (int) $row->outcome]) }}
                                    @elseif ($row->kind === 'login')
                                        {{ $row->outcome === 'ok' ? __('assistant::audit.login_ok') : __('assistant::audit.login_failed').' · '.$row->outcome }}
                                    @elseif ($row->kind === 'change')
                                        {{ \Illuminate\Support\Facades\Lang::has('assistant::audit.actions.'.$row->outcome) ? __('assistant::audit.actions.'.$row->outcome) : str_replace('_', ' ', (string) $row->outcome) }}
                                    @else
                                        {{ $row->outcome ?? '—' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-ui.pager :rows="$rows" />
    </div>
</x-layouts.app>
