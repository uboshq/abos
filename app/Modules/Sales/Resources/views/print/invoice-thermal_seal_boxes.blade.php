{{--
    থার্মাল ১৯ · সিলমোহরের ঘর — সইয়ের জায়গায় সিল বসানোর বাক্স।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'boxed', 'rule' => 'dashed', 'items' => 'lines', 'total' => 'box', 'seal' => true]])
