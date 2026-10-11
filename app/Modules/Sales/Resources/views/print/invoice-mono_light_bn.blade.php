{{--
    ২৭ · মোনো ক্লাসিক হালকা (বাংলা) — সব ঘর বাংলায়; কাঠামো [[invoice-mono-light]]-এ; ইংরেজি রূপ `mono_light`।
--}}
{{-- ⭐ বাংলা নকশা — অঙ্কও বাংলায় (মালিক, ১০ অক্টোবর ২০২৬; [[BanglaDigits::inText()]]) --}}
<?php ob_start(); ?>
@include('sales::print.partials.invoice-mono-light', ['lang' => 'bn'])
{!! \App\Core\Support\BanglaDigits::inText((string) ob_get_clean()) !!}
