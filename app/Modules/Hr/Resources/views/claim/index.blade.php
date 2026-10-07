{{--
    ⭐ খরচের দাবি ও অগ্রিম — মালিকের আদেশ, ৭ অক্টোবর ২০২৬ ([[ExpenseClaimController]])।
    ⓘ "আমার" সবার; "সবার" কেবল `hr.claim.view`-এ, নাগালের কর্মী ধরে। মাথায় নিজের খোলা অগ্রিম।
--}}
@php
    $claimColumns = [
        ['key' => 'document_no', 'label' => __('hr::claim.number'), 'width' => '8rem',
         'render' => fn ($c) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('hr.claim.show', $c) . '\' class=\'text-(--color-brand-500) underline-offset-2 hover:underline\'>'
             . e($c->document_no) . '</a>')],
        ['key' => 'kind', 'label' => __('hr::claim.kind'), 'render' => fn ($c) => __('hr::claim.kind_'.$c->kind)],
        ['key' => 'employee', 'label' => __('hr::claim.employee'), 'render' => fn ($c) => $c->employee?->name()],
        ['key' => 'head', 'label' => __('hr::claim.head'), 'render' => fn ($c) => $c->expenseAccount?->name()],
        ['key' => 'amount', 'total' => 'money', 'label' => __('hr::claim.amount'), 'numeric' => true,
         'render' => fn ($c) => \App\Core\Support\Money::format($c->amount)],
        ['key' => 'status', 'label' => __('hr::claim.status'), 'render' => fn ($c) => __('hr::claim.state_'.$c->status)],
        ['key' => 'created_at', 'label' => __('hr::claim.submitted_at'), 'width' => '8rem',
         'render' => fn ($c) => $c->created_at?->format('d/m/Y')],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::claim.title') }}</x-slot:title>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.errors />

    <section data-boxed data-claim-list class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            @if ($tab === 'all')
                <input type="hidden" name="tab" value="all">
            @endif

            <x-ui.toolbar :title="__('hr::claim.title')" :subtitle="__('hr::claim.list_note')" :columns="$claimColumns" :quiet="['tab']">
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('hr.claim.create')">{{ __('hr::claim.new') }}</x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        @if ($openAdvance !== null)
            <p data-open-advance class="border-b border-(--color-border) px-3 py-2 text-sm">
                {{ __('hr::claim.open_advance') }}:
                <span class="num font-semibold">{{ \App\Core\Support\Money::format($openAdvance) }}</span>
                <span class="text-2xs text-(--color-ink-muted)">— {{ __('hr::claim.open_advance_note') }}</span>
            </p>
        @endif

        @can('hr.claim.view')
            <nav class="flex flex-wrap gap-1 border-b border-(--color-border) px-2 text-sm" aria-label="{{ __('hr::claim.title') }}">
                @foreach (['mine' => __('hr::claim.tab_mine'), 'all' => __('hr::claim.tab_all')] as $key => $label)
                    <a href="{{ route('hr.claim.index', $key === 'mine' ? [] : ['tab' => $key]) }}"
                       @if ($tab === $key) aria-current="page" @endif
                       class="-mb-px flex min-h-(--spacing-touch) items-center border-b-2 px-3
                              {{ $tab === $key ? 'border-(--color-brand-500) font-semibold text-(--color-ink)' : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </nav>
        @endcan

        <x-ui.table :rows="$claims" :grand="$grand ?? []" :view-url="fn ($c) => route('hr.claim.show', $c)"
                    :columns="$claimColumns" :compact="request()->boolean('compact')" :empty="__('hr::claim.none')" />

        <x-ui.pager :rows="$claims" />
        <x-ui.list-totals :rows="$claims" :grand="$grand ?? []" :columns="$claimColumns" />
    </section>
</x-layouts.app>
