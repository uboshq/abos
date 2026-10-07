{{--
    থার্মাল ১৬ · খতিয়ান ছক — খাতার মতো ঘরকাটা।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['font' => 'dejavusansmono', 'header' => 'boxed', 'rule' => 'solid', 'items' => 'grid', 'total' => 'double', 'size' => 7.5]])
