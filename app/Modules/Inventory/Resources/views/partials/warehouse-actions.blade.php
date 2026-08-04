@can('update', $warehouse)
    <a href="{{ route('inventory.warehouse.edit', $warehouse) }}"
       class="text-2xs text-(--color-brand-500) underline-offset-2 hover:underline">
        {{ __('core.action.edit') }}
    </a>
@endcan
