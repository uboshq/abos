{{--
    থার্মাল ২১ · সুইস — বাঁয়ে মোটা দাগ, পরিষ্কার ছক।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'bar', 'rule' => 'solid', 'items' => 'lines', 'total' => 'big']])
