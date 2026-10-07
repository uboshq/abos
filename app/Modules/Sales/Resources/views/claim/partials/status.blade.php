<x-ui.badge :tone="match ($claim->status) {
    'accepted' => 'success',
    'rejected' => 'danger',
    default => 'pending',
}">{{ __('sales::portal.'.$claim->status) }}</x-ui.badge>
