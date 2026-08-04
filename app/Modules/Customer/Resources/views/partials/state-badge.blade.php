{{-- সক্রিয় নাকি নিষ্ক্রিয় — রং একা কিছু বলে না, লেখাটাই বলে। --}}
<x-ui.badge :tone="$customer->is_active ? 'success' : 'draft'">
    {{ $customer->is_active ? __('customer::state.active') : __('customer::state.inactive') }}
</x-ui.badge>
