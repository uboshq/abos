{{--
    থার্মাল ৮০মিমি · ২৪ · বিশ্ব-মানক (ইংরেজি) — A4 নকশার সাদা-কালো রোল-রূপ।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['header' => 'left', 'rule' => 'solid', 'items' => 'lines', 'total' => 'box']])
