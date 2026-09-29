{{--
    A5 · আদায়ের রসিদ · ৮ · ট্যালি ক্লাসিক (বিশ্বের জনপ্রিয়)। একই নম্বরের নকশা বিল-ভাউচার-চালানে একই সাজের।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন partial-এ; এখানে কেবল সাজ ([[PaperLook]])।
--}}
@include('sales::print.partials.receipt-look-a5', ['look' => ['accent' => '#000000', 'ink' => '#000000', 'head' => 'form', 'table' => 'grid', 'amount' => 'line', 'cards' => 'box', 'tint' => '#f2f2f2', 'size' => 'a5']])
