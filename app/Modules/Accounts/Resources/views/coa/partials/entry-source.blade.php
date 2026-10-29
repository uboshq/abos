{{-- লেজারের একটা সারি কোন ডকুমেন্ট থেকে এল — নিয়ম ১
     ⭐ পাশে পক্ষ, থাকলে (মালিক, ১৯ অক্টোবর ২০২৬) — নাম আনে [[AccountLedger::page()]], পাতার একবারে --}}
<x-ui.party-cell :party="$party ?? null">
    <x-ui.drill :entry="$entry">{{ $entry->document_no }}</x-ui.drill>
</x-ui.party-cell>
