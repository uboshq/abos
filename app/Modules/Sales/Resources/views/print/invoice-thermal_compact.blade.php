{{--
    থার্মাল ১২ · ঘন তালিকা — পরিবেশকের লম্বা তালিকা কম কাগজে।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'left', 'rule' => 'dashed', 'items' => 'dense', 'total' => 'plain', 'size' => 7.5]])
