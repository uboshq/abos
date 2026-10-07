{{--
    আদায়ের রসিদ · ১৪ · সুইস (আধুনিক)। একই নম্বরের নকশা বিল-ভাউচার-চালানে একই সাজের।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন partial-এ; এখানে কেবল সাজ ([[PaperLook]])।
--}}
@include('sales::print.partials.receipt-look', ['look' => ['accent' => '#d62828', 'head' => 'minimal', 'table' => 'underline', 'amount' => 'big', 'cards' => 'line']])
