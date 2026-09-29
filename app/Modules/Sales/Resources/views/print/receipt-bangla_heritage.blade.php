{{--
    আদায়ের রসিদ · ১৬ · পুরো বাংলা (আমাদের বাছাই)। একই নম্বরের নকশা বিল-ভাউচার-চালানে একই সাজের।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন partial-এ; এখানে কেবল সাজ ([[PaperLook]])।
--}}
@include('sales::print.partials.receipt-look', ['look' => ['accent' => '#8b1e1e', 'lang' => 'bn', 'head' => 'center', 'table' => 'grid', 'amount' => 'line', 'cards' => 'box', 'tint' => '#fbf1f1']])
