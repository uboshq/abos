{{--
    ⭐ মাধ্যমের স্বাস্থ্য — সংযুক্ত কি, শেষ পরীক্ষা, ২৪ ঘণ্টা আর ৭ দিনের সাফল্য, গড় সময়, কিউ, ব্যর্থ-তালিকা, আটকে থাকা
    (মালিকের স্পেক §৪, §১৫; ধাপ ২)। প্রতিটা সংখ্যা তার তালিকায় খোলে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::delivery.health_title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::delivery.health_title')" :search="false" :export="false" :share="false" />

        <p class="text-sm text-(--color-ink-muted)">{{ __('notification::delivery.health_note') }}</p>

        <div class="overflow-x-auto">
            <table class="ui-grid">
                <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                    <tr>
                        <th class="text-start">{{ __('notification::delivery.channel') }}</th>
                        <th class="text-start">{{ __('notification::delivery.connected') }}</th>
                        <th class="text-start">{{ __('notification::delivery.last_check') }}</th>
                        <th class="text-end">{{ __('notification::delivery.day') }} · {{ __('notification::delivery.success') }}</th>
                        <th class="text-end">{{ __('notification::delivery.week') }} · {{ __('notification::delivery.success') }}</th>
                        <th class="text-end">{{ __('notification::delivery.failures') }}</th>
                        <th class="text-end">{{ __('notification::delivery.latency') }}</th>
                        <th class="text-end">{{ __('notification::delivery.queued') }}</th>
                        <th class="text-end">{{ __('notification::delivery.dead') }}</th>
                        <th class="text-end">{{ __('notification::delivery.stuck') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-(--color-border)" data-health="{{ $row['key'] }}">
                            <td>{{ __('notification::channel.names.'.$row['key']) }}</td>
                            <td class="text-2xs">{{ __('notification::channel.'.($row['connected'] ? 'connected' : 'not_connected')) }}</td>
                            <td class="whitespace-nowrap text-2xs">
                                @if ($row['config']?->last_checked_at)
                                    {{ $row['config']->last_checked_at->format('d/m/Y H:i') }} · {{ $row['config']->last_check_ok ? '✓' : '✗' }}
                                @else
                                    {{ __('notification::channel.never') }}
                                @endif
                            </td>
                            <td class="text-end text-2xs tabular-nums">
                                {{ $row['day']['success'] === null ? __('notification::delivery.none') : $row['day']['success'].'%' }}
                                <span class="text-(--color-ink-muted)">({{ $row['day']['sent'] }}/{{ $row['day']['total'] }})</span>
                            </td>
                            <td class="text-end text-2xs tabular-nums">
                                {{ $row['week']['success'] === null ? __('notification::delivery.none') : $row['week']['success'].'%' }}
                                <span class="text-(--color-ink-muted)">({{ $row['week']['sent'] }}/{{ $row['week']['total'] }})</span>
                            </td>
                            <td class="text-end text-2xs tabular-nums">
                                <a href="{{ route('notification.deliveries.logs', ['channel' => $row['key']]) }}" class="hover:underline">{{ $row['week']['failed'] }}</a>
                            </td>
                            <td class="text-end text-2xs tabular-nums">{{ $row['week']['latency'] === null ? __('notification::delivery.none') : $row['week']['latency'].' ms' }}</td>
                            <td class="text-end text-2xs tabular-nums">
                                <a href="{{ route('notification.deliveries.queue', ['channel' => $row['key']]) }}" class="hover:underline">{{ $row['queued'] }}</a>
                            </td>
                            <td class="text-end text-2xs tabular-nums">
                                <a href="{{ route('notification.deliveries.failed', ['channel' => $row['key']]) }}" class="hover:underline">{{ $row['dead'] }}</a>
                            </td>
                            <td class="text-end text-2xs tabular-nums">
                                <a href="{{ route('notification.deliveries.queue', ['channel' => $row['key'], 'status' => 'processing']) }}" class="hover:underline">{{ $row['stuck'] }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
