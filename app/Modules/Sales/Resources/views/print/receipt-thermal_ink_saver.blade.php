{{--
    থার্মাল ৮০মিমি · আদায়ের রসিদ · ১৯ · কালি বাঁচানো (আমাদের বাছাই)। একই নম্বরের নকশা বিল-ভাউচার-চালানে একই সাজের।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন partial-এ; এখানে কেবল সাজ ([[PaperLook]])।
--}}
@include('sales::print.partials.receipt-look-thermal', ['look' => ['accent' => '#000000', 'ink' => '#000000', 'head' => 'left', 'table' => 'rows', 'amount' => 'line', 'cards' => 'plain', 'title' => 'text', 'size' => 'thermal']])
