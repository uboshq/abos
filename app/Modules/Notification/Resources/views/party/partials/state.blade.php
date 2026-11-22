{{-- ⓘ অবস্থার পিল, আর ব্যর্থ হলে নিচে লাল কারণ ("এই নম্বরে WhatsApp নেই → SMS-এ গেছে") --}}
<x-ui.badge :tone="$state['tone']" data-party-state="{{ $message->status }}">{{ $state['text'] }}</x-ui.badge>
@if ($state['why'] !== null)
    <span class="mt-0.5 block text-2xs text-(--color-badge-danger-ink)" data-party-why>{{ $state['why'] }}</span>
@endif
