@extends('print.layout')

{{--
    ডেবিট/ক্রেডিট নোট — মালিক, ৩ অক্টোবর ২০২৬: নোটের কোনো ছাপা ছিল না ([[NotePrintController]])।

    ⓘ কোম্পানির মাথা লেআউটের; এখানে নোটের দিক (ডেবিট বা ক্রেডিট), নম্বর, তারিখ, পক্ষ, কারণ, বিবরণ, টাকা আর কথায়,
    বিপরীতের কাগজ, সই আর নিচের লেখা। থার্মালে একটা সই-ঘর (পক্ষের), বাকি মাপে তিনটা।

    ⭐ নকশা v5 (২৩ নভেম্বর ২০২৬): সারিওয়ালা নোট —
      ক্রেডিট নোট = মূসক-৬.৭, গ্রাহকের ডেবিট নোট = মূসক-৬.৮ (ধারা ৫২(১), বিধি ৪০): দুই পক্ষের নাম-ঠিকানা-BIN, নোটের নম্বর,
      তারিখ ও সময়, মূল কর চালানপত্রের (মূসক-৬.৩) নম্বর আর তারিখ ও সময়, কারণ, দামের সমন্বয় আর মাল ফেরতের সারি আলাদা,
      মোট + ফেরত = সর্বমোট, কথায়, সই।
      সরবরাহকারীর ডেবিট নোট — সাধারণ "ডেবিট নোট", সরবরাহকারীর মূসক-৬.৭ নম্বর-তারিখ থাকলে।
    ⓘ সারি নেই এমন পুরনো নোট আগের মতোই — এক অঙ্কের বাক্স।
--}}

@section('body')
    @php($thermal = $paper->isThermal)
    @php($taxed = ($note['has_lines'] ?? false) === true)

    <div style="text-align: center; font-weight: bold; font-size: {{ $thermal ? 11 : 14 }}pt; margin-bottom: {{ $taxed ? 0 : 2 }}mm;" data-note-direction>
        {{ $note['direction'] }}
    </div>

    @if ($taxed && $note['mushak'] !== '')
        {{-- ⓘ ফর্মের নম্বর আর বিধি — ফর্মের নিজের মাথার লেখা --}}
        <div style="text-align: center; font-size: {{ $thermal ? 7.5 : 9 }}pt; margin-bottom: 2mm;" data-note-mushak>
            {{ __('accounts::note_print.mushak_'.str_replace('.', '_', $note['mushak'])) }}
        </div>
    @elseif ($taxed)
        <div style="margin-bottom: 2mm;"></div>
    @endif

    @if (! empty($notice))
        {{-- ⓘ বাতিল বা আগে ছাপা — কাগজটা যেন আসল প্রথম কপি হিসেবে না চলে --}}
        <div style="text-align: center; font-weight: bold; border: 0.4mm solid #000;
                    padding: {{ $thermal ? '1mm' : '2mm' }}; margin-bottom: {{ $thermal ? 2 : 4 }}mm;
                    font-size: {{ $thermal ? 8 : 11 }}pt;" data-note-notice>
            {{ $notice }}
        </div>
    @endif

    @if ($taxed)
        @php
            $isCustomerForm = $note['mushak'] !== '';
            $partyFacts = [
                'name' => $note['party_name'], 'code' => $note['party_code'],
                'address' => $note['party_address'], 'bin' => $note['party_bin'],
            ];
        @endphp

        @include('print.partials.tax-parties', [
            'companyBin' => $note['company_bin'],
            'party' => $partyFacts,
            // ⓘ সরবরাহকারীর ডেবিট নোটে কাগজ দিই আমরা, ক্রেতা হিসেবে
            'issuerLabel' => $isCustomerForm ? __('accounts::note_print.issuer') : __('accounts::note_print.buyer'),
            'recipientLabel' => $isCustomerForm ? __('accounts::note_print.recipient') : __('accounts::note_print.supplier'),
            'warnBin' => $isCustomerForm,
        ])

        {{-- ⓘ নোট আর মূল চালানপত্র — নম্বর, তারিখ ও সময় --}}
        <table class="meta" data-note-facts>
            <tr>
                <td class="label" style="width: {{ $thermal ? 22 : 34 }}mm">{{ __('accounts::note_print.note_no_'.$note['type']) }}</td>
                <td><b>{{ $note['document_no'] }}</b></td>
                @unless ($thermal)
                    <td class="label" style="width: 38mm">{{ __('accounts::note_print.bill_no_'.$note['type']) }}</td>
                    <td data-note-against>{{ $note['against_no'] !== '' ? $note['against_no'] : '—' }}</td>
                @endunless
            </tr>
            <tr>
                <td class="label">{{ __('accounts::note_print.issued_at') }}</td>
                <td data-note-issued>{{ $note['issued_at'] }}</td>
                @unless ($thermal)
                    <td class="label">{{ __('accounts::note_print.bill_issued_at') }}</td>
                    <td data-note-bill-issued>{{ $note['bill_issued_at'] !== '' ? $note['bill_issued_at'] : '—' }}</td>
                @endunless
            </tr>
            @if ($thermal)
                <tr>
                    <td class="label">{{ __('accounts::note_print.bill_no_'.$note['type']) }}</td>
                    <td data-note-against>{{ $note['against_no'] !== '' ? $note['against_no'] : '—' }}</td>
                </tr>
                @if ($note['bill_issued_at'] !== '')
                    <tr><td class="label">{{ __('accounts::note_print.bill_issued_at') }}</td><td>{{ $note['bill_issued_at'] }}</td></tr>
                @endif
            @endif

            {{-- ⓘ সরবরাহকারীর ডেবিট নোট — তাঁর মূসক-৬.৭ নম্বর ও তারিখ, থাকলে --}}
            @if ($note['type'] === 'dn' && $note['supplier_note_no'] !== '')
                <tr>
                    <td class="label">{{ __('accounts::note_print.supplier_note') }}</td>
                    <td @unless($thermal) colspan="3" @endunless data-note-supplier-note>
                        {{ $note['supplier_note_no'] }}@if ($note['supplier_note_date'] !== '') · {{ $note['supplier_note_date'] }}@endif
                    </td>
                </tr>
            @endif

            <tr>
                <td class="label">{{ __('accounts::print.note_reason') }}</td>
                <td @unless($thermal) colspan="3" @endunless>{{ $note['reason'] }}</td>
            </tr>
            @if ($note['narration'] !== '')
                <tr>
                    <td class="label">{{ __('core.table.narration') }}</td>
                    <td @unless($thermal) colspan="3" @endunless>{{ $note['narration'] }}</td>
                </tr>
            @endif
        </table>

        {{-- ⭐ দামের সমন্বয়ের সারি আর মাল ফেরতের সারি — আলাদা দুই টেবিল --}}
        @if ($note['price_lines'] !== [])
            <div style="font-weight: bold; font-size: {{ $thermal ? 8 : 9.5 }}pt; margin-bottom: 1mm;" data-note-price-lines>
                {{ __('accounts::note_print.price_lines') }}
            </div>
            @include('print.partials.tax-lines', [
                'lines' => $note['price_lines'],
                'sumAmount' => $note['price_amount'], 'sumVat' => $note['price_vat'], 'sumTotal' => $note['total'],
                'sumLabel' => __('accounts::note_print.note_total'),
            ])
        @endif

        @if ($note['return_lines'] !== [])
            <div style="font-weight: bold; font-size: {{ $thermal ? 8 : 9.5 }}pt; margin-bottom: 1mm;" data-note-return-lines>
                {{ __('accounts::note_print.return_lines_'.($note['type'] === 'dn' ? 'pr' : 'sr')) }}
            </div>
            @include('print.partials.tax-lines', [
                'lines' => $note['return_lines'],
                'sumAmount' => $note['return_amount'], 'sumVat' => $note['return_vat'], 'sumTotal' => $note['returned_total'],
                'sumLabel' => __('accounts::note_print.returned_total'),
            ])
        @endif

        {{-- ⓘ নোট + ফেরত = সর্বমোট; কথায় সর্বমোট --}}
        <table style="width: {{ $thermal ? '100%' : '60%' }}; margin-left: auto; font-size: {{ $thermal ? 8 : 9.5 }}pt; border-collapse: collapse;" data-note-totals>
            @if ($note['price_lines'] !== [])
                <tr><td>{{ __('accounts::note_print.note_total') }}</td><td class="num" style="text-align: right">{{ $note['total'] }}</td></tr>
            @endif
            @if ($note['returned_total'] !== '')
                <tr><td>{{ __('accounts::note_print.returned_total') }}</td><td class="num" style="text-align: right">{{ $note['returned_total'] }}</td></tr>
            @endif
            <tr style="font-weight: bold; font-size: {{ $thermal ? 9.5 : 11.5 }}pt;">
                <td style="border-top: 0.3mm solid #000; padding-top: 1mm;">{{ __('accounts::note_print.grand_total') }}</td>
                <td class="num" style="text-align: right; border-top: 0.3mm solid #000; padding-top: 1mm;" data-note-total>{{ $note['grand_total'] }}</td>
            </tr>
        </table>
        <div style="font-size: {{ $thermal ? 7 : 8.5 }}pt; margin: 1.5mm 0 {{ $thermal ? 2 : 4 }}mm;" data-note-words>
            {{ __('accounts::print.note_in_words') }}: {{ $note['in_words'] }}
        </div>
    @else
        <table class="meta">
            <tr>
                <td class="label" style="width: 24mm">{{ __('core.print.document_no') }}</td>
                <td>{{ $note['document_no'] }}</td>
                @unless ($thermal)
                    <td class="label" style="width: 20mm">{{ __('core.print.date') }}</td>
                    <td>{{ $note['date'] }}</td>
                @endunless
            </tr>

            @if ($thermal)
                <tr>
                    <td class="label">{{ __('core.print.date') }}</td>
                    <td>{{ $note['date'] }}</td>
                </tr>
            @endif

            <tr>
                <td class="label">{{ $note['party_kind'] !== '' ? $note['party_kind'] : __('accounts::note.party') }}</td>
                <td @unless($thermal) colspan="3" @endunless data-note-party>
                    {{ $note['party_name'] }}
                    @if ($note['party_code'] !== '')
                        <span style="color: #333;">({{ $note['party_code'] }})</span>
                    @endif
                </td>
            </tr>

            @if ($note['party_address'] !== '')
                <tr>
                    <td class="label">{{ __('accounts::print.note_address') }}</td>
                    <td @unless($thermal) colspan="3" @endunless>{{ $note['party_address'] }}</td>
                </tr>
            @endif

            @if ($note['party_phone'] !== '')
                <tr>
                    <td class="label">{{ __('accounts::print.note_phone') }}</td>
                    <td @unless($thermal) colspan="3" @endunless>{{ $note['party_phone'] }}</td>
                </tr>
            @endif

            @if ($note['against_no'] !== '')
                <tr>
                    <td class="label">{{ __('accounts::print.note_ref') }}</td>
                    <td @unless($thermal) colspan="3" @endunless data-note-against>{{ $note['against_no'] }}</td>
                </tr>
            @endif

            {{-- ⭐ দুই খাত — পক্ষের আর অন্য পাশের ([[NoteAccounts]]) --}}
            <tr>
                <td class="label">{{ __('accounts::note.control_account') }}</td>
                <td @unless($thermal) colspan="3" @endunless data-note-control>{{ $note['control_account'] }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('accounts::note.other_account') }}</td>
                <td @unless($thermal) colspan="3" @endunless data-note-other>{{ $note['other_account'] }}</td>
            </tr>

            <tr>
                <td class="label">{{ __('accounts::print.note_reason') }}</td>
                <td @unless($thermal) colspan="3" @endunless>{{ $note['reason'] }}</td>
            </tr>

            @if ($note['narration'] !== '')
                <tr>
                    <td class="label">{{ __('core.table.narration') }}</td>
                    <td @unless($thermal) colspan="3" @endunless>{{ $note['narration'] }}</td>
                </tr>
            @endif
        </table>

        {{-- ⓘ অঙ্কটা আলাদা বাক্সে — কাগজের একটাই কাজ, কত টাকার সমন্বয় --}}
        <div style="border: 0.3mm solid #000; padding: {{ $thermal ? '2mm' : '3mm' }}; margin: {{ $thermal ? 2 : 4 }}mm 0;">
            @if ($note['tax'] !== '')
                <table style="width: 100%; font-size: {{ $thermal ? 8 : 10 }}pt;">
                    <tr><td>{{ __('accounts::note.amount') }}</td><td class="num" style="text-align: right">{{ $note['amount'] }}</td></tr>
                    <tr><td>{{ __('accounts::note.tax_amount') }}</td><td class="num" style="text-align: right">{{ $note['tax'] }}</td></tr>
                </table>
            @endif
            <div style="text-align: center;">
                <div style="font-size: {{ $thermal ? 7 : 9 }}pt; color: #333;">{{ __('accounts::note.total') }}</div>
                <div class="num" style="font-size: {{ $thermal ? 13 : 18 }}pt; font-weight: bold;" data-note-total>{{ $note['total'] }}</div>
                <div style="font-size: {{ $thermal ? 7 : 8 }}pt;" data-note-words>
                    {{ __('accounts::print.note_in_words') }}: {{ $note['in_words'] }}
                </div>
            </div>
        </div>
    @endif

    @php($roles = $thermal ? array_slice($signatures, -1) : $signatures)
    <table class="signatures" data-note-signatures>
        <tr>
            @foreach ($roles as $role)
                <td style="width: {{ (int) round(100 / max(count($roles), 1)) }}%">
                    <div class="sig-line">{{ $role }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    @if ($footnote !== '')
        <div style="margin-top: {{ $thermal ? 3 : 6 }}mm; font-size: {{ $thermal ? 7 : 8 }}pt; text-align: center; color: #333;" data-note-footnote>
            {{ $footnote }}
        </div>
    @endif
@endsection
