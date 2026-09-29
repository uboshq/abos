{{--
    থার্মাল ২০ · সেরিফ — পত্রিকার মতো সেরিফ অক্ষর।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['font' => 'dejavuserif', 'header' => 'center', 'rule' => 'double', 'items' => 'lines', 'total' => 'double']])
