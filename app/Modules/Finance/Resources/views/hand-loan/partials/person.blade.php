{{-- নাম, নম্বর, আর চুকে গেলে সেটা --}}
<span class="inline-flex flex-col">
    <span class="inline-flex items-center gap-1.5">
        {{ $row['account']->person?->name() ?? '—' }}

        @if ($row['account']->isSettled())
            <x-ui.badge tone="draft">{{ __('finance::state.settled') }}</x-ui.badge>
        @endif
    </span>

    {{-- নম্বরটা এখন ব্যক্তির সারি থেকে --}}
    @if ($row['account']->person?->mobile)
        <span class="num text-2xs text-(--color-ink-muted)">{{ $row['account']->person->mobile }}</span>
    @endif
</span>
