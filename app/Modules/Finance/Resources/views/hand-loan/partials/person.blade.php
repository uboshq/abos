{{-- নাম, নম্বর, আর চুকে গেলে সেটা --}}
<span class="inline-flex flex-col">
    <span class="inline-flex items-center gap-1.5">
        {{ $row['account']->person_name }}

        @if ($row['account']->isSettled())
            <x-ui.badge tone="draft">{{ __('finance::state.settled') }}</x-ui.badge>
        @endif
    </span>

    @if ($row['account']->mobile)
        <span class="num text-2xs text-(--color-ink-muted)">{{ $row['account']->mobile }}</span>
    @endif
</span>
