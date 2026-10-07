{{--
    থার্মাল ১৭ · বিল + হিসাব — বিলের নিচে গ্রাহকের হিসাবের সারাংশ।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'left', 'rule' => 'solid', 'items' => 'lines', 'total' => 'box', 'account' => true]])
