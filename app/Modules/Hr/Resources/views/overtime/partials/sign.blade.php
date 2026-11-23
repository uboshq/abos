{{-- ⭐ "সই দিন" — কেবল যিনি সত্যিই পারেন ([[OvertimeService::maySign()]]); বোতাম দেখা গেলে দরজাও খোলে --}}
@if ($may)
    <form method="POST" action="{{ route('hr.overtime.sign', $sheet->id) }}">
        @csrf
        <x-ui.button type="submit" tone="primary">{{ __('hr::action.ot_sign') }}</x-ui.button>
    </form>
@else
    <span class="text-2xs text-(--color-ink-muted)">{{ $sheet->isSigned() ? __('hr::kind.ot_signed') : __('hr::kind.ot_waiting') }}</span>
@endif
