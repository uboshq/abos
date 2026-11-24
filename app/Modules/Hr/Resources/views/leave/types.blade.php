{{--
    ছুটির ধরন — তালিকা আর যোগ করার ঘর একই পাতায়।

    আলাদা ফর্ম পাতা নেই, কারণ ঘর মাত্র পাঁচটা আর কাজটা বছরে দুইবার হয়।
    এক পাতায় থাকলে যোগ করেই তালিকায় দেখা যায়।
--}}
@php
    $columns = [
        ['key' => 'code', 'label' => __('hr::field.code'), 'width' => '9rem',
         'render' => fn ($t) => $t->code],
        ['key' => 'name', 'label' => __('hr::field.name'),
         'render' => fn ($t) => $t->name()],
        ['key' => 'days', 'label' => __('hr::field.days_per_year'), 'numeric' => true,
         'width' => '9rem',
         'render' => fn ($t) => $t->isEarned() ? __('hr::message.earned_accrues', ['per' => \App\Modules\Hr\Services\EarnedLeave::WORKED_DAYS_PER_DAY])
             : ($t->hasNoYearlyLimit() ? '—' : rtrim(rtrim((string) $t->days_per_year, '0'), '.'))],
        ['key' => 'paid', 'label' => __('hr::field.is_paid'), 'width' => '7rem',
         'render' => fn ($t) => $t->is_paid ? '✓' : '—'],
    ];
    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে ([[AbosHead]]); বাকি রূপ আগের মতো
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('paid', __('hr::field.is_paid'), ['yes' => '✓', 'no' => '—']),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.leave_types') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('hr::menu.leave_types')">
            <x-slot:actions>
                <x-ui.button :href="route('hr.leave.index')">{{ __('hr::menu.leave') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>
    @endunless

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($canInstallDefaults)
        <div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-8 text-center">
            {{-- ⓘ খালি তালিকা, বা কিছু প্রমিত ধরন বাকি (যেমন নতুন মাতৃত্বকালীন) — [[LeaveTypeDefaults::anyMissing()]] --}}
            <h2 class="text-lg font-semibold">{{ __($types->isEmpty() && ! request()->filled('q') && ! request()->filled('paid') ? 'hr::message.empty_leave_types' : 'hr::message.leave_types_missing') }}</h2>

            @can('hr.leave.manage')
                <form method="POST" action="{{ route('hr.leave_type.install') }}" class="mt-4">
                    @csrf
                    <x-ui.button type="submit" tone="primary">
                        {{ __('hr::action.install_leave_types') }}
                    </x-ui.button>
                </form>
            @endcan
        </div>
    @endif

    {{-- ⭐ অর্জিত ছুটির পাওনা হাজিরা থেকে, বাঁধা দিন নয় — "বছরে দিন" ঘরে হার লেখা (শ্রম আইন ধারা ১১৭; [[EarnedLeave]]; a6, ২১ অক্টোবর ২০২৬) --}}
    <x-ui.filter-layout :filters="$facetPanel ?? \App\Core\ListFilter\Facets::panel([])" form="list-form">
    <div class="grid gap-4 lg:grid-cols-[1fr_20rem]">
        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            @if ($abosHead)
                <form id="list-form" method="GET" class="contents">
                    <x-ui.toolbar :title="__('hr::menu.leave_types')" :search-placeholder="__('hr::field.name')" :columns="$columns" :facets="$facetPanel"
                                  :sort="['code' => __('hr::field.code'), 'name' => __('hr::field.name'), 'days' => __('hr::field.days_per_year')]">
                        <x-slot:actions>
                            <x-ui.button :href="route('hr.leave.index')">{{ __('hr::menu.leave') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.toolbar>
                </form>
            @endif
            <x-ui.table
                :empty="__('hr::message.no_leave_types')"
                :rows="$types"
                :columns="$columns" />
        </div>

        @can('hr.leave.manage')
            <form method="POST" action="{{ route('hr.leave_type.store') }}"
                  class="rounded-(--radius-card) border border-(--color-border)
                         bg-(--color-surface-card) p-4">
                @csrf

                <h2 class="mb-3 font-semibold">{{ __('hr::action.add_type') }}</h2>

                <div class="space-y-3">
                    <x-ui.field name="code" :label="__('hr::field.code')" :value="old('code')"
                                :placeholder="__('core.create.code_auto')" />
                    <x-ui.field name="name_en" :label="__('hr::field.leave_type_name_en')" :value="old('name_en')" required />
                    <x-ui.field name="name_bn" :label="__('hr::field.leave_type_name_bn')" :value="old('name_bn')" />
                    <x-ui.field name="days_per_year" type="number" step="0.5" min="0"
                                :label="__('hr::field.days_per_year')"
                                :value="old('days_per_year', 0)" required />

                    {{-- বিনা বেতনের ছুটিতে এই ঘরটা খালি থাকে, আর তখন
                         অনুপস্থিতির মতোই বেতন কাটে। --}}
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_paid" value="1" @checked(old('is_paid', true))
                               class="size-4">
                        {{ __('hr::field.is_paid') }}
                    </label>
                </div>

                <x-ui.button class="mt-3" type="submit" tone="primary">{{ __('hr::action.save') }}</x-ui.button>
            </form>
        @endcan
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
