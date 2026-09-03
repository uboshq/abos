{{--
    সূচি তৈরি ও সম্পাদনা — একটাই ফর্ম।

    frequency অনুযায়ী দিনের ঘরগুলো Alpine-এ দেখা/লুকানো: সাপ্তাহিকে বার,
    মাসিকে তারিখ বা "মাসের শেষ দিন"। জমা পড়ে কেবল প্রাসঙ্গিক ঘরগুলো।
--}}
@php
    $isNew = ! $schedule->exists;
    $sel = fn (string $name, $default = null) => old($name, $schedule->{$name} ?? $default);
    $field = 'h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) px-3 bg-(--color-surface-card)';
    $chosen = (array) old('recipients', $schedule->recipients ?? []);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('system_admin::schedule.add') : __('system_admin::schedule.edit') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$isNew ? __('system_admin::schedule.add') : __('system_admin::schedule.edit')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('system_admin.reports.schedule.store') : route('system_admin.reports.schedule.update', $schedule) }}"
          x-data="{ freq: '{{ $sel('frequency', 'daily') }}' }"
          class="max-w-3xl space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        @if ($errors->any())
            <div role="alert" class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                                     text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('system_admin::schedule.report') }}</span>
                    <select name="report_key" class="{{ $field }}">
                        @foreach ($reportTitles as $key => $title)
                            <option value="{{ $key }}" @selected($sel('report_key') === $key)>{{ $title }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('system_admin::schedule.format') }}</span>
                    <select name="format" class="{{ $field }}">
                        @foreach (['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV', 'json' => 'JSON', 'pdf' => 'PDF'] as $v => $l)
                            <option value="{{ $v }}" @selected($sel('format', 'xlsx') === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('system_admin::schedule.frequency') }}</span>
                    <select name="frequency" x-model="freq" class="{{ $field }}">
                        @foreach (['daily', 'weekly', 'monthly'] as $f)
                            <option value="{{ $f }}" @selected($sel('frequency', 'daily') === $f)>
                                {{ __('system_admin::schedule.freq.'.$f) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <x-ui.field name="at_time" type="time" :label="__('system_admin::schedule.at_time')"
                            :value="$sel('at_time', '08:00')" />

                {{-- সাপ্তাহিক — বার --}}
                <label class="block" x-show="freq === 'weekly'" x-cloak>
                    <span class="mb-1 block text-sm font-medium">{{ __('system_admin::schedule.day_of_week') }}</span>
                    <select name="day_of_week" class="{{ $field }}">
                        @foreach (__('system_admin::schedule.weekday') as $n => $label)
                            <option value="{{ $n }}" @selected((string) $sel('day_of_week', 1) === (string) $n)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                {{-- মাসিক — তারিখ (১–২৮) বা শেষ দিন --}}
                <div x-show="freq === 'monthly'" x-cloak class="space-y-2">
                    <x-ui.field name="day_of_month" type="number" min="1" max="28"
                                :label="__('system_admin::schedule.day_of_month')"
                                :value="$sel('day_of_month', 1)" numeric />
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="on_month_end" value="1" @checked($sel('on_month_end'))>
                        {{ __('system_admin::schedule.on_month_end') }}
                    </label>
                </div>

                <x-ui.field name="timezone" :label="__('system_admin::schedule.timezone')"
                            :value="$sel('timezone', config('app.timezone'))" />
            </div>
        </section>

        {{-- প্রাপক — কেবল যাঁদের অনুমতি আছে; খালি রাখলে শুধু নিজের --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('system_admin::schedule.recipients') }}</h2>
            <p class="mb-3 text-2xs text-(--color-ink-muted)">{{ __('system_admin::schedule.recipients_hint') }}</p>

            <div class="grid gap-2 sm:grid-cols-3">
                @foreach ($users as $u)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="recipients[]" value="{{ $u->id }}"
                               @checked(in_array($u->id, array_map('intval', $chosen), true))>
                        {{ $u->name }}
                    </label>
                @endforeach
            </div>
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('system_admin.reports.schedule.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
