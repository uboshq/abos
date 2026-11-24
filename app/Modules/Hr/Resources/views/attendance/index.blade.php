{{--
    একটা দিনের হাজিরা — সবার সারি একসাথে।

    মাসিক গ্রিড নয়: ত্রিশ দিন × বিশ জন মানে ছয়শো ঘর, আর ফোনে সেটা ভরা
    যায় না। গুদামের লোক সকালে একবার আজকের পর্দাটা খোলেন — কাজের ধরনটাই
    এই রকম।
--}}
@php
    // ⛔ মাথা আর প্যানেল কেবল ABOS Smart, Navy, Blue-এ — বাকি রূপ হুবহু আগের মতো ([[AbosHead]])
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::day('date', __('hr::field.work_date'), $date->toDateString()),
    ]);
    $departments = $employees->pluck('department')->filter()->unique('id')->sortBy(fn ($d) => $d->name())->mapWithKeys(fn ($d) => [$d->id => $d->name()])->all();
    // ⓘ সংরক্ষণ থামলে (যেমন বেতন হয়ে যাওয়া মাস) যা লেখা হয়েছিল তাই ফেরে — আগে সব মুছে যেত
    $typed = is_array(old('rows')) ? old('rows') : null;
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.attendance') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header
            :title="__('hr::menu.attendance')"
            :subtitle="$date->translatedFormat('d M Y, l')">
            <x-slot:actions>
                <x-ui.button :href="route('hr.attendance.sheet', ['month' => $date->format('Y-m')])">
                    {{ __('hr::action.monthly_sheet') }}
                </x-ui.button>
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

    @unless ($abosHead)
    {{-- দিন বাছা — আলাদা ফর্ম, কারণ এটা শুধু পাতা বদলায়, কিছু সংরক্ষণ করে না --}}
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <label class="block">
            <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                         text-(--color-ink-muted)">{{ __('hr::field.work_date') }}</span>
            <x-ui.date name="date"
                       :value="$date->toDateString()"
                       :submit-on-change="true"
                       class="text-sm" />
        </label>
    </form>
    @endunless

    @if ($facetPanel)
        <div x-data="daySheet">
            <x-ui.filter-layout :filters="$facetPanel" form="list-form">
                <form id="list-form" method="GET" class="contents">
                    <x-ui.toolbar :title="__('hr::menu.attendance')" :info="__('hr::message.attendance_note')" :count="$date->translatedFormat('d M Y, l')"
                                  :search="false" :density="false" :export="false" :share="false" :print="false" :refresh="false"
                                  :quiet="['date']" :facets="$facetPanel">
                        <x-slot:find>
                            {{-- ⓘ নাম ছাড়া ঘর — ঠিকানায় যায় না, পাতা আবার খোলে না; কেবল সারি লুকায় ([[day-sheet.js]]) --}}
                            <input type="search" x-model="term" x-on:input="narrow()" x-on:keydown.enter.prevent="narrow()" autocomplete="off"
                                   placeholder="{{ __('hr::field.name') }}" aria-label="{{ __('hr::field.name') }}" data-day-find
                                   class="h-(--spacing-field-compact) min-w-48 flex-1 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                            @if (count($departments) > 1)
                                <select x-model="department" x-on:change="narrow()" aria-label="{{ __('hr::field.department') }}" data-day-department
                                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                                    <option value="">{{ __('hr::field.department') }}: {{ __('core.list_filter.any') }}</option>
                                    @foreach ($departments as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <span class="num text-sm text-(--color-ink-muted)" aria-live="polite"><span x-text="shown">{{ $employees->count() }}</span> / {{ $employees->count() }}</span>
                        </x-slot:find>
                        <x-slot:actions>
                            <x-ui.button tone="secondary" :href="route('hr.attendance.sheet', ['month' => $date->format('Y-m')])">{{ __('hr::action.monthly_sheet') }}</x-ui.button>
                            @can('hr.attendance.manage')
                                @if ($employees->isNotEmpty())
                                    <x-ui.button type="submit" form="attendance-form" tone="primary" data-save-day>{{ __('hr::action.save_attendance') }}</x-ui.button>
                                @endif
                            @endcan
                        </x-slot:actions>
                    </x-ui.toolbar>
                </form>
                @include('hr::attendance.partials.day')
            </x-ui.filter-layout>
        </div>
    @else
        @include('hr::attendance.partials.day')
    @endif
</x-layouts.app>
