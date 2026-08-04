{{-- সক্রিয় নাকি নিষ্ক্রিয় — রং একা কিছু বলে না, লেখাটাই বলে। --}}
<x-ui.badge :tone="$supplier->is_active ? 'success' : 'draft'">
    {{ $supplier->is_active ? __('supplier::state.active') : __('supplier::state.inactive') }}
</x-ui.badge>
