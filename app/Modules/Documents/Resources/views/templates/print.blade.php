@extends('print.layout')

{{--
    ছাঁচ থেকে কাগজ (§২; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬) — কোম্পানির মাথা লেআউটের, নিচে ভরা ছাঁচ।

    ⓘ `$html` আগেই নিরাপদ করা ([[DocumentTemplates::html()]]) — ছাঁচের লেখা আর ঘরের মান দুইটাই `e()`-র পরে,
    তারপর কেবল আমাদের চিহ্ন (শিরোনাম, মোটা, অনুচ্ছেদ, দাগ) HTML হয়েছে।
--}}

@section('body')
    <div data-template-body style="font-size: 11pt; line-height: 1.6;">
        {!! $html !!}
    </div>
@endsection
