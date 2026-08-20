<x-ui.badge :tone="$asset->isActive() ? 'success' : 'neutral'">
    {{ $asset->isActive() ? __('accounts::asset.active') : __('accounts::asset.disposed') }}
</x-ui.badge>
