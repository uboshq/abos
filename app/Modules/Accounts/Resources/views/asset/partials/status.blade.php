{{-- ⓘ তিন অবস্থা — সইয়ের অপেক্ষা "বাতিল" নয় (গ১, ৪ অক্টোবর ২০২৬) --}}
<x-ui.badge :tone="$asset->isActive() ? 'success' : ($asset->isAwaiting() ? 'pending' : 'neutral')">
    {{ $asset->isActive() ? __('accounts::asset.active') : ($asset->isAwaiting() ? __('accounts::asset.awaiting') : __('accounts::asset.disposed')) }}
</x-ui.badge>
