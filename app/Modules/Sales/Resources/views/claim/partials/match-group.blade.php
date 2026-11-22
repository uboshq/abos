{{-- ⭐ দলের প্রস্তাব — এক ব্যাংক-সারি, কয়েকটা খবর, এক চাপে (মালিক, ২১ নভেম্বর ২০২৬; [[DepositMatchService::matchMany()]]) --}}
<form method="POST" action="{{ route('sales.claim.match.many', $group['line']->id) }}" data-match-group="{{ $group['line']->id }}">
    @csrf
    @foreach ($group['claims'] as $claim)
        <input type="hidden" name="claims[]" value="{{ $claim->id }}">
    @endforeach
    <x-ui.button type="submit" tone="primary">{{ __('sales::deposit.match_all') }}</x-ui.button>
</form>
