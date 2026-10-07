{{--
    থার্মাল ৭ · পিওএস মানক — Epson-ধাঁচের চলতি পিওএস রসিদ: বাঁয়ে মাথা, দুই দাগে মোট।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['font' => 'dejavusansmono', 'header' => 'left', 'rule' => 'dashed', 'items' => 'pos', 'total' => 'double', 'size' => 7.5]])
