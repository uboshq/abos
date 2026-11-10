{{--
    ছাপা চেকের খাতা: অবস্থা আর কাজ — আবার ছাপা (কারণসহ, নতুন ট্যাবে PDF) আর নষ্ট পাতা (কারণসহ)।
    ⓘ লেখার ঘর আছে বলে "অ্যাকশন ▾" নয় — চেকের খাতার মতোই সারির ভিতরে খোলা ছোট ফর্ম।
--}}
@php
    $spoiled = $print->status === \App\Modules\Accounts\Models\ChequePrint::SPOILED;
    $may = $print->off_books ? $mayFree : $mayPrint;
    $field = 'h-(--spacing-field-compact) min-w-0 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm';
@endphp
<div class="flex flex-col gap-1">
    <span data-print-state="{{ $print->status }}" @class([
        'w-fit rounded-full px-2 text-xs font-bold',
        'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => $spoiled,
        'bg-(--color-badge-info-bg) text-(--color-badge-info-ink)' => ! $spoiled,
    ])>{{ $spoiled ? __('accounts::cheque_print.state_spoiled') : __('accounts::cheque_print.state_printed') }}</span>
    @if ($may && ! $spoiled)
        <details>
            <summary class="cursor-pointer text-xs font-semibold text-(--color-brand-600)">{{ __('accounts::cheque_print.again') }} · {{ __('accounts::cheque_print.spoil') }}</summary>
            <form method="POST" action="{{ route('accounts.cheque.print.again', $print) }}" target="_blank" class="mt-1 flex gap-1">
                @csrf
                <input type="text" name="reason" required maxlength="200" placeholder="{{ __('accounts::cheque_print.reason') }}" aria-label="{{ __('accounts::cheque_print.reason') }}" class="{{ $field }}">
                <button type="submit" class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-brand-500) px-2 text-xs font-semibold text-(--color-brand-600)">{{ __('accounts::cheque_print.again') }}</button>
            </form>
            <form method="POST" action="{{ route('accounts.cheque.print.spoil', $print) }}" class="mt-1 flex gap-1">
                @csrf
                <input type="text" name="reason" required maxlength="200" placeholder="{{ __('accounts::cheque_print.reason_spoil') }}" aria-label="{{ __('accounts::cheque_print.reason_spoil') }}" class="{{ $field }}">
                <button type="submit" class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-badge-danger-ink) px-2 text-xs font-semibold text-(--color-badge-danger-ink)">{{ __('accounts::cheque_print.spoil') }}</button>
            </form>
        </details>
    @endif
</div>
