<span @class(['text-2xs', 'font-semibold text-(--color-badge-danger-ink)' => $check?->status === 'failed'])>
    @if ($check === null)
        <span class="text-(--color-ink-muted)">{{ __('backup::screen.check_none') }}</span>
    @elseif ($check->sawSomething())
        {{ __('backup::screen.check_passed', ['tables' => $check->detail['tables'] ?? 0]) }}
    @else
        {{ __('backup::screen.check_failed') }}
        @if (! empty($check->detail['error']))
            <span class="mt-0.5 block font-mono break-all">{{ $seesRawErrors ? $check->detail['error'] : __('backup::message.failed_detail_hidden') }}</span>
        @endif
    @endif
</span>
