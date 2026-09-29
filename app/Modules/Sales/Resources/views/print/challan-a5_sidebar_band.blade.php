{{--
    A5 · ডেলিভারি চালান · ১৫ · পাশের পট্টি (আধুনিক)। একই নম্বরের নকশা বিল-ভাউচার-চালানে একই সাজের।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন partial-এ; এখানে কেবল সাজ ([[PaperLook]])।
--}}
@include('sales::print.partials.challan-look-a5', ['look' => ['accent' => '#c2410c', 'head' => 'sidebar', 'table' => 'zebra', 'amount' => 'box', 'cards' => 'tint', 'tint' => '#fff7ed', 'title' => 'pill', 'size' => 'a5']])
