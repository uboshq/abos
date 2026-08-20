{{-- তারিখ পেরোনো অথচ ঝুলে থাকা চেক লাল — আগাম তারিখের চেক ফেলে রাখা
     স্বাভাবিক, তারিখ পেরোনোর পরেও ফেলে রাখা নয়। --}}
@php
    $overdue = $cheque->isOpen() && $cheque->cheque_date?->isBefore(now()->startOfDay());
@endphp
<span @class(['font-semibold text-(--color-danger)' => $overdue])>
    {{ $cheque->cheque_date?->format('d M Y') }}
</span>
