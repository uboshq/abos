@extends('print.layout')

{{--
    ⭐ জের নিশ্চিতকরণের চিঠি — অর্থ-মডিউলের পরিকল্পনা ১.৯, ৫ অক্টোবর ২০২৬ ([[HandLoanLetterController]])।

    ⓘ কোম্পানির মাথা লেআউটের; এখানে তারিখ, প্রাপক, এক বাক্যে জের (কে কাকে কত), টাকা কথায়, আর ফেরত-অংশ: "ঠিক আছে"
    নাকি "আমার হিসাবে …", প্রাপকের সই ও তারিখ। সই-ঘর দুইটা — আমাদের আর তাঁর।
--}}

@section('body')
    <div style="text-align: center; font-weight: bold; font-size: 14pt; margin-bottom: 4mm;" data-letter-title>
        {{ __('finance::hand_loan_letter.title') }}
    </div>

    <table class="meta">
        <tr>
            <td class="label" style="width: 24mm">{{ __('core.print.date') }}</td>
            <td>{{ $letter['date'] }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('finance::hand_loan_letter.to') }}</td>
            <td data-letter-name>
                {{ $letter['name'] }}
                @if ($letter['code'] !== '')
                    <span style="color: #333;">({{ $letter['code'] }})</span>
                @endif
            </td>
        </tr>
        @if ($letter['address'] !== '')
            <tr>
                <td class="label">{{ __('finance::hand_loan_report.address') }}</td>
                <td>{{ $letter['address'] }}</td>
            </tr>
        @endif
        @if ($letter['mobile'] !== '')
            <tr>
                <td class="label">{{ __('finance::hand_loan_report.mobile') }}</td>
                <td>{{ $letter['mobile'] }}</td>
            </tr>
        @endif
    </table>

    <p style="margin: 5mm 0 3mm; font-size: 10.5pt;">{{ __('finance::hand_loan_letter.greeting') }}</p>
    <p style="margin: 0 0 3mm; font-size: 10.5pt;" data-letter-line>{{ $letter['line'] }}</p>

    @if ($letter['side'] !== 0)
        <div style="border: 0.3mm solid #000; padding: 3mm; margin: 3mm 0; text-align: center;">
            <div class="num" style="font-size: 18pt; font-weight: bold;" data-letter-amount>{{ $letter['amount'] }}</div>
            <div style="font-size: 8pt;" data-letter-words>{{ __('finance::hand_loan_letter.in_words') }}: {{ $letter['in_words'] }}</div>
        </div>
    @endif

    <p style="margin: 3mm 0; font-size: 10.5pt;">{{ __('finance::hand_loan_letter.please') }}</p>

    {{-- ⓘ ফেরত-অংশ — প্রাপক টিক দেন বা নিজের হিসাবের অঙ্ক লেখেন --}}
    <div style="border: 0.3mm dashed #000; padding: 3mm; margin: 4mm 0; font-size: 10pt;" data-letter-reply>
        <div style="margin-bottom: 3mm;">☐ {{ __('finance::hand_loan_letter.agree') }}</div>
        <div>☐ {{ __('finance::hand_loan_letter.disagree') }} ____________________</div>
    </div>

    <table class="signatures" data-letter-signatures>
        <tr>
            <td style="width: 50%"><div class="sig-line">{{ __('finance::hand_loan_letter.ours') }}</div></td>
            <td style="width: 50%"><div class="sig-line">{{ __('finance::hand_loan_letter.theirs') }}</div></td>
        </tr>
    </table>
@endsection
