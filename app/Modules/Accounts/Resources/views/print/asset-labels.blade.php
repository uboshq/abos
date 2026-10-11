{{--
    ⭐ সম্পদের লেবেল — ট্যাগ নম্বর দাগে আর লেখায় (স্থায়ী সম্পদ ধাপ ৪)।

    ⓘ পণ্যের লেবেলের ([[print/labels]]) একই জাল: সমান মাপের ঘর, মাথা নেই, কেটে জিনিসের গায়ে সাঁটা। ⛔ QR নয় — মালিক বাদ
    দিয়েছেন। দাগ Code 128, যা কেবল ASCII বইতে পারে; ট্যাগে অন্য অক্ষর থাকলে দাগ বাদ, লেখাটা থাকে।
--}}
@php
    use App\Core\Support\Barcode;

    $thermal = $paper->isThermal;
    $columns = $thermal ? 1 : 3;
@endphp

<style @nonce>
    * { box-sizing: border-box; }
    body { font-family: hindsiliguri, sans-serif; color: #000; font-size: {{ $thermal ? 8 : 9 }}pt; }
    table.sheet { width: 100%; border-collapse: collapse; }
    td.label {
        width: {{ round(100 / $columns, 4) }}%;
        padding: {{ $thermal ? 1 : 2 }}mm;
        text-align: center;
        vertical-align: top;
        border: 0.2mm dashed #999;
    }
    .owner { font-size: {{ $thermal ? 6 : 7 }}pt; }
    .name { font-weight: bold; line-height: 1.2; height: {{ $thermal ? 8 : 9 }}mm; overflow: hidden; }
    .code { font-size: {{ $thermal ? 7 : 8.5 }}pt; letter-spacing: 0.3mm; margin-top: 0.8mm; font-weight: bold; }
    .bars { margin-top: 1mm; line-height: 0; }
</style>

<table class="sheet">
    @foreach ($labels->chunk($columns) as $row)
        <tr>
            @foreach ($row as $label)
                <td class="label">
                    <div class="owner">{{ $company?->name() }} · {{ $label['branch'] }}</div>
                    <div class="name">{{ $label['name'] }}</div>
                    @if (preg_match('/^[\x20-\x7E]+$/', $label['payload']))
                        <div class="bars">{!! Barcode::html($label['payload'], $thermal ? 0.3 : 0.33, $thermal ? 10 : 12) !!}</div>
                    @endif
                    <div class="code">{{ $label['payload'] }}</div>
                </td>
            @endforeach
            @for ($blank = $row->count(); $blank < $columns; $blank++)
                <td class="label"></td>
            @endfor
        </tr>
    @endforeach
</table>
