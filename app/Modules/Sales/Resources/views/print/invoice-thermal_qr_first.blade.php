{{--
    থার্মাল ২ · QR আগে — কাগজ-কম রসিদের ধাঁচ: সবার উপরে QR, স্ক্যানেই হিসাব।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'center', 'rule' => 'dashed', 'qr_top' => true, 'items' => 'dense', 'total' => 'box']])
