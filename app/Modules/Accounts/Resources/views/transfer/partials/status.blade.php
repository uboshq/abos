{{--
    তিনটা অবস্থা, আর "অপেক্ষায়" সবচেয়ে গুরুত্বপূর্ণ: তখন টাকাটা এখনো
    দাতার হিসাবে, যদিও হাত থেকে বেরিয়ে গেছে বলে সে মনে করছে।
--}}
@if ($transfer->isPending())
    <x-ui.badge tone="warning">{{ __('accounts::state.awaiting_receipt') }}</x-ui.badge>
@elseif ($transfer->isConfirmed())
    <x-ui.badge tone="success">{{ __('accounts::state.received') }}</x-ui.badge>
@else
    <x-ui.badge tone="danger">{{ __('core.status.cancelled') }}</x-ui.badge>
@endif
