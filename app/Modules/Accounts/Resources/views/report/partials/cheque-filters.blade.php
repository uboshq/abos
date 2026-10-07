{{--
    চেকের খাতার নিজের দুই ছাঁকনি — দিক আর অবস্থা ([[ChequeReports]], ২ অক্টোবর ২০২৬)।
    ⓘ রিপোর্টের পাতার `$extraFilters` দরজা দিয়ে আসে, তাই ভাগের পাতায় কোনো রিপোর্টের নাম লেখা লাগে না।
--}}
<label>
    <span class="sr-only">{{ __('accounts::cheque.direction') }}</span>
    <select name="direction"
            class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                   bg-(--color-surface-app) px-2 text-sm">
        <option value="">{{ __('accounts::cheque.direction_all') }}</option>
        @foreach (['received', 'issued'] as $code)
            <option value="{{ $code }}" @selected(($filters['direction'] ?? null) === $code)>{{ __('accounts::cheque.state_'.$code) }}</option>
        @endforeach
    </select>
</label>

<label>
    <span class="sr-only">{{ __('accounts::cheque.status') }}</span>
    <select name="status"
            class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                   bg-(--color-surface-app) px-2 text-sm">
        <option value="">{{ __('accounts::cheque.status_all') }}</option>
        @foreach (['pdc', 'due', 'pending', 'deposited', 'cleared', 'bounced', 'cancelled'] as $code)
            <option value="{{ $code }}" @selected(($filters['status'] ?? null) === $code)>{{ __('accounts::cheque.state_'.$code) }}</option>
        @endforeach
    </select>
</label>
