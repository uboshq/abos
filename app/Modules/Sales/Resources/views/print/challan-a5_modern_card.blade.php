{{--
    A5 · ডেলিভারি চালান · ১৩ · আধুনিক কার্ড (আধুনিক)। একই নম্বরের নকশা বিল-ভাউচার-চালানে একই সাজের।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন partial-এ; এখানে কেবল সাজ ([[PaperLook]])।
--}}
@include('sales::print.partials.challan-look-a5', ['look' => ['accent' => '#7c3aed', 'head' => 'split', 'table' => 'clean', 'amount' => 'card', 'cards' => 'tint', 'tint' => '#f5f3ff', 'radius' => 3, 'size' => 'a5']])
