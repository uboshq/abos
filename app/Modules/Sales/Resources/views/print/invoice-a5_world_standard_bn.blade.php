{{--
    A5 · world_standard_bn (বাংলা) — কাঠামো [[invoice-world-a5]]-এ।
--}}
{{-- ⭐ বাংলা নকশা — অঙ্কও বাংলায় (মালিক, ১০ অক্টোবর ২০২৬; [[BanglaDigits::inText()]]) --}}
<?php ob_start(); ?>
@include('sales::print.partials.invoice-world-a5', ['lang' => 'bn'])
{!! \App\Core\Support\BanglaDigits::inText((string) ob_get_clean()) !!}
