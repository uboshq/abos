{{-- কাগজের সারি — ছাপার নিয়ন্ত্রণ আর "Set Your Invoice Information" দুই পাতাই এটা আঁকে।

     ⭐ মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"Tab thakbe 'Set Your Invoice Information', Invoice, Challan, Order,
     Qutation, aday rosid, ভাউচার"* — এই ক্রমে। ⚠️ সারিটা এক ফাইলে, কারণ দুই ব্লেডে কপি করলে একদিন
     একটায় নতুন কাগজ আসত আর অন্যটায় নয়। --}}
<nav aria-label="{{ __('system_admin::settings.print_papers') }}"
     class="-mx-1 mb-4 flex gap-1 overflow-x-auto px-1 pb-1">
    @foreach ([
        [
            'code' => 'invoice_info',
            'label' => __('system_admin::settings.invoice_info_title'),
            'url' => route('system_admin.print_control.invoice_info'),
        ],
        ...array_map(fn (array $one) => [
            'code' => $one['code'],
            'label' => $one['label'],
            'url' => route('system_admin.print_control', ['paper' => $one['code']]),
        ], $papers),
    ] as $one)
        <a href="{{ $one['url'] }}"
           @class([
               'flex min-h-(--spacing-touch) shrink-0 items-center whitespace-nowrap',
               'rounded-(--radius-field) px-3 text-sm transition-colors',
               'bg-(--color-brand-600) text-(--color-brand-ink) font-medium' => $current === $one['code'],
               'text-(--color-ink-muted) hover:bg-(--color-surface-sunken)' => $current !== $one['code'],
           ])
           @if ($current === $one['code']) aria-current="page" @endif>
            {{ $one['label'] }}
        </a>
    @endforeach
</nav>
