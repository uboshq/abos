{{--
    A5 · ডেলিভারি চালান · ২ · বেন্টো কার্ড (বিশ্বের নতুন)। একই নম্বরের নকশা বিল-ভাউচার-চালানে একই সাজের।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন partial-এ; এখানে কেবল সাজ ([[PaperLook]])।
--}}
@include('sales::print.partials.challan-look-a5', ['look' => ['accent' => '#0f766e', 'head' => 'split', 'table' => 'zebra', 'amount' => 'card', 'cards' => 'tint', 'tint' => '#ecfdf5', 'radius' => 3, 'title' => 'text', 'size' => 'a5']])
