<x-ui.badge :tone="$account->is_active ? 'success' : 'neutral'">
    {{ $account->is_active ? __('customer::state.active') : __('customer::state.inactive') }}
</x-ui.badge>
