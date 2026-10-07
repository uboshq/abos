{{-- হিসাবের নামটাই কাজের পাতায় যাওয়ার লিংক — নিয়ম ১। --}}
<a href="{{ route('accounts.reconciliation.show', $recon) }}"
   class="text-(--color-brand-500) hover:underline">{{ $recon->bankAccount?->label() }}</a>
