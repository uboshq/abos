{{--
    স্ক্যান করা পাতা থেকে PDF (§৭; পঞ্চম ধাপ, ৯ অক্টোবর ২০২৬) — ABOS-এর ছাপার যন্ত্রে ([[DocumentScan]])।

    ⓘ প্রতি পাতায় একটা ছবি, পাতা জুড়ে; কোম্পানির মাথা নেই — এটা কাগজের নকল, আমাদের ছাপা কাগজ নয়।
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style @nonce>
        body { margin: 0; }
        .page { text-align: center; }
        .page img { max-width: 100%; max-height: 270mm; }
    </style>
</head>
<body>
    @foreach ($images as $i => $image)
        <div class="page" @if ($i > 0) style="page-break-before: always;" @endif>
            <img src="{{ $image }}" alt="">
        </div>
    @endforeach
</body>
</html>
