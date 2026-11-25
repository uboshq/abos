{{-- ছাপা চেকের খাতা: কে, কখন — আবার ছাপা হলে কতবার আর কেন --}}
<span class="flex flex-col">
    <span>{{ $print->printer?->name ?? '—' }} · {{ \App\Core\Support\DateFormat::formatWithTime($print->updated_at) }}</span>
    @if ($print->times > 1 || filled($print->reason))
        <span class="text-xs text-(--color-badge-warning-ink)">{{ __('accounts::cheque_print.printed_times', ['times' => $print->times]) }}@if (filled($print->reason)) — {{ $print->reason }}@endif</span>
    @endif
</span>
