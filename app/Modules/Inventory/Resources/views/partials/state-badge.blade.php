{{-- সক্রিয় নাকি নিষ্ক্রিয় — রং একা কিছু বলে না, লেখাটাই বলে। --}}
<x-ui.badge :tone="$record->is_active ? 'success' : 'draft'">
    {{ $record->is_active ? __('inventory::state.active') : __('inventory::state.inactive') }}
</x-ui.badge>
