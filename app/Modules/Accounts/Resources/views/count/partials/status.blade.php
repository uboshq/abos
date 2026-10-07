<x-ui.badge :tone="$count->isApproved() ? 'success' : 'warning'">
    {{ $count->isApproved() ? __('accounts::state.approved') : __('core.status.draft') }}
</x-ui.badge>
