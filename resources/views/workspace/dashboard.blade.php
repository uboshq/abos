{{-- ড্যাশবোর্ড — Phase 11-এ উইজেট আসবে; এখন শেলটা কাজ করছে কি না দেখার জায়গা। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.menu.dashboard') }}</x-slot:title>

    <x-slot:header>
        <h1 class="text-xl font-semibold">{{ __('core.menu.dashboard') }}</h1>
        <p class="text-sm text-(--color-ink-muted)">
            {{ auth()->user()?->currentCompany?->name() }}
            @if (auth()->user()?->currentBranch)
                · {{ auth()->user()->currentBranch->name() }}
            @endif
        </p>
    </x-slot:header>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => __('core.source.sales_invoice'), 'value' => '—', 'colour' => 'var(--color-module-sales)'],
            ['label' => __('core.source.purchase_invoice'), 'value' => '—', 'colour' => 'var(--color-module-purchase)'],
            ['label' => __('core.accounting.receivable'), 'value' => '—', 'colour' => 'var(--color-receivable)'],
            ['label' => __('core.accounting.payable'), 'value' => '—', 'colour' => 'var(--color-payable)'],
        ] as $tile)
            <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)
                        p-4 shadow-(--shadow-card)">
                <p class="text-sm text-(--color-ink-muted)">{{ $tile['label'] }}</p>
                <p class="tabular mt-1 text-2xl font-semibold" style="color: {{ $tile['colour'] }}">
                    {{ $tile['value'] }}
                </p>
            </div>
        @endforeach
    </div>

    <div class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-6">
        <h2 class="text-lg font-semibold">{{ __('core.dashboard.foundation_ready') }}</h2>
        <p class="mt-1 max-w-(--spacing-prose-max) text-sm text-(--color-ink-body)">
            {{ __('core.dashboard.foundation_note') }}
        </p>
    </div>
</x-layouts.app>
