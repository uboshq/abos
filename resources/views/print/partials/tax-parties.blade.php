{{--
    ⭐ কর-কাগজের দুই পক্ষ — দাতা (আমাদের কোম্পানি) আর গ্রহীতা: নাম, ঠিকানা, BIN (মূসক আইন ২০১২ ধারা ৫২(১), বিধি ৪০)।
    ⛔ কোম্পানির BIN খালি হলে ছাপা থামে না — BIN-এর জায়গায় সতর্কবার্তা (`$warnBin`; কর-ফর্মে চালু)।

    দেয়: $company (লেআউটের), $companyBin, $party (name, code, address, bin), $issuerLabel, $recipientLabel, $thermal, $warnBin
--}}
@php
    $binText = fn (string $bin) => $bin !== '' ? $bin : '—';
@endphp

@if ($thermal)
    <table class="meta" data-tax-parties>
        <tr><td class="label" style="width: 18mm">{{ $issuerLabel }}</td><td>{{ $company->name() }}</td></tr>
        <tr>
            <td class="label">BIN</td>
            <td data-company-bin>
                @if ($companyBin !== ''){{ $companyBin }}@elseif ($warnBin)<b>⚠ {{ __('accounts::note.bin_missing') }}</b>@else — @endif
            </td>
        </tr>
        <tr><td class="label">{{ $recipientLabel }}</td><td>{{ $party['name'] }}</td></tr>
        @if (($party['address'] ?? '') !== '')
            <tr><td class="label">{{ __('accounts::print.note_address') }}</td><td>{{ $party['address'] }}</td></tr>
        @endif
        <tr><td class="label">BIN</td><td data-party-bin>{{ $binText((string) ($party['bin'] ?? '')) }}</td></tr>
    </table>
@else
    <table style="width: 100%; border-collapse: separate; border-spacing: 1.5mm 0; margin-bottom: 3mm; font-size: 9pt;" data-tax-parties>
        <tr>
            <td style="width: 50%; border: 0.3mm solid #777; padding: 2mm 2.5mm; vertical-align: top;">
                <div style="font-weight: bold; margin-bottom: 0.8mm;">{{ $issuerLabel }}</div>
                <div>{{ $company->name() }}</div>
                @if ($company->address())
                    <div>{{ $company->address() }}</div>
                @endif
                <div data-company-bin>
                    BIN:
                    @if ($companyBin !== '')
                        {{ $companyBin }}
                    @elseif ($warnBin)
                        <b style="border: 0.3mm solid #000; padding: 0 1mm;">⚠ {{ __('accounts::note.bin_missing') }}</b>
                    @else
                        —
                    @endif
                </div>
            </td>
            <td style="width: 50%; border: 0.3mm solid #777; padding: 2mm 2.5mm; vertical-align: top;">
                <div style="font-weight: bold; margin-bottom: 0.8mm;">{{ $recipientLabel }}</div>
                <div data-note-party>
                    {{ $party['name'] }}
                    @if (($party['code'] ?? '') !== '')
                        <span style="color: #333;">({{ $party['code'] }})</span>
                    @endif
                </div>
                @if (($party['address'] ?? '') !== '')
                    <div>{{ $party['address'] }}</div>
                @endif
                <div data-party-bin>BIN: {{ $binText((string) ($party['bin'] ?? '')) }}</div>
            </td>
        </tr>
    </table>
@endif
