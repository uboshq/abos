{{--
    থার্মাল ৫ · বড় বিল নম্বর — রেস্তোরাঁ-ক্যাফের নতুন ধাঁচ: বিল নম্বর সবচেয়ে বড় করে।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'big_no', 'rule' => 'double', 'total' => 'big', 'items' => 'lines']])
