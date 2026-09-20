<a href="{{ route('finance.deposit.show', ['issuer' => $deposit->kind?->issuer ?? 'bank', 'deposit' => $deposit->id]) }}"
   class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ $deposit->document_no }}</a>
