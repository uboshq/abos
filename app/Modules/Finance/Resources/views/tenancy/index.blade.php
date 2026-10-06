{{--
    ⭐ ভাড়াটের চুক্তি — আমরা যখন জায়গা ভাড়া দিই (মালিকের সিদ্ধান্ত প্র৩, ৬ অক্টোবর ২০২৬; [[TenancyController]])।
    ⓘ ভাড়ার চুক্তির তালিকার একই ছাঁদ: ট্যাব (চালু · শেষ), খোঁজা, টেবিল, মোট; রিপোর্ট আর মাসের দাবির বোতাম মাথায়।
--}}
@php
    $tenancyColumns = [
        ['key' => 'tenant', 'label' => __('finance::tenancy.tenant'),
         'render' => fn ($t) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('finance.tenancy.show', $t) . '\' '
             . 'class=\'text-(--color-brand-500) underline-offset-2 hover:underline\'>'
             . e($t->tenant) . '</a>')],
        ['key' => 'document_no', 'label' => __('finance::tenancy.contract'), 'width' => '8rem',
         'render' => fn ($t) => $t->document_no],
        ['key' => 'premises', 'label' => __('finance::tenancy.premises'),
         'render' => fn ($t) => $t->premises],
        ['key' => 'monthly_rent', 'total' => 'money', 'label' => __('finance::tenancy.monthly_rent'), 'numeric' => true,
         'render' => fn ($t) => \App\Core\Support\Money::format($t->monthly_rent)],
        /* ⭐ যে দুইটা সংখ্যার জন্য এই পর্দা — কত পাওনা, আর কত জামানত ফেরত দিতে হবে */
        ['key' => 'outstanding', 'label' => __('finance::tenancy.outstanding'), 'numeric' => true,
         'render' => fn ($t) => new \Illuminate\Support\HtmlString(
             '<span class=\'font-semibold\'>' . e(\App\Core\Support\Money::format($t->outstanding())) . '</span>')],
        ['key' => 'deposit_held', 'label' => __('finance::tenancy.deposit_held'), 'numeric' => true,
         'render' => fn ($t) => \App\Core\Support\Money::format($t->depositHeld())],
        ['key' => 'ends_on', 'label' => __('finance::tenancy.ends_on'), 'width' => '8rem',
         'render' => fn ($t) => $t->ends_on->format('d/m/Y')],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::tenancy.title') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.errors />

    <section data-boxed data-tenancy-list
             class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <form method="GET" class="contents">
            @if ($tab === 'closed')
                <input type="hidden" name="tab" value="closed">
            @endif

            <x-ui.toolbar :title="__('finance::tenancy.title')"
                :subtitle="__('finance::tenancy.list_note')"
                :columns="$tenancyColumns"
                :search-placeholder="__('finance::tenancy.search')"
                :quiet="['tab']">
                <x-slot:actions>
                    @can('finance.rental.create')
                        <x-ui.button tone="primary" icon="plus" :href="route('finance.tenancy.create')">
                            {{ __('finance::tenancy.new') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <div class="px-2 pb-2">
            @include('finance::tenancy.partials.report-tabs')

            {{-- ⭐ মাসের ভাড়া দাবি — চলতি মাস নিজেই বসে ([[RentAccrue]]); বোতামটা আগের খোলা মাসের জন্য --}}
            @if ($tab === 'running')
                @can('finance.rental.create')
                    <form method="POST" action="{{ route('finance.tenancy.charge') }}" data-tenancy-charge
                          class="mt-2 flex w-full flex-wrap items-end gap-2 text-sm">
                        @csrf
                        <label class="grid gap-0.5">
                            <span class="block text-2xs text-(--color-ink-muted)">{{ __('finance::tenancy.month') }}</span>
                            <input type="month" name="month" required value="{{ now()->format('Y-m') }}" max="{{ now()->format('Y-m') }}"
                                   class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                        </label>
                        <x-ui.button type="submit" tone="secondary">{{ __('finance::tenancy.charge_run') }}</x-ui.button>
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('finance::tenancy.charge_note') }}</span>
                    </form>
                @endcan
            @endif
        </div>

        <nav class="flex flex-wrap gap-1 border-b border-(--color-border) px-2 text-sm"
             aria-label="{{ __('finance::tenancy.title') }}">
            @foreach (['running' => __('finance::state.active'), 'closed' => __('finance::state.closed')] as $key => $label)
                <a href="{{ route('finance.tenancy.index', $key === 'running' ? [] : ['tab' => $key]) }}"
                   @if ($tab === $key) aria-current="page" @endif
                   class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                          {{ $tab === $key
                              ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                              : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                    {{ $label }}
                    <span class="rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">
                        {{ $counts[$key] }}
                    </span>
                </a>
            @endforeach
        </nav>

        <x-ui.table :rows="$tenancies"
                    :grand="$grand ?? []"
                    :view-url="fn ($t) => route('finance.tenancy.show', $t)"
                    :columns="$tenancyColumns"
                    :compact="request()->boolean('compact')"
                    :empty="request('q') ? __('core.empty.no_results') : __('finance::tenancy.none')" />

        <x-ui.pager :rows="$tenancies" />
        <x-ui.list-totals :rows="$tenancies" :grand="$grand ?? []" :columns="$tenancyColumns" />
    </section>
</x-layouts.app>
