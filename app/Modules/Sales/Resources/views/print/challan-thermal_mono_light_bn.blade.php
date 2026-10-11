{{--
    থার্মাল ৮০মিমি · চালান · মোনো ক্লাসিক হালকা (বাংলা) — A4-এর সাদা-কালো রোল-রূপ ([[PaperLook]])।
--}}
{{-- ⭐ বাংলা নকশা — অঙ্কও বাংলায় (মালিক, ১০ অক্টোবর ২০২৬; [[BanglaDigits::inText()]]) --}}
<?php ob_start(); ?>
@include('sales::print.partials.challan-look-thermal', ['look' => ['accent' => '#000000', 'ink' => '#000000', 'head' => 'left', 'table' => 'underline', 'amount' => 'line', 'cards' => 'plain', 'lang' => 'bn', 'size' => 'thermal']])
{!! \App\Core\Support\BanglaDigits::inText((string) ob_get_clean()) !!}
