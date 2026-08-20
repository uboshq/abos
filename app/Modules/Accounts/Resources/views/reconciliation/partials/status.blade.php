<x-ui.badge :tone="$recon->isConfirmed() ? 'success' : 'neutral'">
    {{ $recon->isConfirmed() ? __('accounts::recon.confirmed') : __('accounts::recon.draft') }}
</x-ui.badge>
