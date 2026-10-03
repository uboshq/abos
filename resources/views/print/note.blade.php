@extends('print.layout')

{{--
    ডেবিট/ক্রেডিট নোট — মালিক, ৩ অক্টোবর ২০২৬: নোটের কোনো ছাপা ছিল না ([[NotePrintController]])।

    ⓘ কোম্পানির মাথা লেআউটের; এখানে নোটের দিক (ডেবিট বা ক্রেডিট), নম্বর, তারিখ, পক্ষ, কারণ, বিবরণ, টাকা আর কথায়,
    বিপরীতের কাগজ, সই আর নিচের লেখা। থার্মালে একটা সই-ঘর (পক্ষের), বাকি মাপে তিনটা।
--}}

@section('body')
    @php($thermal = $paper->isThermal)

    <div style="text-align: center; font-weight: bold; font-size: {{ $thermal ? 11 : 14 }}pt; margin-bottom: 2mm;" data-note-direction>
        {{ $note['direction'] }}
    </div>

    @if (! empty($notice))
        {{-- ⓘ বাতিল বা আগে ছাপা — কাগজটা যেন আসল প্রথম কপি হিসেবে না চলে --}}
        <div style="text-align: center; font-weight: bold; border: 0.4mm solid #000;
                    padding: {{ $thermal ? '1mm' : '2mm' }}; margin-bottom: {{ $thermal ? 2 : 4 }}mm;
                    font-size: {{ $thermal ? 8 : 11 }}pt;" data-note-notice>
            {{ $notice }}
        </div>
    @endif

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
