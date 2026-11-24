{{--
    ⭐ লগইন নিরাপত্তা — তিন ABOS রূপে: বার উপরে, নিচে চার কার্ড, দুই ট্যাব (মালিকের "ok", ২৫ নভেম্বর ২০২৬; নমুনা
    BNUbjUdEXdfYqxgyjbcn2m)। ⛔ নামানো নেই। ⓘ বাকি রূপে আগের পাতা ([[system_admin::security.show]])।
--}}
@php
    $step = fn ($row) => \App\Modules\SystemAdmin\Http\Controllers\LoginSecurityController::twoStepOf($row);
    $when = fn ($at) => $at ? \App\Core\Support\DateFormat::spoken(\Illuminate\Support\Carbon::parse($at)) : '—';
    $on = $everyone->where('two_step', true)->count();
    $reasons = ['unknown', 'password', 'inactive', 'wrong_code', 'locked', 'handoff_refused'];

    $tabs = [
        ['key' => 'people', 'label' => __('system_admin::security.people'), 'count' => $everyone->count(), 'url' => route('system_admin.login-security'), 'active' => $tab === 'people'],
        ['key' => 'failed', 'label' => __('system_admin::security.tab_failed'), 'url' => route('system_admin.login-security', ['tab' => 'failed']), 'active' => $tab === 'failed'],
    ];

    $facetPanel = \App\Core\ListFilter\Facets::panel($tab === 'people' ? [
        \App\Core\ListFilter\Facets::pick('two_step', __('system_admin::security.two_step'),
            ['on' => __('system_admin::security.on'), 'required' => __('system_admin::security.required_not_set'), 'off' => __('system_admin::security.off')]),
        \App\Core\ListFilter\Facets::pick('state', __('system_admin::security.state'),
            ['active' => __('system_admin::security.active'), 'inactive' => __('system_admin::security.inactive')]),
    ] : [
        \App\Core\ListFilter\Facets::pick('reason', __('system_admin::security.reason'),
            collect($reasons)->mapWithKeys(fn ($r) => [$r => __('system_admin::security.reason_'.$r)])->all()),
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
    ]);

    $columns = $tab === 'people' ? [
        ['key' => 'name', 'label' => __('system_admin::field.user_name'), 'render' => fn ($row) => view('system_admin::security.partials.name', ['row' => $row])],
        ['key' => 'two_step', 'label' => __('system_admin::security.two_step'), 'render' => fn ($row) => view('system_admin::security.partials.two-step', ['step' => $step($row)])],
        ['key' => 'last_in', 'label' => __('system_admin::security.last_in'), 'width' => '16rem', 'render' => fn ($row) => $when($row['last_in'])],
        ['key' => 'phones', 'label' => __('system_admin::security.phones'), 'numeric' => true, 'width' => '10rem', 'render' => fn ($row) => (string) $row['phones']],
    ] : [
        ['key' => 'who', 'label' => __('system_admin::security.who'), 'render' => fn ($try) => (string) $try->identifier],
        ['key' => 'reason', 'label' => __('system_admin::security.reason'), 'render' => fn ($try) => __('system_admin::security.reason_'.$try->reason)],
        ['key' => 'ip', 'label' => __('system_admin::security.ip'), 'width' => '12rem', 'render' => fn ($try) => (string) $try->ip_address],
        ['key' => 'when', 'label' => __('system_admin::security.when'), 'width' => '16rem', 'render' => fn ($try) => $when($try->created_at)],
    ];
    $rows = $tab === 'people' ? $people : $failed;
    $mayAll = \Illuminate\Support\Facades\Route::has('governance.login.index') && auth()->user()->can('governance.login.view');
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::security.title') }}</x-slot:title>

    {{-- ⓘ এই পাতা থেকে বদলের দরজায় গিয়ে ফেরত এলে কারণটা এখানে দেখা যায় --}}
    <x-ui.errors />

    <x-ui.list-tabs :tabs="$tabs" :label="__('system_admin::security.title')" />

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            @if ($tab === 'failed')
                <input type="hidden" name="tab" value="failed">
            @endif
            <x-ui.toolbar :title="__('system_admin::security.title')"
                          :info="__('system_admin::security.subtitle').' '.__('system_admin::settings.auto_logout_minutes_hint')"
                          :search-placeholder="__('system_admin::security.find')" :export="false" :print="false" :share="false"
                          :columns="$columns" :facets="$facetPanel" :quiet="['tab']"
                          :sort="$tab === 'people'
                              ? ['name' => __('system_admin::field.user_name'), 'last_in' => __('system_admin::security.sort_last_in'), 'phones' => __('system_admin::security.sort_phones')]
                              : ['newest' => __('system_admin::security.sort_newest'), 'oldest' => __('system_admin::security.sort_oldest')]">
                @if ($mayAll)
                    <x-slot:actions>
                        <x-ui.button tone="secondary" :href="route('governance.login.index')">{{ __('system_admin::security.all_logins') }}</x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.toolbar>
        </form>

        {{-- ── এক নজরে — গোটা কোম্পানির সংখ্যা ── --}}
        <div class="list-cards p-3" data-list-cards data-security-summary>
            @foreach ([
                ['two-step', __('system_admin::security.two_step_on'), $on.' / '.$everyone->count(), '#b45309', '#d97706', 'lock'],
                ['failed', __('system_admin::security.failed_week'), (string) $failedCount, '#be123c', '#e11d48', 'alert-triangle'],
                ['phones', __('system_admin::security.phones'), (string) $everyone->sum('phones'), '#2346d8', '#3b6cf6', 'chat'],
                ['logout', __('system_admin::security.auto_logout'), $logoutMinutes > 0 ? __('system_admin::security.minutes', ['n' => $logoutMinutes]) : __('system_admin::security.never'), '#047857', '#059669', 'clock'],
            ] as [$key, $label, $value, $a, $b, $icon])
                <div class="list-card" data-security-card="{{ $key }}" style="--a: {{ $a }}; --b: {{ $b }}">
                    <span class="list-card-icon" aria-hidden="true"><x-ui.icon :name="$icon" :size="14" /></span>
                    <span class="min-w-0">
                        <span class="list-card-label">{{ $label }}</span>
                        <span class="list-card-value num">{{ $value }}</span>
                    </span>
                    @if ($key === 'logout')
                        <a data-security-logout href="{{ route('system_admin.control-panel', ['find' => __('system_admin::settings.auto_logout_minutes')]) }}" class="ms-auto text-xs font-semibold underline">{{ __('system_admin::security.change') }}</a>
                    @endif
                </div>
            @endforeach
        </div>

        <div @if ($tab === 'people') data-security-people @else data-security-failed @endif>
            <x-ui.table :rows="$rows" :columns="$columns" :compact="\App\Core\Support\Ui::compact()"
                        :empty="$tab === 'failed' ? __('system_admin::security.no_failed') : __('core.empty.no_results')"
                        :row-attributes="$tab === 'people' ? fn ($row) => ['data-security-person' => $row['user']->id] : null" />
        </div>
        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
