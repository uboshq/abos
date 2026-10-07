<a href="{{ route('purchase.bill.show', $bill) }}"
   class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ $bill->document_no }}</a>
@if (filled($bill->supplier_bill_no))
    <span class="block text-2xs text-(--color-ink-muted)">{{ $bill->supplier_bill_no }}</span>
@endif
