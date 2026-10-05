{{--
    তিনটা অবস্থা, আর "অপেক্ষায়" সবচেয়ে গুরুত্বপূর্ণ: তখন টাকাটা এখনো
    দাতার হিসাবে, যদিও হাত থেকে বেরিয়ে গেছে বলে সে মনে করছে।
--}}
@if ($transfer->isAwaiting())
    {{-- ⓘ সইয়ের অপেক্ষায় — টাকা এখনো দাতার ড্রয়ারে, খাতায় কিছু বসেনি (অডিট ম৬) --}}
    <x-ui.badge tone="pending">{{ __('accounts::state.awaiting_signature') }}</x-ui.badge>
@elseif ($transfer->isPending())
    <x-ui.badge tone="warning">{{ __('accounts::state.awaiting_receipt') }}</x-ui.badge>
@elseif ($transfer->isConfirmed())
    <x-ui.badge tone="success">{{ __('accounts::state.received') }}</x-ui.badge>
@else
    <x-ui.badge tone="danger">{{ __('core.status.cancelled') }}</x-ui.badge>
@endif
