<x-ui.badge :tone="match ($claim->status) {
    'accepted' => 'success',
    'rejected' => 'danger',
    'verifying' => 'info',
    default => 'pending',
}">{{ $claim->statusLabel() }}</x-ui.badge>
