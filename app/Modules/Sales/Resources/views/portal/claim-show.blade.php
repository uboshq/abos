{{--
    একটা দাবির অবস্থা।

    প্রত্যাখ্যাত হলে কারণটা এখানেই দেখা যায় — আর ওটাই ফোনটা এড়ায়।
--}}
<x-sales::portal.layout :customer="$customer">
    <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) p-4">
        <div class="mb-3 flex items-center justify-between gap-3">
            <p class="num text-2xl font-semibold">
                {{ \App\Core\Support\Money::format($claim->amount) }}
            </p>
            <x-ui.badge :tone="match ($claim->status) {
                'accepted' => 'success',
                'rejected' => 'danger',
                default => 'pending',
            }">
                {{ __('sales::portal.'.$claim->status) }}
            </x-ui.badge>
        </div>

        <dl class="grid gap-2 text-sm">
            <div class="flex justify-between gap-3">
                <dt class="text-(--color-ink-muted)">{{ __('sales::portal.claimed_on') }}</dt>
                <dd>{{ $claim->claimed_on?->format('d M Y') }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-(--color-ink-muted)">{{ __('sales::portal.method') }}</dt>
                <dd>{{ __('sales::portal.'.$claim->method) }}</dd>
            </div>
            @if ($claim->reference)
                <div class="flex justify-between gap-3">
                    <dt class="text-(--color-ink-muted)">{{ __('sales::portal.reference') }}</dt>
                    <dd>{{ $claim->reference }}</dd>
                </div>
            @endif
            @if ($claim->note)
                <div class="flex justify-between gap-3">
                    <dt class="text-(--color-ink-muted)">{{ __('sales::portal.note') }}</dt>
                    <dd>{{ $claim->note }}</dd>
                </div>
            @endif
        </dl>

        @if ($claim->decision_reason)
            <p class="mt-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                      text-(--color-badge-danger-ink)">
                {{ $claim->decision_reason }}
            </p>
        @endif
    </div>

    <a href="{{ route('sales.portal.home') }}"
       class="mt-4 block text-center text-sm text-(--color-brand-500) hover:underline">
        {{ __('sales::portal.title') }}
    </a>
</x-sales::portal.layout>
