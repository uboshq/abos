{{--
    থার্মাল ৬ · সুপারমার্কেট — বিশ্বের সুপারশপের চেনা রসিদ: টাইপরাইটার অক্ষর, ড্যাশ দাগ, বড় হাতের লেখা।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['font' => 'dejavusansmono', 'header' => 'center', 'rule' => 'dashed', 'items' => 'pos', 'total' => 'double', 'upper' => true, 'size' => 7.5]])
