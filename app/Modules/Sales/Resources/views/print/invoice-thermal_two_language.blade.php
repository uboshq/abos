{{--
    থার্মাল ১০ · দুই ভাষা — বিশ্বজুড়ে চালু দুই ভাষার রসিদ: ইংরেজি / বাংলা পাশাপাশি।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['lang' => 'both', 'header' => 'center', 'rule' => 'dashed', 'items' => 'lines', 'total' => 'box', 'size' => 7.5]])
