{{--
    A5 · mono_light_bn (বাংলা) — কাঠামো [[challan-mono-light-a5]]-এ।
--}}
{{-- ⭐ বাংলা নকশা — অঙ্কও বাংলায় (মালিক, ১০ অক্টোবর ২০২৬; [[BanglaDigits::inText()]]) --}}
<?php ob_start(); ?>
@include('sales::print.partials.challan-mono-light-a5', ['lang' => 'bn'])
{!! \App\Core\Support\BanglaDigits::inText((string) ob_get_clean()) !!}
