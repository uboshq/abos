{{--
    থার্মাল ৮ · খুচরা বাক্স — দোকানের জনপ্রিয় ছক-রসিদ: বাক্সে মাথা, ঘরকাটা পণ্য তালিকা।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'boxed', 'rule' => 'solid', 'items' => 'grid', 'total' => 'box']])
