{{--
    থার্মাল ৯ · ব্যাংক স্লিপ — ব্যাংক-এটিএম স্লিপের ধাঁচ: বাঁয়ে মোটা দাগ, বাক্সে মোট।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['font' => 'dejavusansmono', 'header' => 'bar', 'rule' => 'solid', 'items' => 'dense', 'total' => 'box', 'size' => 7.5]])
