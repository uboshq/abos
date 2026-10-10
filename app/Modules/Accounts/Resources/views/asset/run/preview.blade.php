{{--
    ⭐ মাসের অবচয় — আগে দেখা, তারপর বসানো (স্থায়ী সম্পদ ধাপ ২, মালিক, ১০ অক্টোবর ২০২৬)।

    ⓘ উপরে মাস আর মোট, মাঝে প্রতিটা সম্পদ — কত বসবে, আর শূন্য হলে কেন (শুরু হয়নি, শেষ দামে পৌঁছেছে, এ মাসের একক নেই…)।
    "বসান" চাপলে ঠিক এই সারিগুলোই শাখায় একটা কাগজে বসে। নিচে এই মাসে যে কাগজগুলো ইতিমধ্যে বসেছে — দুইবার চাপলেও
    আবার বসে না ([[DepreciationEngine::run()]])।
--}}
@php
    $columns = [
        ['key' => 'asset', 'label' => __('accounts::asset.name'),
            'render' => fn ($r) => view('accounts::asset.partials.name', ['asset' => $r['asset']])],
        ['key' => 'branch', 'label' => __('accounts::asset.branch'), 'width' => '10rem',
            'render' => fn ($r) => $r['asset']->branch?->name() ?? '—'],
        ['key' => 'category', 'label' => __('accounts::asset.category'), 'width' => '10rem',
            'render' => fn ($r) => $r['asset']->category?->name() ?? '—'],
        ['key' => 'method', 'label' => __('accounts::asset.method'), 'width' => '10rem',
            'render' => fn ($r) => __('accounts::asset.'.$r['asset']->method)],
        ['key' => 'amount', 'label' => __('accounts::asset.amount'), 'numeric' => true, 'width' => '10rem',
            'render' => fn ($r) => view('accounts::asset.partials.amount', ['value' => $r['amount']])],
        ['key' => 'reason', 'label' => __('accounts::asset.run_why'), 'width' => '14rem',
            'render' => fn ($r) => $r['reason'] === null ? '' : __('accounts::asset.skip_'.$r['reason'])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::asset.run_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::asset.run_title')" :subtitle="$month->translatedFormat('F Y')" />
    </x-slot:header>

    @if (session('status'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('status') }}
        </div>
    @endif

    <x-ui.errors />

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <form method="GET" action="{{ route('accounts.asset.run.preview') }}" class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::asset.run_month') }}</span>
                <input type="month" name="month" required value="{{ $month->format('Y-m') }}"
                       class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2">
            </label>
            <x-ui.button type="submit" tone="secondary">{{ __('accounts::asset.run_preview') }}</x-ui.button>
        </form>

        <div class="ms-auto text-end">
            <p class="text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.run_total') }}</p>
            <p class="num text-xl font-semibold" data-run-total>{{ \App\Core\Support\Money::format($total) }}</p>
        </div>

        @if ($open && bccomp($total, '0', 4) > 0)
            <form method="POST" action="{{ route('accounts.asset.depreciate') }}">
                @csrf
                <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                <x-ui.button type="submit" tone="primary">{{ __('accounts::asset.run_action') }}</x-ui.button>
            </form>
        @elseif (! $open)
            <p class="text-sm text-(--color-badge-danger-ink)">{{ __('accounts::asset.run_month_locked') }}</p>
        @endif
    </div>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table :rows="$rows" :columns="$columns" :empty="__('accounts::asset.empty')" />
    </div>

    @if ($runs->isNotEmpty())
        <section data-boxed class="mt-5 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">{{ __('accounts::asset.run_papers') }}</h2>
            <ul class="divide-y divide-(--color-border) text-sm">
                @foreach ($runs as $run)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-2">
                        <a href="{{ route('accounts.asset.run.show', $run) }}" class="font-medium text-(--color-brand-500) hover:underline">{{ $run->document_no }}</a>
                        <span>{{ $run->branch?->name() ?? __('accounts::asset.no_branch') }}</span>
                        <span class="text-(--color-ink-muted)">{{ trans_choice('accounts::asset.run_assets', $run->assets_count, ['count' => $run->assets_count]) }}</span>
                        <span class="num ms-auto tabular-nums">{{ \App\Core\Support\Money::format((string) $run->total) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layouts.app>
