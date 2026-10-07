{{--
    থার্মাল ১১ · পুরো বাংলা — সব ঘর বাংলায়।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['lang' => 'bn', 'header' => 'center', 'rule' => 'double', 'items' => 'lines', 'total' => 'box']])
