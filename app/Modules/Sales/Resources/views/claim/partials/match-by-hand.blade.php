{{--
    ⭐ হাতে মেলানো — ব্যাংকের না-মেলা সারি থেকে বেছে (জমার খবর ধাপ গ)।
    ⓘ একই খাতের সারি আগে; অঙ্ক আলাদা হলে (ব্যাংক চার্জ) তবুও মেলানো যায় — "অনুমোদন দিন"-এ চার্জ লেখা হয়।
--}}
@php
    $choices = $lines->sortBy(fn ($l) => ($claim->bank_account_id !== null && (int) $l->bank_account_id === (int) $claim->bank_account_id ? 0 : 1)
        .'|'.(bccomp((string) $l->credit, (string) $claim->amount, 4) === 0 ? 0 : 1));
@endphp
@if ($choices->isEmpty())
    <span class="text-2xs text-(--color-ink-muted)">{{ __('sales::deposit.no_free_line') }}</span>
@else
    <form method="POST" action="{{ route('sales.claim.match.store', $claim) }}" class="flex flex-wrap items-end gap-2" data-match-by-hand>
        @csrf
        <select name="line" required aria-label="{{ __('sales::deposit.bank_line') }}"
                class="h-(--spacing-field-compact) max-w-64 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2">
            @foreach ($choices as $line)
                <option value="{{ $line->id }}">{{ \App\Core\Support\DateFormat::format($line->trx_date) }} · {{ \App\Core\Support\Money::format($line->credit) }} · {{ $line->bankAccount?->label() }}@if ($line->reference) · {{ $line->reference }}@endif</option>
            @endforeach
        </select>
        <x-ui.button type="submit">{{ __('sales::deposit.match') }}</x-ui.button>
    </form>
@endif
