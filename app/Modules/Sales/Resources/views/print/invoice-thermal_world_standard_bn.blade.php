{{--
    থার্মাল ৮০মিমি · ২৫ · বিশ্ব-মানক (বাংলা) — A4 নকশার সাদা-কালো রোল-রূপ।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
{{-- ⭐ বাংলা নকশা — অঙ্কও বাংলায় (মালিক, ১০ অক্টোবর ২০২৬; [[BanglaDigits::inText()]]) --}}
<?php ob_start(); ?>
@include('sales::print.partials.invoice-thermal', ['style' => ['lang' => 'bn', 'header' => 'left', 'rule' => 'solid', 'items' => 'lines', 'total' => 'box']])
{!! \App\Core\Support\BanglaDigits::inText((string) ob_get_clean()) !!}
