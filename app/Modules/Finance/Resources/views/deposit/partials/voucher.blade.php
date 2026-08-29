{{-- চলাচলের সংখ্যাটা তার ভাউচারে নামায় — নিয়ম ১।

     ── কেন খালি ঘরও থাকতে পারে ──────────────────────────────────────
     ভাউচারটা মুছে ফেলা হলে (বাতিল দাখিলা) জোড়াটা null হয়ে যায়।
     তখন লিংকটা না দেখিয়ে একটা ড্যাশ — ভাঙা লিংক দেখানোর চেয়ে ভালো,
     আর সারিটা তবু থাকে, কারণ ঘটনাটা সত্যিই ঘটেছিল। --}}
@if ($movement->voucher)
    {{-- বাতিল ভাউচারের নম্বরটা কাটা — জমাটা ফিরিয়ে নেওয়ার পরেও সারিটা
         থাকে (নিয়ম ৫), আর দাগ না দিলে দেখে মনে হত দাখিলাটা এখনো
         খাতায় বসে আছে। --}}
    <a href="{{ route('accounts.voucher.show', $movement->voucher) }}"
       @class(['num text-(--color-link) hover:underline',
               'line-through text-(--color-ink-muted)' => $movement->voucher->isCancelled()])
    >{{ $movement->voucher->document_no }}</a>
@else
    <span class="text-(--color-ink-muted)">—</span>
@endif
