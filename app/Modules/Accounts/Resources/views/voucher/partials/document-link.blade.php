{{-- খাতায় বসা একটা কাগজের নম্বর, তার নিজের পাতার লিংক — [[voucher/list]]-এর ক্রয় ও বিক্রয় ট্যাব।
     ⭐ পাশে গ্রাহক/সরবরাহকারী, থাকলে (মালিক, ১৯ অক্টোবর ২০২৬; [[SourceParties]]) --}}
<x-ui.party-cell :party="$party ?? null">
    <x-ui.drill :source="$source" :id="$id" />
</x-ui.party-cell>
