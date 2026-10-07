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
            {{-- ⭐ কোন বিলের বিপরীতে — দাবির প্রস্তাব (টাকার পরিকল্পনা ২, ৭ অক্টোবর ২০২৬) --}}
            @foreach (\App\Modules\Sales\Http\Controllers\DepositRequestController::billFacts($claim) as $bill)
                <div class="flex justify-between gap-3" data-claim-bills>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::slip.bills_named') }} {{ $bill['no'] }}</dt>
                    <dd class="num">{{ \App\Core\Support\Money::format($bill['amount']) }}</dd>
                </div>
            @endforeach
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

        @if ($hasSlip ?? false)
            <a href="{{ route('sales.portal.claim.slip', $claim) }}" target="_blank" data-slip-link
               class="mt-3 inline-block text-sm text-(--color-brand-500) hover:underline">{{ __('sales::slip.view') }}</a>
        @endif

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
