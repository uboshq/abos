{{-- খসড়া · বইয়ে বসেছে · বাতিল --}}
@if ($note->isCancelled())
    <x-ui.badge tone="danger">{{ __('core.status.cancelled') }}</x-ui.badge>
@elseif ($note->isConfirmed())
    <x-ui.badge tone="success">{{ __('core.status.confirmed') }}</x-ui.badge>
@else
    <x-ui.badge tone="pending">{{ __('core.status.draft') }}</x-ui.badge>
@endif
