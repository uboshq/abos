{{--
    ⭐ চেষ্টার লগ — প্রতিটা চেষ্টা: কখন, কার কাছে, মাধ্যম, প্রোভাইডার, রেফারেন্স, ফল, ভুল, সময় (মালিকের স্পেক §৪, §১৪; ধাপ ২)।
    ⛔ ভুলের লেখা আগেই গোপন জিনিস ছেঁটে রাখা ([[DeliveryResult::clean()]]) — এখানে কেবল দেখানো।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::delivery.logs_title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::delivery.logs_title')" :search="false" :export="false" :share="false">
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::delivery.channel') }}
                <select name="channel" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('core.notify.any') }}</option>
                    @foreach (\App\Models\NotificationChannel::ALL as $channel)
                        <option value="{{ $channel }}" @selected(($filters['channel'] ?? '') === $channel)>{{ __('notification::channel.names.'.$channel) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::delivery.outcome') }}
                <select name="outcome" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('core.notify.any') }}</option>
                    @foreach (['sent', 'transient', 'permanent'] as $outcome)
                        <option value="{{ $outcome }}" @selected(($filters['outcome'] ?? '') === $outcome)>{{ __('notification::delivery.outcomes.'.$outcome) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::delivery.from') }}
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::delivery.to') }}
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            </label>
        </x-ui.toolbar>

        <p class="text-sm text-(--color-ink-muted)">{{ __('notification::delivery.logs_note') }}</p>

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::delivery.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::delivery.when') }}</th>
                            <th class="text-start">{{ __('notification::delivery.recipient') }}</th>
                            <th class="text-start">{{ __('notification::delivery.channel') }}</th>
                            <th class="text-start">{{ __('notification::delivery.provider') }}</th>
                            <th class="text-start">{{ __('notification::delivery.reference') }}</th>
                            <th class="text-start">{{ __('notification::delivery.outcome') }}</th>
                            <th class="text-start">{{ __('notification::delivery.error') }}</th>
                            <th class="text-end">{{ __('notification::delivery.duration') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $attempt)
                            <tr class="border-t border-(--color-border)" data-attempt="{{ $attempt->id }}">
                                <td class="whitespace-nowrap text-2xs">{{ $attempt->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td class="text-2xs">{{ $attempt->job?->user?->name ?? __('notification::delivery.none') }}</td>
                                <td class="text-2xs">{{ __('notification::channel.names.'.$attempt->channel) }}</td>
                                <td class="text-2xs">{{ $attempt->provider ?? __('notification::delivery.none') }}</td>
                                <td class="font-mono text-2xs">{{ $attempt->provider_ref ?? __('notification::delivery.none') }}</td>
                                <td class="text-2xs">{{ __('notification::delivery.outcomes.'.$attempt->outcome) }} <span class="text-(--color-ink-muted)">#{{ $attempt->attempt }}</span></td>
                                <td class="text-2xs">{{ $attempt->error ?? __('notification::delivery.none') }}</td>
                                <td class="text-end text-2xs tabular-nums">{{ $attempt->duration_ms }}</td>
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
