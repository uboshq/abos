{{--
    ⭐ মাধ্যমের তালিকা — কোনটা চালু, কোনটা সংযুক্ত, প্রোভাইডার, শেষ পরীক্ষা (মালিকের স্পেক §৪; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২)।
    ⓘ ঘণ্টা নিজে কোনো মাধ্যম নয় — সব মাধ্যম বন্ধ থাকলেও ঘণ্টায় খবর আসে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::channel.title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::channel.title')" :search="false" :export="false" :share="false" />

        <p class="text-sm text-(--color-ink-muted)">{{ __('notification::channel.note') }}</p>

        @if (session('saved'))
            <p role="status" class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                    text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
        @endif

        <div class="overflow-x-auto">
            <table class="ui-grid">
                <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                    <tr>
                        <th class="text-start">{{ __('notification::delivery.channel') }}</th>
                        <th class="text-start">{{ __('notification::delivery.status') }}</th>
                        <th class="text-start">{{ __('notification::channel.provider') }}</th>
                        <th class="text-start">{{ __('notification::channel.sender') }}</th>
                        <th class="text-start">{{ __('notification::channel.last_check') }}</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-(--color-border)" data-channel="{{ $row['key'] }}" data-connected="{{ $row['connected'] ? '1' : '0' }}">
                            <td>{{ __('notification::channel.names.'.$row['key']) }}</td>
                            <td class="text-2xs">
                                <span @class([
                                    'rounded-full px-1.5 py-0.5',
                                    'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $row['connected'],
                                    'text-(--color-ink-muted)' => ! $row['connected'],
                                ])>{{ __('notification::channel.'.($row['connected'] ? 'connected' : 'not_connected')) }}</span>
                                <span class="ms-1 text-(--color-ink-muted)">{{ __('notification::channel.'.(($row['config']?->enabled) ? 'enabled' : 'disabled')) }}</span>
                            </td>
                            <td class="text-2xs">{{ $row['provider'] ?: __('notification::delivery.none') }}</td>
                            <td class="text-2xs">{{ $row['config']?->sender_id ?: __('notification::delivery.none') }}</td>
                            <td class="whitespace-nowrap text-2xs">
                                @if ($row['config']?->last_checked_at)
                                    {{ $row['config']->last_checked_at->format('d/m/Y H:i') }}
                                    · {{ $row['config']->last_check_ok ? __('notification::channel.test_ok') : __('notification::channel.test_failed', ['error' => $row['config']->last_error]) }}
                                @else
                                    {{ __('notification::channel.never') }}
                                @endif
                            </td>
                            <td class="text-end text-2xs">
                                <a href="{{ route('notification.channels.edit', $row['key']) }}" class="text-(--color-brand-500) hover:underline">{{ __('notification::channel.edit') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
