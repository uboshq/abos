{{--
    A5 · আদায়ের রসিদ · ১২ · কর্পোরেট নীল (আধুনিক)। একই নম্বরের নকশা বিল-ভাউচার-চালানে একই সাজের।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন partial-এ; এখানে কেবল সাজ ([[PaperLook]])।
--}}
@include('sales::print.partials.receipt-look-a5', ['look' => ['accent' => '#1f3a68', 'head' => 'band', 'table' => 'underline', 'amount' => 'box', 'cards' => 'line', 'size' => 'a5']])
