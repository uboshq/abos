{{--
    ডিলারের নিজের পাতা।

    সবার উপরে বকেয়া, বড় করে — কারণ ডিলার এই পাতায় আসেন ওই একটা
    সংখ্যা দেখতে। বাকি সব তার ব্যাখ্যা।
--}}
<x-sales::portal.layout :dealer="$dealer">
    <div class="mb-5 rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) px-4 py-4">
        <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
            {{ __('sales::portal.due') }}
        </p>
        <p class="num text-3xl font-semibold">{{ \App\Core\Support\Money::format($due) }}</p>
    </div>

    <a href="{{ route('sales.portal.claim.create') }}"
       class="mb-6 block rounded-(--radius-field) bg-(--color-brand-500) px-4 py-3 text-center
              font-medium text-white">
        {{ __('sales::portal.claim_title') }}
    </a>

    <h2 class="mb-2 text-sm font-semibold">{{ __('sales::portal.my_claims') }}</h2>

    @if ($claims->isEmpty())
        <p class="mb-6 text-sm text-(--color-ink-muted)">{{ __('sales::portal.no_claims') }}</p>
    @else
        <ul class="mb-6 divide-y divide-(--color-border) rounded-(--radius-card) border
                   border-(--color-border) bg-(--color-surface-card)">
            @foreach ($claims as $claim)
                <li>
                    <a href="{{ route('sales.portal.claim.show', $claim) }}"
                       class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-(--color-surface-hover)">
                        <span class="min-w-0">
                            <span class="block num font-medium">
                                {{ \App\Core\Support\Money::format($claim->amount) }}
                            </span>
                            <span class="block text-2xs text-(--color-ink-muted)">
                                {{ $claim->claimed_on?->format('d M Y') }}
                                @if ($claim->reference) · {{ $claim->reference }} @endif
                            </span>
                        </span>

                        <x-ui.badge :tone="match ($claim->status) {
                            'accepted' => 'success',
                            'rejected' => 'danger',
                            default => 'pending',
                        }">
                            {{ __('sales::portal.'.$claim->status) }}
                        </x-ui.badge>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="mb-2 text-sm font-semibold">{{ __('sales::portal.recent_bills') }}</h2>

    @if ($invoices->isEmpty())
        <p class="text-sm text-(--color-ink-muted)">{{ __('sales::portal.no_bills') }}</p>
    @else
        <ul class="divide-y divide-(--color-border) rounded-(--radius-card) border
                   border-(--color-border) bg-(--color-surface-card)">
            @foreach ($invoices as $invoice)
                <li class="flex items-center justify-between gap-3 px-4 py-3">
                    <span class="min-w-0">
                        <span class="block font-medium">{{ $invoice->document_no }}</span>
                        <span class="block text-2xs text-(--color-ink-muted)">
                            {{ $invoice->trx_date?->format('d M Y') }}
                        </span>
                    </span>
                    <span class="num font-medium">
                        {{ \App\Core\Support\Money::format($invoice->grand_total) }}
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</x-sales::portal.layout>
