{{-- চলাচলের সংখ্যাটা তার ভাউচারে নামায় — নিয়ম ১।

     ── কেন খালি ঘরও থাকতে পারে ──────────────────────────────────────
     ভাউচারটা মুছে ফেলা হলে (বাতিল দাখিলা) জোড়াটা null হয়ে যায়।
     তখন লিংকটা না দেখিয়ে একটা ড্যাশ — ভাঙা লিংক দেখানোর চেয়ে ভালো,
     আর সারিটা তবু থাকে, কারণ ঘটনাটা সত্যিই ঘটেছিল। --}}
@if ($movement->voucher)
    <a href="{{ route('accounts.voucher.show', $movement->voucher) }}"
       class="num text-(--color-link) hover:underline">{{ $movement->voucher->document_no }}</a>
@else
    <span class="text-(--color-ink-muted)">—</span>
@endif
