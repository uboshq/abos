{{--
    থার্মাল ১৪ · কেটে রাখার অংশ — নিচে কেটে রাখার ছোট অংশ, প্রাপকের সই।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'center', 'rule' => 'dashed', 'items' => 'lines', 'total' => 'box', 'stub' => true]])
