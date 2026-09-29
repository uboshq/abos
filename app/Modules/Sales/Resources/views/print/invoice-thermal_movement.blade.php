{{--
    থার্মাল ১৮ · আধুনিক বিবরণী — হিসাবের সারাংশ আর জমার চলাচল।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'bar', 'rule' => 'solid', 'items' => 'dense', 'total' => 'band', 'account' => true, 'movement' => true]])
