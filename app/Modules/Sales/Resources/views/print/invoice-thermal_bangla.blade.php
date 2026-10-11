{{--
    থার্মাল ১১ · পুরো বাংলা — সব ঘর বাংলায়।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন একটাই থার্মাল partial-এ; এখানে কেবল সাজ।
--}}
{{-- ⭐ বাংলা নকশা — অঙ্কও বাংলায় (মালিক, ১০ অক্টোবর ২০২৬; [[BanglaDigits::inText()]]) --}}
<?php ob_start(); ?>
@include('sales::print.partials.invoice-thermal', ['style' => ['lang' => 'bn', 'header' => 'center', 'rule' => 'double', 'items' => 'lines', 'total' => 'box']])
{!! \App\Core\Support\BanglaDigits::inText((string) ob_get_clean()) !!}
