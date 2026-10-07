{{--
    থার্মাল ৮০মিমি · ২৫ · বিশ্ব-মানক (বাংলা) — A4 নকশার সাদা-কালো রোল-রূপ।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['lang' => 'bn', 'header' => 'left', 'rule' => 'solid', 'items' => 'lines', 'total' => 'box']])
