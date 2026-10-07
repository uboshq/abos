{{--
    থার্মাল ১৫ · আগে হিসাব — উপরেই মোট-জমা-বকেয়া।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'left', 'rule' => 'solid', 'items' => 'lines', 'total' => 'plain', 'summary_first' => true]])
