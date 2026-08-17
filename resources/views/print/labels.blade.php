{{--
    পণ্যের লেবেল — নিজের ছাপা।

    ── কেন এই কাগজটার নিজের লেআউট, `print.layout` নয় ────────────────────
    বাকি প্রতিটা কাগজ একটা ডকুমেন্ট: মাথায় প্রতিষ্ঠানের নাম, নিচে সই।
    লেবেলের শীটে ওসব কিছুই থাকে না — গোটা কাগজটাই সমান মাপের ঘরের একটা
    জাল, আর প্রতিটা ঘর কেটে আলাদা করে পণ্যের গায়ে সাঁটা হয়। মাথা বসালে
    প্রথম সারিটা নিচে নেমে যেত, আর প্রি-কাট স্টিকার শীটের সাথে জালটা আর
    মিলত না।

    ── কেন দাম ঐচ্ছিক ──────────────────────────────────────────────────
    ডিলারের গুদামের লেবেলে দাম থাকা ভালো নয় — ওখানে দাম গ্রাহকভেদে
    আলাদা। দোকানের তাকের লেবেলে উল্টো, দামটাই মূল জিনিস।
--}}
@php
    use App\Core\Support\Barcode;
    use App\Core\Support\Money;

    $thermal = $paper->isThermal;

    // থার্মাল রোলে এক সারিতে একটাই লেবেল; A4-তে তিনটা পাশাপাশি
    $columns = $thermal ? 1 : 3;
@endphp

<style>
    * { box-sizing: border-box; }

    body {
        font-family: hindsiliguri, sans-serif;
        color: #000;
        font-size: {{ $thermal ? 8 : 9 }}pt;
    }

    /*
        জালটা টেবিল দিয়ে, grid বা flex দিয়ে নয়।

        mPDF-এ flex নেই আর grid আংশিক — ভাঙলে লেবেলগুলো একটার নিচে
        একটা লম্বা হয়ে বেরোত, আর গোটা শীটটা নষ্ট হত।
    */
    table.sheet { width: 100%; border-collapse: collapse; }

    td.label {
        width: {{ round(100 / $columns, 4) }}%;
        padding: {{ $thermal ? 1 : 2 }}mm;
        text-align: center;
        vertical-align: top;
        border: 0.2mm dashed #999;
    }

    .name {
        font-weight: bold;
        font-size: {{ $thermal ? 8 : 9 }}pt;
        line-height: 1.2;
        height: {{ $thermal ? 8 : 9 }}mm;
        overflow: hidden;
    }

    .code { font-size: {{ $thermal ? 6.5 : 7.5 }}pt; letter-spacing: 0.3mm; margin-top: 0.8mm; }
    .price { font-weight: bold; font-size: {{ $thermal ? 9 : 11 }}pt; margin-top: 0.5mm; }
    .bars { margin-top: 1mm; line-height: 0; }
</style>

<table class="sheet">
    @foreach ($labels->chunk($columns) as $row)
        <tr>
            @foreach ($row as $label)
                <td class="label">
                    <div class="name">{{ $label['name'] }}</div>

                    <div class="bars">{!! Barcode::html($label['payload'], $thermal ? 0.3 : 0.33, $thermal ? 10 : 12) !!}</div>

                    {{-- দাগের নিচে লেখাটাও থাকে: স্ক্যানার না পড়লে
                         মানুষ হাতে টাইপ করতে পারেন, আর ওটাই একমাত্র
                         উপায় যদি লেবেলটা ভাঁজ পড়ে বা ঘষা লাগে। --}}
                    <div class="code">{{ $label['payload'] }}</div>

                    @if ($label['price'] !== null)
                        <div class="price">{{ Money::format($label['price']) }}</div>
                    @endif
                </td>
            @endforeach

            {{-- শেষ সারিটা অসম্পূর্ণ হলে খালি ঘর — নাহলে টেবিলের শেষ
                 ঘরটা চওড়া হয়ে যেত আর স্টিকারের জালের সাথে মিলত না। --}}
            @for ($blank = $row->count(); $blank < $columns; $blank++)
                <td class="label"></td>
            @endfor
        </tr>
    @endforeach
</table>
