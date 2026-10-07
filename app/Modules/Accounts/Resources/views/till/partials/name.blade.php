{{-- নাম, আর প্রধান কাউন্টার হলে সেটাও — দিনশেষে জমা এখানেই যায় --}}
<span class="flex flex-wrap items-center gap-2">
    <span>{{ $till->name() }}</span>

    @if ($till->is_primary)
        <x-ui.badge tone="info">{{ __('accounts::field.primary') }}</x-ui.badge>
    @endif

    @unless ($till->is_active)
        <x-ui.badge tone="neutral">{{ __('accounts::state.closed') }}</x-ui.badge>
    @endunless
</span>
