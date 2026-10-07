{{-- কাগজের নম্বরটাই লিংক — খরচের সারি থেকে আসল ভাউচারে যাওয়ার পথ। --}}
<a href="{{ route('accounts.voucher.show', $voucher) }}"
   class="num text-(--color-link) hover:underline">{{ $voucher->document_no }}</a>
