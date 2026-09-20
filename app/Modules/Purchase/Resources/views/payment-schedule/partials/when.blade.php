{{-- শেষ তারিখ আর কত দিন — ⓘ তারিখ না থাকলে বিলের তারিখই, নগদের বিল --}}
@php
    $due = ($bill->due_on ?? $bill->trx_date)->copy()->startOfDay();
    $days = (int) $today->copy()->startOfDay()->diffInDays($due, false);
@endphp
<span class="inline-flex flex-col">
    <span>{{ $due->format('d M Y') }}</span>
    <x-ui.badge :tone="$days < 0 ? 'danger' : ($days <= 7 ? 'pending' : 'info')" class="mt-0.5 self-start">
        {{ $days < 0
            ? __('purchase::schedule.days_late', ['days' => -$days])
            : ($days === 0 ? __('purchase::schedule.today') : __('purchase::schedule.days_left', ['days' => $days])) }}
    </x-ui.badge>
</span>
