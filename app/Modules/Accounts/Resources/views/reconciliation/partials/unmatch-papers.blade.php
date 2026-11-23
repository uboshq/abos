{{-- ⭐ মিল খোলা — সারির সব কাগজ একসাথে ছাড়া পায় (মালিক, ২১ নভেম্বর ২০২৬) --}}
@if ($recon->isDraft())
    @can('accounts.reconciliation.manage')
        <form method="POST" action="{{ route('accounts.reconciliation.unmatch_books', [$recon, $line->id]) }}" data-unmatch-papers="{{ $line->id }}">
            @csrf
            <x-ui.button type="submit">{{ __('accounts::recon.unmatch') }}</x-ui.button>
        </form>
    @endcan
@endif
