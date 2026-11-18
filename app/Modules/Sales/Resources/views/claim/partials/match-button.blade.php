{{-- ⭐ প্রস্তাবিত জোড়া — এক চাপে মেলান ([[DepositMatchService::match()]]) --}}
<form method="POST" action="{{ route('sales.claim.match.store', $claim) }}" data-match-suggested>
    @csrf
    <input type="hidden" name="line" value="{{ $line->id }}">
    <x-ui.button type="submit" tone="primary">{{ __('sales::deposit.match') }}</x-ui.button>
</form>
