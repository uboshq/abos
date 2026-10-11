{{--
    বিক্রয় আদেশ · ১৬ · পুরো বাংলা (আমাদের বাছাই)। একই নম্বরের নকশা বিল-ভাউচার-চালানে একই সাজের।
    ⓘ কাঠামো, সুইচ আর `data-*` চিহ্ন partial-এ; এখানে কেবল সাজ ([[PaperLook]])।
--}}
{{-- ⭐ বাংলা নকশা — অঙ্কও বাংলায় (মালিক, ১০ অক্টোবর ২০২৬; [[BanglaDigits::inText()]]) --}}
<?php ob_start(); ?>
@include('sales::print.partials.order-look', ['look' => ['accent' => '#8b1e1e', 'lang' => 'bn', 'head' => 'center', 'table' => 'grid', 'amount' => 'line', 'cards' => 'box', 'tint' => '#fbf1f1']])
{!! \App\Core\Support\BanglaDigits::inText((string) ob_get_clean()) !!}
