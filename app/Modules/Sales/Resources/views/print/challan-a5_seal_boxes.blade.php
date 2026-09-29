{{--
    A5 · ডেলিভারি চালান · ২০ · সিলমোহরের ঘর (আমাদের বাছাই)। একই নম্বরের নকশা বিল-ভাউচার-চালানে একই সাজের।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন partial-এ; এখানে কেবল সাজ ([[PaperLook]])।
--}}
@include('sales::print.partials.challan-look-a5', ['look' => ['accent' => '#0b5394', 'head' => 'left', 'table' => 'grid', 'amount' => 'box', 'cards' => 'box', 'seal' => true, 'tint' => '#eef4fa', 'size' => 'a5']])
