{{--
    থার্মাল ৪ · মোটা কালো ব্লক — মাথায় কালো পট্টি, নিচে কালো ব্লকে মোট — সাহসী নতুন ধাঁচ।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'band', 'rule' => 'solid', 'total' => 'band', 'items' => 'lines']])
