{{--
    পাইপলাইন — ধাপ ধরে সুযোগ, প্রতিটা ধাপে মোট আর ওজন-করা অঙ্ক।

    ⓘ ওজন-করা অঙ্ক = আনুমানিক অঙ্ক × সম্ভাবনা। "খাতায় কত আসতে পারে"
    প্রশ্নের সৎ উত্তর এটাই — মোটটা আশা, ওজনটা হিসাব।

    ⚠️ প্রতিটা ধাপে কেবল প্রথম কয়েকটা সারি; যোগফল সবগুলোর। বাকিগুলো
    "সব দেখুন" দিয়ে তালিকায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::crm.pipeline') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::crm.pipeline')" :subtitle="__('sales::crm.pipeline_note')">
            <x-slot:actions>
                <x-ui.button tone="secondary" :href="route('sales.opportunity.index')">
                    {{ __('sales::crm.opportunities') }}
                </x-ui.button>
                <x-ui.button tone="primary" icon="plus" :href="route('sales.opportunity.create')">
                    {{ __('sales::crm.new_opportunity') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <p class="text-sm text-(--color-ink-muted)">{{ __('sales::crm.open_weighted') }}</p>
        <p class="mt-1 text-lg font-semibold">{{ \App\Core\Support\Money::format($openWeighted) }}</p>
    </section>

    @if ($board === [])
        <x-ui.empty-state :message="__('sales::crm.no_opportunities')" />
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($board as $column)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h2 class="font-semibold">{{ $column['stage']->name() }}</h2>
                    <span class="text-2xs text-(--color-ink-muted)">{{ $column['count'] }}</span>
                </div>

                <dl class="mb-3 grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">{{ __('sales::crm.estimated_value') }}</dt>
                        <dd>{{ \App\Core\Support\Money::format($column['total']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">{{ __('sales::crm.weighted_value') }}</dt>
                        <dd>{{ \App\Core\Support\Money::format($column['weighted']) }}</dd>
                    </div>
                </dl>

                @foreach ($column['rows'] as $opportunity)
                    <a href="{{ route('sales.opportunity.show', $opportunity->id) }}"
                       class="block border-t border-(--color-border) py-2 text-sm hover:underline">
                        <span class="block">{{ $opportunity->title }}</span>
                        <span class="block text-2xs text-(--color-ink-muted)">
                            {{ $opportunity->partyName() }} · {{ \App\Core\Support\Money::format($opportunity->estimated_value) }}
                            · {{ $opportunity->probability }}%
                        </span>
                    </a>
                @endforeach

                @if ($column['count'] > $column['rows']->count())
                    <a class="mt-2 block text-sm text-(--color-link) hover:underline"
                       href="{{ route('sales.opportunity.index', ['stage' => $column['stage']->id]) }}">
                        {{ __('sales::crm.see_all', ['count' => $column['count']]) }}
                    </a>
                @endif
            </section>
        @endforeach
    </div>
</x-layouts.app>
