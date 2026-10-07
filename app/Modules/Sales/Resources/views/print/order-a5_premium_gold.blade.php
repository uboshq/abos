{{--
    A5 · বিক্রয় আদেশ · ১৭ · প্রিমিয়াম সোনালি (আমাদের বাছাই)। একই নম্বরের নকশা বিল-ভাউচার-চালানে একই সাজের।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন partial-এ; এখানে কেবল সাজ ([[PaperLook]])।
--}}
@include('sales::print.partials.order-look-a5', ['look' => ['accent' => '#9a7b2f', 'font' => 'serif', 'head' => 'center', 'table' => 'underline', 'amount' => 'line', 'cards' => 'line', 'title' => 'text', 'size' => 'a5']])
