{{--
    একটা ছাপার ডকুমেন্টের দেহ — মাথা, ঘর, লাইন, মোট, স্বাক্ষর।

    ── কেন এটা আলাদা ফাইলে ──────────────────────────────────────────
    বেতনশিট একসাথে অনেকগুলো ছাপতে হয় — বিশ জন কর্মীর বিশটা, প্রতিটা
    নিজের পাতায়। document.blade.php একটা ডকুমেন্ট চেনে, আর সেটাই ঠিক;
    তাই দেহটা এখানে সরানো হল, আর যার একগুচ্ছ লাগে সে পাতা ভাগ করে
    এটাকেই বারবার ডাকে।

    কোরে "গুচ্ছ" বলে কোনো ধারণা যোগ করা হয়নি: গুচ্ছের দাবিটা একটামাত্র
    জায়গা থেকে এসেছে, আর একজনের দাবিতে শেয়ার্ড বিমূর্ততা বানানো নিষেধ।
--}}
    @php
        $thermal = $paper->isThermal;
        $columns = $paper->maxColumns();

        // ৫৮mm-এ তিনটা কলাম, ৮০mm-এ চারটা, A4-তে সবগুলো
        $showUnit = $columns >= 8;
        $showRate = $doc->showMoney && $columns >= 4;
        $showAmount = $doc->showMoney;
    @endphp

    @if ($doc->notice)
        {{-- খসড়ার সতর্কবার্তা — কাগজটা দেখেই বোঝা যেতে হবে এটা চূড়ান্ত নয়,
             নাহলে কেউ খসড়া বিল নিয়ে টাকা চাইতে চলে যেতেন --}}
        <div style="text-align: center; font-weight: bold; border: 0.4mm solid #000;
                    padding: {{ $thermal ? '1mm' : '2mm' }}; margin-bottom: {{ $thermal ? 2 : 4 }}mm;
                    font-size: {{ $thermal ? 8 : 11 }}pt;">
            {{ $doc->notice }}
        </div>
    @endif

    <table class="meta">
        @php
            $metaRows = collect($doc->meta)->filter(fn ($value) => filled($value));
            // A4-তে দুই জোড়া এক সারিতে, থার্মালে এক জোড়া — সরু কাগজে
            // দুই জোড়া দিলে মান দুই লাইনে ভেঙে যায়
            $chunks = $metaRows->chunk($thermal ? 1 : 2);
        @endphp

        @foreach ($chunks as $chunk)
            <tr>
                @foreach ($chunk as $label => $value)
                    <td class="label" style="width: {{ $thermal ? '24mm' : '22mm' }}">{{ __($label) }}</td>
                    <td>{{ $value }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>

    @if ($doc->lines !== [])
        <table class="lines">
            <thead>
                <tr>
                    <th style="width: {{ $thermal ? '6mm' : '10mm' }}">#</th>
                    <th>{{ __('core.print.item') }}</th>
                    @if ($showUnit)
                        <th style="width: 16mm">{{ __('core.print.unit') }}</th>
                    @endif
                    <th class="num" style="width: {{ $thermal ? '13mm' : '20mm' }}">{{ __('core.print.qty') }}</th>
                    @if ($showRate)
                        <th class="num" style="width: {{ $thermal ? '15mm' : '24mm' }}">{{ __('core.print.rate') }}</th>
                    @endif
                    @if ($showAmount)
                        <th class="num" style="width: {{ $thermal ? '18mm' : '28mm' }}">{{ __('core.print.amount') }}</th>
                    @endif
                </tr>
            </thead>

            <tbody>
                @foreach ($doc->lines as $index => $line)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $line['name'] }}</td>
                        @if ($showUnit)
                            <td>{{ $line['unit'] }}</td>
                        @endif
                        <td class="num">{{ $line['qty'] }}</td>
                        @if ($showRate)
                            <td class="num">{{ $line['rate'] }}</td>
                        @endif
                        @if ($showAmount)
                            <td class="num">{{ $line['amount'] }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($doc->showMoney && $doc->totals !== [])
        <table class="totals">
            @foreach ($doc->totals as $label => $value)
                <tr @if ($loop->last) class="grand" @endif>
                    <td>{{ __($label) }}</td>
                    <td class="num" style="width: {{ $thermal ? '20mm' : '32mm' }}">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($doc->showMoney && $doc->amountInWords)
        <div class="words">
            <strong>{{ __('core.print.in_words') }}:</strong> {{ $doc->amountInWords }}
        </div>
    @endif

    @if ($doc->narration)
        <div class="words">
            <strong>{{ __('core.table.narration') }}:</strong> {{ $doc->narration }}
        </div>
    @endif

    @if ($doc->signatures !== [])
        <table class="signatures">
            <tr>
                @php
                    // সরু কাগজে একটাই স্বাক্ষরের ঘর — তিনটা পাশাপাশি দিলে
                    // প্রতিটার প্রস্থে নাম লেখাই যায় না
                    $lines = $thermal ? array_slice($doc->signatures, -1) : $doc->signatures;
                    $width = (int) round(100 / max(count($lines), 1));
                @endphp

                @foreach ($lines as $label)
                    <td style="width: {{ $width }}%">
                        <div class="sig-line">{{ __($label) }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif
