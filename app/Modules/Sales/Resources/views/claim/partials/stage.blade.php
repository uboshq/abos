{{-- ⭐ পাঁচ ধাপ ([[DepositClaim::stage()]]) — অপেক্ষা হলুদ, মিল নীল, খাতায় সবুজ, ফেরত লাল, সইয়ের অপেক্ষা ধূসর --}}
<x-ui.badge :tone="match ($claim->stage()) {
    'confirmed' => 'success',
    'rejected' => 'danger',
    'matched' => 'info',
    'awaiting' => 'draft',
    default => 'pending',
}" data-claim-stage="{{ $claim->stage() }}">{{ $claim->stageLabel() }}</x-ui.badge>
