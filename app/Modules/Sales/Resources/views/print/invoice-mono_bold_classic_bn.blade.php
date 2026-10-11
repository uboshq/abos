{{--
    ২৬ক · মোনো সাহসী ক্লাসিক (বাংলা) — সব ঘর বাংলায়; কাঠামো [[invoice-mono-bold-classic]]-এ; ইংরেজি রূপ `mono_bold_classic`।
    মালিক, ৪ অক্টোবর ২০২৬: "eita banglateo zate kora zay"।
--}}
{{-- ⭐ বাংলা নকশা — অঙ্কও বাংলায় (মালিক, ১০ অক্টোবর ২০২৬; [[BanglaDigits::inText()]]) --}}
<?php ob_start(); ?>
@include('sales::print.partials.invoice-mono-bold-classic', ['lang' => 'bn'])
{!! \App\Core\Support\BanglaDigits::inText((string) ob_get_clean()) !!}
