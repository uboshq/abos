{{--
    বিশ্ব-মানক (বাংলা) — সারা বিশ্বে সবচেয়ে চালু বিলের গড়ন, সব ঘর বাংলায়; কাঠামো [[invoice-world]]-এ, ইংরেজি রূপ `world_standard`।
--}}
{{-- ⭐ বাংলা নকশা — অঙ্কও বাংলায় (মালিক, ১০ অক্টোবর ২০২৬; [[BanglaDigits::inText()]]) --}}
<?php ob_start(); ?>
@include('sales::print.partials.invoice-world', ['lang' => 'bn'])
{!! \App\Core\Support\BanglaDigits::inText((string) ob_get_clean()) !!}
