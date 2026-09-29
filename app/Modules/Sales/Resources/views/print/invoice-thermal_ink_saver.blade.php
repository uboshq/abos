{{--
    থার্মাল ১৩ · কালি বাঁচানো — কোনো ভরাট নেই — রোলের মাথা বেশি দিন চলে।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'center', 'rule' => 'dashed', 'items' => 'lines', 'total' => 'plain', 'logo' => 'none']])
