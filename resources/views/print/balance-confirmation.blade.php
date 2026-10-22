@extends('print.layout')

{{--
    পাওনা-দেনা নিশ্চিতকরণের চিঠি — ISA 505 (ERP ১০০%-এর হিসাবের ফাঁক #১৯, ২০ অক্টোবর ২০২৬; [[BalanceConfirmation]])।

    ⓘ উপরে কাকে, কোন তারিখে, কত জের, কার দিকে; নিচে কেটে ফেরত দেওয়ার উত্তরের অংশ — মেলে / মেলে না, তাদের খাতার জের, তফাতের
    কারণ, সই-সিল। এটা টাকা চাওয়ার চিঠি নয়, সেটাও লেখা থাকে, যাতে পক্ষ ভয় না পেয়ে খোলা মনে মেলান।
--}}

@section('body')
    <div style="text-align: center; font-weight: bold; font-size: 14pt; margin: 0 0 3mm;" data-confirmation-title>{{ $title }}</div>

    <table class="meta">
        <tr>
            <td class="label" style="width: 30mm">{{ __('confirmation.to') }}</td>
            <td data-confirmation-party>{{ $party }}</td>
            <td class="label" style="width: 22mm">{{ __('core.print.date') }}</td>
            <td>{{ \App\Core\Support\DateFormat::format(now()->toDateString()) }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('confirmation.code') }}</td>
            <td>{{ $facts['code'] ?? '' }}</td>
            <td class="label">{{ __('confirmation.as_of') }}</td>
            <td data-confirmation-date>{{ \App\Core\Support\DateFormat::format($asOf) }}</td>
        </tr>
        @if (filled($facts['address'] ?? null) || filled($facts['phone'] ?? null))
            <tr>
                <td class="label">{{ __('confirmation.address') }}</td>
                <td colspan="3">{{ collect([$facts['address'] ?? null, $facts['phone'] ?? null])->filter()->implode(' · ') }}</td>
            </tr>
        @endif
    </table>

    <p style="margin: 4mm 0 2mm;">{{ __('confirmation.greeting') }}</p>
    <p style="margin: 0 0 2mm; line-height: 1.5;" data-confirmation-body>
        {{ __('confirmation.body', ['date' => \App\Core\Support\DateFormat::format($asOf)]) }}
        <strong data-confirmation-amount>{{ $amount }}</strong> — <strong data-confirmation-side>{{ __('confirmation.side_'.$side) }}</strong>.
    </p>
    <p style="margin: 0 0 2mm; line-height: 1.5;">{{ __('confirmation.ask', ['days' => $days]) }}</p>
    <p style="margin: 0 0 4mm; font-style: italic;">{{ __('confirmation.not_a_demand') }}</p>

    <table class="signatures">
        <tr>
            <td style="width: 50%"><div class="sig-line">{{ __('confirmation.prepared_by') }}</div></td>
            <td style="width: 50%"><div class="sig-line">{{ __('confirmation.approved_by') }}</div></td>
        </tr>
    </table>

    <div style="border-top: 1px dashed #555; margin: 8mm 0 3mm; padding-top: 2mm; font-weight: bold;" data-confirmation-reply>
        {{ __('confirmation.reply_title') }}
    </div>
    <p style="margin: 0 0 2mm;">{{ $party }} · {{ __('confirmation.as_of') }} {{ \App\Core\Support\DateFormat::format($asOf) }}</p>
    <p style="margin: 0 0 2mm;">[&nbsp;&nbsp;&nbsp;] {{ __('confirmation.agree', ['amount' => $amount, 'side' => __('confirmation.side_'.$side)]) }}</p>
    <p style="margin: 0 0 2mm;">[&nbsp;&nbsp;&nbsp;] {{ __('confirmation.disagree') }} ______________________</p>
    <p style="margin: 0 0 4mm;">{{ __('confirmation.reason') }} ________________________________________________</p>

    <table class="signatures">
        <tr>
            <td style="width: 34%"><div class="sig-line">{{ __('confirmation.reply_sign') }}</div></td>
            <td style="width: 33%"><div class="sig-line">{{ __('confirmation.reply_name') }}</div></td>
            <td style="width: 33%"><div class="sig-line">{{ __('confirmation.reply_date') }}</div></td>
        </tr>
    </table>
@endsection
