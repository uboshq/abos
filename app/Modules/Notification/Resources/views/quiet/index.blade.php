{{--
    ⭐ নীরব সময় আর সারসংক্ষেপ — কোম্পানির স্বাভাবিক নীরব সময়, কে কী বেছেছেন, গত সাত দিনে কত খবর পিছানো আর ধরে রাখা
    (মালিকের স্পেক §৪, §১৪; ধাপ ৩)। ⛔ জরুরি খবর নীরব সময়েও যায়।
--}}
@php $field = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm'; @endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::quiet.title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::quiet.title')" :search="false" :export="false" :share="false" />

        <p class="text-sm text-(--color-ink-muted)">{{ __('notification::quiet.note') }}</p>

        @include('notification::partials.flash')

        <div class="grid gap-4 md:grid-cols-3">
            <form method="POST" action="{{ route('notification.quiet.save') }}"
                  class="space-y-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 md:col-span-2">
                @csrf
                <h2 class="text-sm font-medium">{{ __('notification::quiet.defaults') }}</h2>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('notification::quiet.defaults_note') }}</p>
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                        {{ __('notification::quiet.start') }}
                        <input type="time" name="quiet_start" value="{{ old('quiet_start', $quietStart) }}" class="{{ $field }}">
                    </label>
                    <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                        {{ __('notification::quiet.end') }}
                        <input type="time" name="quiet_end" value="{{ old('quiet_end', $quietEnd) }}" class="{{ $field }}">
                    </label>
                </div>
                <button type="submit" class="rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::quiet.save') }}</button>
            </form>

            <div class="space-y-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
                <p>{{ __('notification::quiet.deferred') }}: <span class="font-semibold tabular-nums" data-quiet-deferred>{{ $deferred }}</span></p>
                <p>{{ __('notification::quiet.held') }}: <span class="font-semibold tabular-nums" data-quiet-held>{{ $held }}</span></p>
            </div>
        </div>

        <h2 class="text-sm font-medium">{{ __('notification::quiet.people') }}</h2>
        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::quiet.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::delivery.recipient') }}</th>
                            <th class="text-start">{{ __('notification::quiet.window') }}</th>
                            <th class="text-start">{{ __('notification::quiet.frequency') }}</th>
                            <th class="text-start">{{ __('notification::quiet.timezone') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $pref)
                            <tr class="border-t border-(--color-border)" data-pref="{{ $pref->user_id }}">
                                <td class="text-2xs">{{ $pref->user?->name ?? '—' }}</td>
                                <td class="text-2xs">{{ $pref->quiet_enabled ? substr((string) $pref->quiet_start, 0, 5).' – '.substr((string) $pref->quiet_end, 0, 5) : '—' }}</td>
                                <td class="text-2xs">{{ __('core.notify.frequency.'.$pref->frequency) }}</td>
                                <td class="text-2xs">{{ $pref->timezone ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
