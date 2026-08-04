{{-- ডিফল্টটা আলাদা করে চিহ্নিত: নতুন লেনদেনে ওটাই আপনা থেকে বসে, আর
     কোনটা বসবে তা না জানলে ভুল ধরা যায় না --}}
<span class="flex flex-wrap items-center gap-2">
    <span>{{ $record->name() }}</span>

    @if (($record->is_default ?? false) === true)
        <x-ui.badge tone="info">{{ __('master_data::field.is_default') }}</x-ui.badge>
    @endif

    @unless ($record->is_active)
        <x-ui.badge tone="neutral">{{ __('customer::state.inactive') }}</x-ui.badge>
    @endunless
</span>
