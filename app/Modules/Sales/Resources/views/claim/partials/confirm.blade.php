{{--
    ⭐ ব্যাংকে মিলেছে — "অনুমোদন দিন" (খাতায় তোলা, আদায়ের দরজায়: [[DepositClaimService::accept()]]) আর মিল খোলা (কারণসহ)।
    ⓘ খাত, অঙ্ক আর তারিখ ব্যাংকের সারি থেকে; অফিস কেবল চার্জ লেখেন (খবরের অঙ্ক − ব্যাংকে এসেছে)।
--}}
@php
    $line = $claim->statementLine;
    $gap = $line === null ? '0' : bcsub((string) $claim->amount, (string) $line->credit, 2);
@endphp
<div class="flex flex-wrap items-end gap-2">
    @if ($claim->stage() === 'awaiting')
        <span class="text-2xs text-(--color-ink-muted)" data-awaiting-approval>{{ __('sales::deposit.awaiting_hint', ['no' => $claim->collection?->document_no]) }}</span>
    @else
        @can('sales.claim.decide')
            <form method="POST" action="{{ route('sales.claim.accept', $claim) }}" class="flex flex-wrap items-end gap-2" data-confirm-deposit>
                @csrf
                <input type="number" step="0.01" min="0" name="bank_charge" value="{{ bccomp($gap, '0', 2) > 0 ? $gap : '0' }}"
                       title="{{ __('sales::field.bank_charge') }}" aria-label="{{ __('sales::field.bank_charge') }}"
                       class="num h-(--spacing-field-compact) w-24 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                <x-ui.button type="submit" tone="primary">{{ __('sales::deposit.approve') }}</x-ui.button>
            </form>
        @endcan
    @endif
    @can('sales.claim.match')
        <form method="POST" action="{{ route('sales.claim.match.unmatch', $claim) }}" class="flex flex-wrap items-end gap-2" data-unmatch>
            @csrf
            <input type="text" name="reason" required maxlength="500" placeholder="{{ __('sales::portal.reason') }}"
                   aria-label="{{ __('sales::portal.reason') }}"
                   class="h-(--spacing-field-compact) w-36 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2">
            <x-ui.button type="submit">{{ __('sales::deposit.unmatch') }}</x-ui.button>
        </form>
    @endcan
</div>
