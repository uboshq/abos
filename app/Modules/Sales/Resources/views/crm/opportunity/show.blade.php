{{-- একটা সুযোগ — অঙ্ক, ধাপ, পণ্য, আর জিতলে কোটেশনের পথ। --}}
@php
    // ⓘ কলামগুলো এখানে — কম্পোনেন্টের গুণে বহু-লাইনের অ্যারে ট্যাগ ভাঙার ঝুঁকি
    $lineColumns = [
        ['key' => 'product', 'label' => __('sales::crm.product'),
         'render' => fn ($l) => $l->product?->name() ?: '-'],
        ['key' => 'qty', 'label' => __('sales::crm.qty'), 'numeric' => true, 'width' => '9rem',
         'render' => fn ($l) => \App\Core\Support\Money::format($l->qty, 4)],
        ['key' => 'value', 'label' => __('sales::crm.line_value'), 'numeric' => true, 'width' => '11rem',
         'render' => fn ($l) => \App\Core\Support\Money::format($l->value)],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $opportunity->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$opportunity->document_no" :subtitle="$opportunity->title">
            <x-slot:actions>
                @if ($opportunity->sales_quotation_id === null)
                    <x-ui.button tone="secondary" :href="route('sales.opportunity.edit', $opportunity->id)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>
                @endif

                @if ($quotationUrl)
                    <x-ui.button tone="primary" :href="$quotationUrl">
                        {{ __('sales::crm.create_quotation') }}
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.errors />

    <div class="space-y-4">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'sales::crm.party' => $opportunity->partyName() ?: '-',
                    'sales::crm.stage' => $opportunity->stage?->name() ?: '-',
                    'sales::crm.estimated_value' => \App\Core\Support\Money::format($opportunity->estimated_value),
                    'sales::crm.probability' => $opportunity->probability . '%',
                    'sales::crm.weighted_value' => \App\Core\Support\Money::format($opportunity->weightedValue()),
                    'sales::crm.expected_close_date' => \App\Core\Support\DateFormat::format($opportunity->expected_close_date) ?: '-',
                    'sales::crm.salesperson' => $opportunity->salesperson?->name ?: '-',
                    'sales::crm.competitor' => $opportunity->competitor ?: '-',
                    'sales::crm.remarks' => $opportunity->remarks ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                @if ($opportunity->lead)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __('sales::crm.lead') }}</dt>
                        <dd class="mt-0.5">{{ $opportunity->lead->document_no }} — {{ $opportunity->lead->name }}</dd>
                    </div>
                @endif

                @if ($opportunity->sales_quotation_id)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __('sales::crm.quotation') }}</dt>
                        <dd class="mt-0.5">#{{ $opportunity->sales_quotation_id }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('sales::crm.products') }}</h2>

            <x-ui.table :rows="$opportunity->lines" :columns="$lineColumns" :empty="__('sales::crm.lines_required')" />
        </section>
    </div>
</x-layouts.app>
