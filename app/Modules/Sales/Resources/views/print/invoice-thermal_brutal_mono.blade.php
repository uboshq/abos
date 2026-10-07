{{--
    থার্মাল ৮০মিমি · ২৩ · নিও-ব্রুটাল — A4 নকশার সাদা-কালো রোল-রূপ।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
@include('sales::print.partials.invoice-thermal', ['style' => ['font' => 'dejavusansmono', 'header' => 'boxed', 'rule' => 'solid', 'items' => 'grid', 'total' => 'band', 'size' => 7.5]])
